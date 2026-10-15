<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

final class Installer extends LibraryInstaller
{
    public function __construct(IOInterface $io, Composer $composer, private readonly string $projectDir)
    {
        parent::__construct($io, $composer);
    }

    public function supports(string $packageType): bool
    {
        return str_starts_with($packageType, 'bm-');
    }

    public function getInstallPath(PackageInterface $package): string
    {
        return $this->projectDir . '/.phpbm/packages/' . $package->getPrettyName();
    }
}
