<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use PhpBaremetal\Spec\Manifest\Manifest;
use PhpBaremetal\Spec\Manifest\ManifestValidator;
use PhpBaremetal\Spec\SpecException;

final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        if (Generator::isBaremetalProject($composer)) {
            $composer->getInstallationManager()->addInstaller(new Installer($io, $composer, self::projectDir()));
        }
        (new Binder())->bind($composer, $io);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'writeManifest',
            ScriptEvents::POST_UPDATE_CMD => 'writeManifest',
        ];
    }

    public function getCapabilities(): array
    {
        return [CommandProviderCapability::class => CommandProvider::class];
    }

    public function writeManifest(): void
    {
        if (!Generator::isBaremetalProject($this->composer)) {
            return;
        }
        $projectDir = self::projectDir();
        $writer = new ManifestWriter();

        try {
            $manifest = (new Generator())->build($this->composer, $projectDir);
        } catch (SpecException $e) {
            $writer->remove($projectDir);
            $this->io->writeError('<error>php-baremetal: ' . $e->getMessage() . '</error>');
            return;
        }

        foreach ((new ManifestValidator())->validate($manifest) as $problem) {
            $this->io->writeError('<warning>php-baremetal: ' . $problem . '</warning>');
        }
        $writer->write($projectDir, $manifest);
        $this->io->write('<info>php-baremetal: wrote ' . Manifest::PATH . '</info>');
    }

    public static function projectDir(): string
    {
        return getcwd() ?: '.';
    }
}
