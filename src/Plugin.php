<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/** Composer plugin: regenerates baremetal.gen.json on install/update, exposes conductor:* commands. */
final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io       = $io;
        $this->bindPlatformTransports($composer->getPackage(), $io);
    }

    /**
     * Map virtual <x>-transport / <x>-hal to concrete php-baremetal/<platform>-<x> before solving.
     * Does not fire on first install if the plugin is not yet installed.
     */
    private function bindPlatformTransports(RootPackageInterface $root, IOInterface $io): void
    {
        $platform = $root->getExtra()['baremetal']['platform'] ?? null;
        if (!is_string($platform) || $platform === '') {
            return;
        }

        $parser   = new VersionParser();
        $any      = $parser->parseConstraints('*');
        $repoMgr  = $this->composer->getRepositoryManager();
        $requires = $root->getRequires();

        // Collect transitively required virtuals (php-baremetal packages only).
        $virtuals = [];   // "<x>-hal" / "<x>-transport" => true
        $visited  = [];
        $queue    = array_keys($requires);
        while ($queue) {
            $name = array_shift($queue);
            if (isset($visited[$name])) {
                continue;
            }
            $visited[$name] = true;

            if (preg_match('#^php-baremetal/([a-z0-9]+-(?:transport|hal))$#', $name, $m)) {
                $virtuals[$m[1]] = true;   // a virtual: no package to recurse into
                continue;
            }
            if (strncmp($name, 'php-baremetal/', 14) !== 0) {
                continue;                   // leave the wider ecosystem alone (and offline)
            }
            $pkg = $repoMgr->findPackage($name, $any);
            if (!$pkg) {
                continue;
            }
            foreach ($pkg->getRequires() as $link) {
                $queue[] = $link->getTarget();
            }
        }

        // Inject the concrete provider for each unrequired virtual; warn if missing.
        $added   = [];
        $missing = [];
        foreach (array_keys($virtuals) as $virtual) {
            $concrete = sprintf('php-baremetal/%s-%s', $platform, $virtual);
            if (isset($requires[$concrete])) {
                continue;
            }
            if (!$repoMgr->findPackage($concrete, $any)) {
                $missing[] = $virtual;
                continue;
            }
            $requires[$concrete] = new Link(
                $root->getName(),
                $concrete,
                $parser->parseConstraints('*'),
                Link::TYPE_REQUIRE,
                '*'
            );
            $added[] = $concrete;
        }

        if ($added !== []) {
            $root->setRequires($requires);
            $io->write('<info>php-baremetal: platform=' . $platform . ' -> ' . implode(', ', $added) . '</info>', true, IOInterface::VERBOSE);
        }
        foreach ($missing as $virtual) {
            $io->writeError('<warning>php-baremetal: platform "' . $platform . '" has no provider for '
                . $virtual . ' (need php-baremetal/' . $platform . '-' . $virtual . ')</warning>');
        }
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
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallUpdate',
            ScriptEvents::POST_UPDATE_CMD  => 'onPostInstallUpdate',
        ];
    }

    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }

    public function onPostInstallUpdate(Event $event): void
    {
        $root     = getcwd() ?: '.';
        $gen      = new Generator($this->composer);
        $manifest = $gen->build($root);

        foreach ($gen->validate($manifest) as $problem) {
            $this->io->writeError('<warning>php-baremetal: ' . $problem . '</warning>');
        }

        $path = $root . '/' . Generator::MANIFEST;
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->io->write('<info>php-baremetal: wrote ' . Generator::MANIFEST . '</info>');
    }
}
