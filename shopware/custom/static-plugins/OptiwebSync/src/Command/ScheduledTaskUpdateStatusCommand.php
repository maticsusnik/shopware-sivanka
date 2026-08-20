<?php declare(strict_types=1);

namespace OptiwebSync\Command;

use Doctrine\DBAL\Connection;
use OptiwebSync\ScheduledTask\Order\ExportOrdersTask;
use OptiwebSync\ScheduledTask\Product\ImportProductsTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'optiweb:update-scheduled-task-status',
    description: "Requeues this plugin's scheduled tasks that were left in 'running', and cleans up stale lock files."
)]
class ScheduledTaskUpdateStatusCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'all',
            null,
            InputOption::VALUE_NONE,
            "Requeue every task stuck in 'running', not just this plugin's (use with care — a task that really is running would then run twice)"
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $affected = (bool) $input->getOption('all')
            ? $this->requeueAll()
            : $this->requeueOwnTasks();

        $output->writeln("Updated $affected task(s) from 'running' to 'scheduled'.");

        $this->cleanupLockFiles($output);

        return Command::SUCCESS;
    }

    /**
     * Only this plugin's tasks: resetting every 'running' task in the table
     * would also requeue core tasks that are legitimately mid-run, making them
     * execute twice.
     */
    private function requeueOwnTasks(): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE scheduled_task SET status = :newStatus WHERE status = :currentStatus AND name IN (:names)',
            [
                'newStatus'     => 'scheduled',
                'currentStatus' => 'running',
                'names'         => [ExportOrdersTask::getTaskName(), ImportProductsTask::getTaskName()],
            ],
            ['names' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function requeueAll(): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE scheduled_task SET status = :newStatus WHERE status = :currentStatus',
            ['newStatus' => 'scheduled', 'currentStatus' => 'running'],
        );
    }

    private function cleanupLockFiles(OutputInterface $output): int
    {
        $cleanedCount = 0;

        foreach ($this->getLockDirectories() as $lockDir) {
            $lockFiles = glob($lockDir . '/*.lock');
            if ($lockFiles === false) {
                continue;
            }

            foreach ($lockFiles as $lockFile) {
                if (!$this->isLockFileStale($lockFile)) {
                    continue;
                }

                if (@unlink($lockFile)) {
                    ++$cleanedCount;
                    $output->writeln('Removed stale lock file: ' . basename($lockFile));
                }
            }
        }

        if ($cleanedCount > 0) {
            $output->writeln("Cleaned up $cleanedCount stale lock file(s).");
        }

        return $cleanedCount;
    }

    private function isLockFileStale(string $lockFile): bool
    {
        if (!file_exists($lockFile)) {
            return false;
        }

        $lockData = @file_get_contents($lockFile);
        if ($lockData === false) {
            // Empty or unreadable file, consider it stale
            return true;
        }

        $data = @json_decode($lockData, true);
        if (!is_array($data) || !isset($data['timestamp'], $data['ttl'])) {
            // Invalid lock file format, consider it stale
            return true;
        }

        if (time() - $data['timestamp'] <= $data['ttl']) {
            return false;
        }

        // Older than its TTL — only stale if the owning process is gone.
        $pid = $data['pid'] ?? null;
        if ($pid !== null && function_exists('posix_kill') && posix_kill((int) $pid, 0)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function getLockDirectories(): array
    {
        $lockDir = $_ENV['LOCK_FOLDER'] ?? $_SERVER['LOCK_FOLDER'] ?? getenv('LOCK_FOLDER');

        if (empty($lockDir)) {
            $lockDir = dirname(__DIR__, 4) . '/../../var/optiweb-sync-locks';
        }

        return is_dir($lockDir) ? [$lockDir] : [];
    }
}
