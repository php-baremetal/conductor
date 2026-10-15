<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor;

use Composer\Command\BaseCommand;
use PhpBaremetal\Spec\Manifest\Manifest;
use PhpBaremetal\Spec\Manifest\ManifestValidator;
use PhpBaremetal\Spec\SpecException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GenerateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('conductor:generate')
            ->setDescription('Write ' . Manifest::PATH . ' from the installed bm-* packages and extra.baremetal')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the manifest instead of writing it')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail when the package graph has problems');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $projectDir = Plugin::projectDir();

        try {
            $manifest = (new Generator())->build($composer, $projectDir);
        } catch (SpecException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        $problems = (new ManifestValidator())->validate($manifest);
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        foreach ($problems as $problem) {
            $errors->writeln('<comment>! ' . $problem . '</comment>');
        }

        if ($input->getOption('dry-run')) {
            $output->write($manifest->toJson());
        } else {
            (new ManifestWriter())->write($projectDir, $manifest);
            $output->writeln('<info>wrote ' . Manifest::PATH . '</info>');
        }

        return $problems !== [] && $input->getOption('strict') ? 1 : 0;
    }
}
