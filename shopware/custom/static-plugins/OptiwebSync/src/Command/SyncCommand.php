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
    )
    {
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
            ->setDescription('Sync defined (or all if empty) endpoints in specified order.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $arguments = $input->getArgument('selectedSync');
        $options = $input->getOptions();
        if ($options['listAvailable'] || empty($arguments)) {
            $this->listAvailableSyncs($output);
            return Command::SUCCESS;
        }

        $output->writeln(["[".date("d.m.Y H:i:s", time()) . '] Starting sync.']);
        $this->executeSync($arguments, $options);
        $output->writeln(["[".date("d.m.Y H:i:s", time()) . '] Sync END.']);

        return Command::SUCCESS;
    }

    private function executeSync(?string $syncClasses, array $options): void
    {
        $selected = array_map('trim', explode(',', strtolower($syncClasses)));
        /** @var SyncBaseInterface $sync */
        foreach ($this->syncServices as $sync) {
            $validNames = $sync->getSyncCommandNames();
            if (empty($syncClasses) || !empty(array_intersect($selected, $validNames))) {
                $sync->sync($options);
            }
        }
    }

    private function listAvailableSyncs($output): void
    {
        $output->writeln('');
        $output->writeln('<info>Available syncs:</info>');
        $output->writeln('--------------------');

        $maxLen = max(array_map(fn($s) => strlen($s->getName()), iterator_to_array($this->syncServices)));

        foreach ($this->syncServices as $sync) {
            $name = str_pad($sync->getName(), $maxLen);
            $output->writeln(sprintf(
                ' <fg=green>•</> <comment>%s</comment> <fg=gray>(%s)</>',
                $name,
                implode(', ', $sync->getSyncCommandNames())
            ));
        }

        $output->writeln('');
    }

}
