<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\Version\VersionParser;
use PhpBaremetal\Spec\Manifest\SdkRequirement;
use PhpBaremetal\Spec\SpecException;

final class Binder
{
    public function bind(Composer $composer, IOInterface $io): void
    {
        $root = $composer->getPackage();
        $sdk = $root->getExtra()['baremetal']['sdk'] ?? null;
        if (!is_string($sdk)) {
            return;
        }
        try {
            $platform = SdkRequirement::parse($sdk)->name;
        } catch (SpecException $e) {
            $io->writeError('<error>php-baremetal: extra.baremetal.' . $e->getMessage() . '</error>');
            return;
        }

        $parser = new VersionParser();
        $any = $parser->parseConstraints('*');
        $repositories = $composer->getRepositoryManager();
        $requires = $root->getRequires();

        $virtuals = [];
        $visited = [];
        $queue = array_keys($requires);
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($visited[$name])) {
                continue;
            }
            $visited[$name] = true;

            if (preg_match('#^php-baremetal/([a-z0-9]+)-transport$#', $name, $m)) {
                $io->writeError("<warning>php-baremetal/{$m[1]}-transport is no longer supported: require php-baremetal/{$m[1]}-hal</warning>");
                continue;
            }
            if (preg_match('#^php-baremetal/([a-z0-9]+-hal)$#', $name, $m)) {
                $virtuals[$m[1]] = true;
                continue;
            }
            if (!str_starts_with($name, 'php-baremetal/') && !isset($requires[$name])) {
                continue;
            }
            $package = $repositories->findPackage($name, $any);
            if ($package === null
                || !(str_starts_with($package->getName(), 'php-baremetal/') || str_starts_with($package->getType(), 'bm-'))) {
                continue;
            }
            foreach ($package->getRequires() as $link) {
                $queue[] = $link->getTarget();
            }
        }

        $added = [];
        foreach (array_keys($virtuals) as $virtual) {
            $concrete = "php-baremetal/{$platform}-{$virtual}";
            if (isset($requires[$concrete])) {
                continue;
            }
            if ($repositories->findPackage($concrete, $any) === null) {
                $io->writeError("<warning>php-baremetal: sdk '{$platform}' has no provider for {$virtual} (need {$concrete})</warning>");
                continue;
            }
            $requires[$concrete] = new Link($root->getName(), $concrete, $any, Link::TYPE_REQUIRE, '*');
            $added[] = $concrete;
        }

        if ($added !== []) {
            $root->setRequires($requires);
            $io->write('<info>php-baremetal: ' . $platform . ' -> ' . implode(', ', $added) . '</info>', true, IOInterface::VERBOSE);
        }
    }
}
