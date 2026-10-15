<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor\Tests;

use PhpBaremetal\Spec\Manifest\Manifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ConductorTest extends TestCase
{
    private static string $work;
    private static string $home;

    public static function setUpBeforeClass(): void
    {
        self::$work = sys_get_temp_dir() . '/conductor-' . bin2hex(random_bytes(4));
        self::$home = self::$work . '/home';
        mkdir(self::$home, 0777, true);

        $root = dirname(__DIR__);
        file_put_contents(self::$home . '/composer.json', json_encode([
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'path', 'url' => $root, 'options' => ['versions' => ['php-baremetal/conductor' => '1.0.0']]],
                ['type' => 'path', 'url' => $root . '/vendor/php-baremetal/spec', 'options' => ['versions' => ['php-baremetal/spec' => '1.0.0']]],
            ],
            'require' => ['php-baremetal/conductor' => '^1.0'],
            'config' => ['allow-plugins' => ['php-baremetal/conductor' => true]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        self::composer(self::$home, ['install']);
    }

    /** @return array{int,string} */
    private static function composer(string $dir, array $args, bool $stdoutOnly = false): array
    {
        $process = new Process(['composer', ...$args, '--no-interaction', '--no-ansi'], $dir, ['COMPOSER_HOME' => self::$home]);
        $process->setTimeout(300);
        $process->run();
        return [$process->getExitCode() ?? 1, $stdoutOnly ? $process->getOutput() : $process->getOutput() . $process->getErrorOutput()];
    }

    private function project(array $require, ?array $baremetal): string
    {
        $dir = self::$work . '/project-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $fixtures = __DIR__ . '/fixtures/packages';
        $repositories = [['packagist.org' => false]];
        foreach (glob($fixtures . '/*', GLOB_ONLYDIR) as $package) {
            $repositories[] = ['type' => 'path', 'url' => $package, 'options' => ['symlink' => false]];
        }
        $composer = [
            'name' => 'acme/demo-firmware',
            'repositories' => $repositories,
            'require' => $require,
            'config' => ['platform' => ['php' => '8.4.25']],
        ];
        if ($baremetal !== null) {
            $composer['extra'] = ['baremetal' => $baremetal];
        }
        file_put_contents($dir . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $dir;
    }

    private const BAREMETAL = [
        'sdk' => 'esp32@^1.0',
        'board' => 'demo-board',
        'runtime' => 'init-loop',
        'storage' => 'embedded',
    ];

    public function test_binds_installs_and_writes_the_manifest(): void
    {
        $dir = $this->project(['php-baremetal/ext-demo' => '^1.0', 'acme/plain-lib' => '^1.0'], self::BAREMETAL);

        [$code, $out] = self::composer($dir, ['install']);
        self::assertSame(0, $code, $out);

        self::assertDirectoryExists($dir . '/.phpbm/packages/php-baremetal/ext-demo');
        self::assertDirectoryExists($dir . '/.phpbm/packages/php-baremetal/esp32-demo-hal');
        self::assertDirectoryDoesNotExist($dir . '/.phpbm/packages/php-baremetal/stm32-demo-hal');
        self::assertDirectoryExists($dir . '/vendor/acme/plain-lib');
        self::assertDirectoryDoesNotExist($dir . '/vendor/php-baremetal/ext-demo');

        $manifest = Manifest::fromFile($dir . '/' . Manifest::PATH);
        self::assertSame('demo-firmware', $manifest->project->name);
        self::assertSame('esp32', $manifest->project->sdk->name);
        self::assertSame('8.4.25', $manifest->project->php);
        self::assertSame(
            ['php-baremetal/demo-lib', 'php-baremetal/esp32-demo-hal', 'php-baremetal/ext-demo'],
            array_map(static fn ($p) => $p->name, $manifest->packages),
        );
        self::assertSame('.phpbm/packages/php-baremetal/ext-demo', $manifest->packages[2]->path);
        self::assertSame('demo', $manifest->packages[2]->meta['module']);
    }

    public function test_ignores_projects_without_baremetal_config(): void
    {
        $dir = $this->project(['acme/plain-lib' => '^1.0'], null);

        [$code, $out] = self::composer($dir, ['install']);
        self::assertSame(0, $code, $out);
        self::assertDirectoryDoesNotExist($dir . '/.phpbm');
    }

    public function test_invalid_config_reports_and_removes_stale_manifest(): void
    {
        $config = self::BAREMETAL;
        unset($config['board']);
        $dir = $this->project(['php-baremetal/ext-demo' => '^1.0'], $config);
        mkdir($dir . '/.phpbm');
        file_put_contents($dir . '/' . Manifest::PATH, '{}');

        [$code, $out] = self::composer($dir, ['install']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString("extra.baremetal: 'board' must be a non-empty string", $out);
        self::assertFileDoesNotExist($dir . '/' . Manifest::PATH);
    }

    public function test_generate_dry_run_prints_the_manifest(): void
    {
        $dir = $this->project(['php-baremetal/ext-demo' => '^1.0'], self::BAREMETAL);
        self::composer($dir, ['install']);

        [$code, $out] = self::composer($dir, ['conductor:generate', '--dry-run']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('"generated-by": "php-baremetal/conductor"', $out);
    }

    public function test_dry_run_keeps_graph_problems_out_of_the_json(): void
    {
        $dir = $this->project(['php-baremetal/ext-demo' => '^1.0', 'acme/ext-dup' => '^1.0'], self::BAREMETAL);
        self::composer($dir, ['install']);

        [$code, $out] = self::composer($dir, ['conductor:generate', '--dry-run'], true);
        self::assertSame(0, $code, $out);
        self::assertIsArray(json_decode($out, true), $out);
        [, $all] = self::composer($dir, ['conductor:generate', '--dry-run']);
        self::assertStringContainsString("extension 'demo' provided by both", $all);
    }

    public function test_info_summarises_the_project(): void
    {
        $dir = $this->project(['php-baremetal/ext-demo' => '^1.0'], self::BAREMETAL);
        self::composer($dir, ['install']);

        [$code, $out] = self::composer($dir, ['conductor:info']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('sdk      esp32@^1.0', $out);
        self::assertStringContainsString('ext      php-baremetal/ext-demo', $out);
    }

    public function test_binds_hal_for_local_extensions_outside_the_php_baremetal_vendor(): void
    {
        $dir = $this->project(['acme/ext-local' => '^1.0'], self::BAREMETAL);

        [$code, $out] = self::composer($dir, ['install']);
        self::assertSame(0, $code, $out);
        self::assertDirectoryExists($dir . '/.phpbm/packages/php-baremetal/esp32-demo-hal');
        $manifest = Manifest::fromFile($dir . '/' . Manifest::PATH);
        self::assertContains('acme/ext-local', array_map(static fn ($p) => $p->name, $manifest->packages));
    }

    public function test_a_transport_virtual_is_not_bound_and_warns(): void
    {
        $dir = $this->project(['php-baremetal/ext-demo' => '^1.0', 'php-baremetal/demo-transport' => '^1.0'], self::BAREMETAL);

        [$code, $out] = self::composer($dir, ['update', '--no-install']);
        self::assertStringContainsString('php-baremetal/demo-transport is no longer supported: require php-baremetal/demo-hal', $out);
    }

    public function test_invalid_package_metadata_is_reported_and_writes_no_manifest(): void
    {
        $dir = $this->project(['acme/bad-meta' => '^1.0'], self::BAREMETAL);

        [$code, $out] = self::composer($dir, ['install']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('acme/bad-meta: extra.baremetal must be an object', $out);
        self::assertFileDoesNotExist($dir . '/' . Manifest::PATH);
    }

    public function test_projects_without_baremetal_config_install_normally_even_with_bm_packages(): void
    {
        $dir = $this->project(['php-baremetal/ext-demo' => '^1.0', 'php-baremetal/esp32-demo-hal' => '^1.0'], null);

        [$code, $out] = self::composer($dir, ['install']);
        self::assertSame(0, $code, $out);
        self::assertDirectoryExists($dir . '/vendor/php-baremetal/ext-demo');
        self::assertDirectoryDoesNotExist($dir . '/.phpbm');
    }

    public function test_installs_php_extension_packages_with_the_other_bm_packages(): void
    {
        $dir = $this->project(['php-baremetal/php-demo' => '^1.0'], self::BAREMETAL);

        [$code, $out] = self::composer($dir, ['install']);
        self::assertSame(0, $code, $out);
        self::assertDirectoryExists($dir . '/.phpbm/packages/php-baremetal/php-demo');
        $manifest = Manifest::fromFile($dir . '/' . Manifest::PATH);
        $package = $manifest->ofKind('php-ext')[0];
        self::assertSame('php-baremetal/php-demo', $package->name);
        self::assertSame('bm-php-ext', $package->type);
        self::assertSame(['php-ext' => 'demo_ext'], $package->meta);
    }

    public function test_reports_a_php_extension_package_with_other_keys(): void
    {
        $dir = $this->project(['acme/php-bad' => '^1.0'], self::BAREMETAL);

        [$code, $out] = self::composer($dir, ['install']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString("acme/php-bad: type bm-php-ext allows only 'php-ext' in extra.baremetal, got 'sources'", $out);
    }

    public function test_warns_when_a_hal_declares_another_platform_than_the_sdk(): void
    {
        $dir = $this->project(['php-baremetal/esp32-wrong-hal' => '^1.0'], self::BAREMETAL);

        [$code, $out] = self::composer($dir, ['install']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString("php-baremetal/esp32-wrong-hal: 'platform' is 'stm32' but the project's sdk is 'esp32'", $out);
    }
}
