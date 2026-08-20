<?php declare(strict_types=1);

namespace OptiwebSync\Command;

use OptiwebSync\Service\SyncBase\SyncBaseInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'optiweb:sync',
    description: 'Sync defined (or all if empty) endpoints in specified order.',
)]
class SyncCommand extends Command
{
    /**
     * @param iterable<SyncBaseInterface> $syncServices
     */
    public function __construct(
        private readonly iterable $syncServices
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('selectedSync', InputArgument::OPTIONAL, 'Selected sync(s) - can be separated with comma')
            ->addOption('test', 't', InputOption::VALUE_NONE, 'Only process 10 items for each sync')
            ->addOption('listAvailable', 'l', InputOption::VALUE_NONE, 'List all available sync options')
            ->addOption('ignoreHash', 'i', InputOption::VALUE_NONE, 'Ignore hashed values')
            ->addOption('ignoreMedia', 'm', InputOption::VALUE_NONE, 'Ignore media')
            ->addOption('setId', 's', InputOption::VALUE_REQUIRED, 'Limit sync to specific ID/SKU')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Resolve and log everything but write nothing')
            ->setDescription('Sync defined (or all if empty) endpoints in specified order.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selectedSync = $input->getArgument('selectedSync');

        if ($input->getOption('listAvailable') || empty($selectedSync)) {
            $this->listAvailableSyncs($output);

            return Command::SUCCESS;
        }

        $options = [
            'test'        => (bool) $input->getOption('test'),
            'ignoreHash'  => (bool) $input->getOption('ignoreHash'),
            'ignoreMedia' => (bool) $input->getOption('ignoreMedia'),
            'dryRun'      => (bool) $input->getOption('dry-run'),
            'setId'       => $input->getOption('setId'),
        ];

        $output->writeln('[' . date('d.m.Y H:i:s') . '] Starting sync.' . ($options['dryRun'] ? ' (dry run)' : ''));
        $this->executeSync((string) $selectedSync, $options);
        $output->writeln('[' . date('d.m.Y H:i:s') . '] Sync END.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function executeSync(string $syncClasses, array $options): void
    {
        $selected = array_map('trim', explode(',', strtolower($syncClasses)));

        foreach ($this->syncServices as $sync) {
            if (array_intersect($selected, $sync->getSyncCommandNames()) !== []) {
                $sync->sync($options);
            }
        }
    }

    private function listAvailableSyncs(OutputInterface $output): void
    {
        $syncs = iterator_to_array($this->syncServices);

        $output->writeln('');
        $output->writeln('<info>Available syncs:</info>');
        $output->writeln('--------------------');

        $maxLen = $syncs === [] ? 0 : max(array_map(static fn (SyncBaseInterface $s): int => strlen($s->getName()), $syncs));

        foreach ($syncs as $sync) {
            $output->writeln(sprintf(
                ' <fg=green>•</> <comment>%s</comment> <fg=gray>(%s)</>',
                str_pad($sync->getName(), $maxLen),
                implode(', ', $sync->getSyncCommandNames())
            ));
        }

        $output->writeln('');
    }
}
