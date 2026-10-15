<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** `composer conductor:generate` -- rebuild baremetal.gen.json (--dry-run, --strict). */
final class GenerateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('conductor:generate')
            ->setDescription('Generate baremetal.gen.json from installed bm-* packages and the project config')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the manifest instead of writing it')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit non-zero if the graph has problems');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root     = getcwd() ?: '.';
        $gen      = new Generator($this->requireComposer());
        $manifest = $gen->build($root);
        $problems = $gen->validate($manifest);

        foreach ($problems as $problem) {
            $output->writeln('<comment>! ' . $problem . '</comment>');
        }

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if ($input->getOption('dry-run')) {
            $output->write($json);
        } else {
            file_put_contents($root . '/' . Generator::MANIFEST, $json);
            $output->writeln('<info>wrote ' . Generator::MANIFEST . '</info>');
        }

        return ($problems !== [] && $input->getOption('strict')) ? 1 : 0;
    }
}
