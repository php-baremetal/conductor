<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor;

use Composer\Composer;
use Composer\Package\PackageInterface;
use PhpBaremetal\Spec\Manifest\Manifest;
use PhpBaremetal\Spec\Manifest\Package;
use PhpBaremetal\Spec\Manifest\Project;
use PhpBaremetal\Spec\SpecException;

final class Generator
{
    public static function isBaremetalProject(Composer $composer): bool
    {
        return is_array($composer->getPackage()->getExtra()['baremetal'] ?? null);
    }

    public function build(Composer $composer, string $projectDir): Manifest
    {
        $root = $composer->getPackage();
        $platform = $composer->getConfig()->get('platform');
        $project = Project::fromComposer(
            $root->getName(),
            $root->getExtra()['baremetal'] ?? [],
            is_array($platform) && is_string($platform['php'] ?? null) ? $platform['php'] : null,
        );

        $installer = $composer->getInstallationManager();
        $packages = [];
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {
            if (!str_starts_with($package->getType(), 'bm-')) {
                continue;
            }
            $packages[] = new Package(
                $package->getPrettyName(),
                $package->getType(),
                $package->getPrettyVersion(),
                self::relativePath($projectDir, (string) $installer->getInstallPath($package)),
                $this->meta($package),
            );
        }
        usort($packages, static fn (Package $a, Package $b) => strcmp($a->name, $b->name));

        return new Manifest($project, $packages);
    }

    private function meta(PackageInterface $package): array
    {
        $meta = $package->getExtra()['baremetal'] ?? [];
        if (!is_array($meta) || ($meta !== [] && array_is_list($meta))) {
            throw new SpecException("{$package->getPrettyName()}: extra.baremetal must be an object");
        }

        return $meta;
    }

    public static function relativePath(string $projectDir, string $path): string
    {
        $base = rtrim(str_replace('\\', '/', (string) (realpath($projectDir) ?: $projectDir)), '/') . '/';
        $full = str_replace('\\', '/', (string) (realpath($path) ?: $path));
        return str_starts_with($full, $base) ? substr($full, strlen($base)) : $full;
    }
}
