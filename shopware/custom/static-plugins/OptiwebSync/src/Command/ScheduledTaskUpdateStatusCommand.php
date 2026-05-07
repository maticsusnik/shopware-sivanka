<?php declare(strict_types=1);

namespace OptiwebSync\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'optiweb:update-scheduled-task-status',
    description: 'Executes a query and changes a task status from running to scheduled. Also cleans up stale lock files.'
)]
class ScheduledTaskUpdateStatusCommand extends Command
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $affected = $this->connection->executeStatement(
            'UPDATE scheduled_task SET status = :newStatus WHERE status = :currentStatus',
            [
                'newStatus'     => 'scheduled',
                'currentStatus' => 'running',
            ]
        );

        $output->writeln("Updated $affected task(s) from 'running' to 'scheduled'.");

        // Clean up stale lock files
        $cleaned = $this->cleanupLockFiles($output);

        return Command::SUCCESS;
    }

    /**
     * Clean up stale lock files
     */
    private function cleanupLockFiles(OutputInterface $output): int
    {
        $lockDirs = $this->getLockDirectories();
        $cleanedCount = 0;

        foreach ($lockDirs as $lockDir) {
            if (!is_dir($lockDir)) {
                continue;
            }

            $lockFiles = glob($lockDir . '/*.lock');
            if ($lockFiles === false) {
                continue;
            }

            foreach ($lockFiles as $lockFile) {
                if (!$this->isLockFileStale($lockFile)) {
                    continue;
                }

                // Try to remove stale lock file
                if (@unlink($lockFile)) {
                    $cleanedCount++;
                    $output->writeln("Removed stale lock file: " . basename($lockFile));
                }
            }
        }

        if ($cleanedCount > 0) {
            $output->writeln("Cleaned up $cleanedCount stale lock file(s).");
        }

        return $cleanedCount;
    }

    /**
     * Check if lock file is stale
     */
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
        if (!is_array($data) || !isset($data['timestamp']) || !isset($data['ttl'])) {
            // Invalid lock file format, consider it stale
            return true;
        }

        $lockAge = time() - $data['timestamp'];
        if ($lockAge <= $data['ttl']) {
            // Lock is not stale yet
            return false;
        }

        // Lock is older than TTL, check if process is still running
        $pid = $data['pid'] ?? null;
        if ($pid !== null) {
            // Check if process is still running
            $isRunning = function_exists('posix_kill') ? posix_kill((int)$pid, 0) : false;
            if ($isRunning) {
                // Process is still running, don't remove
                return false;
            }
        }

        // Lock is stale (older than TTL and process is dead or no PID)
        return true;
    }

    /**
     * Get all possible lock directories
     */
    private function getLockDirectories(): array
    {
        $dirs = [];

        // Use LOCK_FOLDER environment variable, fallback to default
        $lockDir = $_ENV['LOCK_FOLDER'] ?? $_SERVER['LOCK_FOLDER'] ?? getenv('LOCK_FOLDER');
        
        if (empty($lockDir)) {
            // Fallback to default path
            $pluginBaseDir = dirname(__DIR__, 4);
            $lockDir = $pluginBaseDir . '/../../var/optiweb-sync-locks';
        }

        if (is_dir($lockDir)) {
            $dirs[] = $lockDir;
        }

        return $dirs;
    }
}
