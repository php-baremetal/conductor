<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor;

use Composer\Command\BaseCommand;
use PhpBaremetal\Spec\SpecException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InfoCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('conductor:info')
            ->setDescription('Show the resolved php-baremetal project and its bm-* packages');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $manifest = (new Generator())->build($this->requireComposer(), Plugin::projectDir());
        } catch (SpecException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        $project = $manifest->project;
        foreach ([
            'sdk' => (string) $project->sdk,
            'board' => $project->board,
            'runtime' => $project->runtime,
            'storage' => $project->storage,
            'php' => $project->php,
        ] as $label => $value) {
            $output->writeln(sprintf('%-8s %s', $label, $value));
        }
        $output->writeln('');
        foreach ($manifest->packages as $package) {
            $output->writeln(sprintf('%-8s %s %s', $package->kind(), $package->name, $package->version));
        }

        return 0;
    }
}
