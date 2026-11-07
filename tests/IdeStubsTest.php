<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor\Tests;

use PhpBaremetal\Conductor\IdeStubs;
use PhpBaremetal\Spec\Manifest\Manifest;
use PhpBaremetal\Spec\Manifest\Package;
use PhpBaremetal\Spec\Manifest\Project;
use PHPUnit\Framework\TestCase;

final class IdeStubsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ide-stubs-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->dir);
    }

    public function testWritesReadmeAndOneFilePerPackage(): void
    {
        $manifest = new Manifest($this->project(), [
            $this->package('php-baremetal/ext-wifi', 'vendor/php-baremetal/ext-wifi', ['ide/ext-wifi.stub' => "<?php\n// wifi\n"]),
            $this->package('php-baremetal/ext-led', '.phpbm/packages/php-baremetal/ext-led', ['ide/ext-led.stub' => "<?php\n// led\n"]),
        ]);

        self::assertSame([], (new IdeStubs())->write($this->dir, $manifest));

        $out = $this->dir . '/' . IdeStubs::DIR;
        self::assertFileExists($out . '/README.md');
        self::assertStringContainsString('Do not edit; do not load at runtime.', (string) file_get_contents($out . '/README.md'));
        self::assertSame("<?php\n// wifi\n", file_get_contents($out . '/ext-wifi.php'));
        self::assertSame("<?php\n// led\n", file_get_contents($out . '/ext-led.php'));
    }

    public function testSecondStubOfAPackageGetsASuffix(): void
    {
        $manifest = new Manifest($this->project(), [
            $this->package('php-baremetal/ext-a', 'vendor/php-baremetal/ext-a', ['ide/a.stub' => 'A', 'ide/b.stub' => 'B']),
        ]);
        (new IdeStubs())->write($this->dir, $manifest);

        $out = $this->dir . '/' . IdeStubs::DIR;
        self::assertSame('A', file_get_contents($out . '/ext-a.php'));
        self::assertSame('B', file_get_contents($out . '/ext-a.2.php'));
    }

    public function testUnsafeEntriesAreSkippedWithAWarning(): void
    {
        file_put_contents($this->dir . '/secret.stub', 'S');
        $pkg = new Package('php-baremetal/ext-x', 'bm-ext', '1.0.0', 'vendor/php-baremetal/ext-x', ['ide-stubs' => ['../../../secret.stub', '/etc/passwd.stub', 'ide/x.txt']]);
        $ide = new IdeStubs();
        $warnings = $ide->write($this->dir, new Manifest($this->project(), [$pkg]));

        self::assertCount(3, $warnings);
        self::assertSame("php-baremetal: php-baremetal/ext-x: ide stub '../../../secret.stub' is not a relative .stub path, skipped", $warnings[0]);
        self::assertSame(['README.md'], $this->listing());
        self::assertSame(0, $ide->written());
    }

    public function testFilesystemFailureIsAWarningNotAnException(): void
    {
        mkdir($this->dir . '/vendor/php-baremetal', 0777, true);
        file_put_contents($this->dir . '/' . IdeStubs::DIR, 'plain file');
        $ide = new IdeStubs();
        // like Composer's handler: honours the @ operator, turns the rest into exceptions
        set_error_handler(static function (int $no, string $msg): bool {
            if (!(error_reporting() & $no)) {
                return true;
            }
            throw new \ErrorException($msg, 0, $no);
        });
        try {
            $warnings = $ide->write($this->dir, new Manifest($this->project(), []));
        } finally {
            restore_error_handler();
        }

        self::assertSame(['php-baremetal: cannot create ' . IdeStubs::DIR], $warnings);
        self::assertSame(0, $ide->written());
    }

    public function testRebuildRemovesFilesOfPackagesThatAreGone(): void
    {
        $a = $this->package('php-baremetal/ext-a', 'vendor/php-baremetal/ext-a', ['ide/a.stub' => 'A']);
        $b = $this->package('php-baremetal/ext-b', 'vendor/php-baremetal/ext-b', ['ide/b.stub' => 'B']);
        (new IdeStubs())->write($this->dir, new Manifest($this->project(), [$a, $b]));
        (new IdeStubs())->write($this->dir, new Manifest($this->project(), [$a]));

        $out = $this->dir . '/' . IdeStubs::DIR;
        self::assertFileExists($out . '/ext-a.php');
        self::assertFileDoesNotExist($out . '/ext-b.php');
    }

    public function testMissingStubIsAWarningAndWritesNothing(): void
    {
        $pkg = new Package('php-baremetal/ext-x', 'bm-ext', '1.0.0', 'vendor/php-baremetal/ext-x', ['ide-stubs' => ['ide/x.stub']]);
        $warnings = (new IdeStubs())->write($this->dir, new Manifest($this->project(), [$pkg]));

        self::assertSame(["php-baremetal: php-baremetal/ext-x: ide stub 'ide/x.stub' not found"], $warnings);
        self::assertSame(['README.md'], $this->listing());
    }

    public function testNoPackagesWithStubsLeavesOnlyTheReadme(): void
    {
        $pkg = new Package('php-baremetal/ext-x', 'bm-ext', '1.0.0', 'vendor/php-baremetal/ext-x', []);
        (new IdeStubs())->write($this->dir, new Manifest($this->project(), [$pkg]));

        self::assertSame(['README.md'], $this->listing());
    }

    public function testOtherVendorsKeepTheFullNameWithDashes(): void
    {
        $manifest = new Manifest($this->project(), [
            $this->package('acme/ext-foo', 'vendor/acme/ext-foo', ['ide/foo.stub' => 'F']),
        ]);
        (new IdeStubs())->write($this->dir, $manifest);

        self::assertSame(['README.md', 'acme--ext-foo.php'], $this->listing());
    }

    public function testLeavesOtherFilesUnderVendorPhpBaremetalAlone(): void
    {
        mkdir($this->dir . '/vendor/php-baremetal/spec', 0777, true);
        file_put_contents($this->dir . '/vendor/php-baremetal/spec/keep.php', 'k');
        mkdir($this->dir . '/' . IdeStubs::DIR, 0777, true);
        file_put_contents($this->dir . '/' . IdeStubs::DIR . '/notes.txt', 'mine');

        (new IdeStubs())->write($this->dir, new Manifest($this->project(), []));

        self::assertFileExists($this->dir . '/vendor/php-baremetal/spec/keep.php');
        self::assertFileExists($this->dir . '/' . IdeStubs::DIR . '/notes.txt');
    }

    /** @param array<string, string> $stubs relative stub path => content */
    private function package(string $name, string $path, array $stubs): Package
    {
        foreach ($stubs as $rel => $content) {
            $file = $this->dir . '/' . $path . '/' . $rel;
            is_dir(dirname($file)) || mkdir(dirname($file), 0777, true);
            file_put_contents($file, $content);
        }

        return new Package($name, 'bm-ext', '1.0.0', $path, ['ide-stubs' => array_keys($stubs)]);
    }

    private function project(): Project
    {
        return Project::fromComposer('acme/app', ['sdk' => 'esp32@^1.0', 'board' => 'demo-board', 'runtime' => 'init-loop', 'storage' => 'embedded'], '8.4.25');
    }

    /** @return list<string> */
    private function listing(): array
    {
        $files = array_values(array_diff(scandir($this->dir . '/' . IdeStubs::DIR) ?: [], ['.', '..']));
        sort($files);

        return $files;
    }

    private function rm(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $e) {
                $this->rm($path . '/' . $e);
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
