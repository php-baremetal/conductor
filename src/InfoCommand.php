<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** `composer conductor:info` -- readable summary of target, transports and drivers. */
final class InfoCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('conductor:info')
            ->setDescription('Show the resolved php-baremetal build: target, transports, drivers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root     = getcwd() ?: '.';
        $gen      = new Generator($this->requireComposer());
        $manifest = $gen->build($root);
        $p        = $manifest['project'];

        $storage = $manifest['storage'] ?? ['type' => '?'];
        $sdesc   = $storage['type'] ?? '?';
        if (($storage['type'] ?? null) === 'microsd' && isset($storage['microsd'])) {
            $sd    = $storage['microsd'];
            $pins  = [];
            foreach (['cs', 'sclk', 'mosi', 'miso'] as $pin) {
                if (isset($sd[$pin])) {
                    $pins[] = $pin . '=' . $sd[$pin];
                }
            }
            $sdesc = sprintf('microsd (%s%s)', $sd['mode'] ?? '?', $pins ? ' ' . implode(' ', $pins) : '');
        }

        $output->writeln('<info>Project</info>');
        $output->writeln(sprintf('  chip=%s  psram=%s  php=%s', $p['chip'] ?? '?', $p['psram'] ?? '?', $manifest['php-version'] ?? '?'));
        $output->writeln(sprintf('  preset=%s  type=%s', $p['preset'] ?? '?', $p['type'] ?? '?'));
        $output->writeln(sprintf('  storage=%s', $sdesc));

        foreach ($manifest['buses'] as $bus => $b) {
            $t = $b['transport'];
            $output->writeln('');
            $output->writeln(sprintf('<info>Bus %s</info>', $bus));
            $output->writeln($t === null
                ? '  transport: <error>none</error>'
                : sprintf('  transport: %s (%s)', $t['arch'] ?? '?', $t['package']));
            foreach ($b['drivers'] as $d) {
                $output->writeln(sprintf('  driver: %-12s class=%-14s %s', $d['driver'] ?? '?', $d['php-class'] ?? '?', $d['capability'] ?? ''));
            }
        }

        $output->writeln('');
        $output->writeln('<info>idf REQUIRES</info>: ' . implode(' ', $manifest['idf-requires']));
        foreach ($manifest['driver-names'] as $bus => $names) {
            $output->writeln(sprintf('PHP_%s_DRIVERS=%s', strtoupper($bus), implode(',', $names)));
        }

        return 0;
    }
}
