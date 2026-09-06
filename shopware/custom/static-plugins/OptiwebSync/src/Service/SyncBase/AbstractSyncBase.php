<?php declare(strict_types=1);

namespace OptiwebSync\Service\SyncBase;

use Doctrine\DBAL\Connection;
use Monolog\Logger;
use OptiwebSync\Helper\GlobalVariables;
use OptiwebSync\Helper\OwLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

abstract class AbstractSyncBase implements SyncBaseInterface
{
    protected SystemConfigService $systemConfigService;
    protected Logger $logger;
    protected bool $ignoreHash = false;
    protected bool $ignoreMedia = false;
    protected bool $dryRun = false;
    protected bool $testMode = false;
    protected ?string $setId = null;
    private ?string $lockFile = null;
    private $lockHandle = null;

    public function __construct(
        SystemConfigService $systemConfigService,
        protected Connection $connection,
    ) {
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * Build this sync's logger. Called before initialize() so that even a failed
     * initialisation — or a skipped run — has somewhere to report itself.
     */
    abstract protected function createLogger(): Logger;

    public function sync(array $options): void
    {
        $this->logger      = $this->createLogger();
        $this->ignoreHash  = (bool) ($options['ignoreHash'] ?? false);
        $this->ignoreMedia = (bool) ($options['ignoreMedia'] ?? false);
        $this->dryRun      = (bool) ($options['dryRun'] ?? false);
        $this->testMode    = (bool) ($options['test'] ?? false);
        $this->setId       = !empty($options['setId']) ? (string) $options['setId'] : null;

        $lockTtl      = $this->getLockTtl();
        $lockAcquired = false;

        if ($lockTtl > 0) {
            $lockName = $this->getLockName();
            if (!$this->acquireLock($lockName, $lockTtl)) {
                OwLogger::addVisibleLog(
                    $this->logger,
                    $this->getName() . ' sync SKIPPED: another sync is already running. Lock: ' . $lockName
                );
                OwLogger::finishLogger($this->logger);

                return;
            }
            $lockAcquired = true;
        }

        try {
            try {
                $this->initialize();
            } catch (Throwable $e) {
                OwLogger::exception($this->logger, $this->getName() . ' initialization failed', $e);
                error_log('[OptiwebSync] ' . $this->getName() . ' initialization failed: ' . $e->getMessage());

                throw $e;
            }

            OwLogger::addVisibleLog($this->logger, $this->getName() . ' sync START.' . ($this->dryRun ? ' (DRY RUN)' : ''));

            $pageSize    = $this->testMode ? 10 : GlobalVariables::BATCH_SIZE;
            $loopThrough = $this->loopThrough();
            $syncType    = $this->getSyncType();

            foreach ($loopThrough as $key => $value) {
                $logValue = is_array($value) ? ($value['anQid'] ?? $value['name'] ?? '') : $value;
                OwLogger::addVisibleLog($this->logger, $this->getName() . ": sync loop $logValue.");

                $loopData = ['key' => $key, 'value' => $value];

                if ($syncType === 'import') {
                    $this->runImport($pageSize, $loopData);
                }

                if ($syncType === 'export') {
                    $this->runExport($loopData);
                }
            }

            $this->finalize();
            OwLogger::addVisibleLog($this->logger, $this->getName() . ' sync END.');
            OwLogger::finishLogger($this->logger);
        } finally {
            if ($lockAcquired) {
                $this->releaseLock();
            }
        }
    }

    /**
     * Page through the source and import each page.
     *
     * Whether another page exists is decided by hasMorePages(), not by comparing
     * the row count to the page size: an import that filters rows out on the way
     * in would otherwise stop at the first page that contained a skipped row.
     */
    private function runImport(int $pageSize, array $loopData): void
    {
        $page = 1;

        while (true) {
            try {
                $data       = $this->fetchPage($page, $pageSize, $loopData);
                $dataLength = count($data);

                OwLogger::addVisibleLog($this->logger, $this->getName() . ": page $page, $dataLength rows.");

                if ($dataLength > GlobalVariables::BATCH_SIZE) {
                    $chunks     = array_chunk($data, GlobalVariables::BATCH_SIZE);
                    $chunkCount = count($chunks);
                    OwLogger::addVisibleLog($this->logger, "Importing $dataLength items in $chunkCount chunks...");

                    foreach ($chunks as $i => $chunk) {
                        $countInserted = $this->import($chunk, $loopData);
                        OwLogger::addVisibleLog($this->logger, sprintf('%d items synced, chunk %d/%d.', $countInserted, $i + 1, $chunkCount));
                    }
                } elseif ($dataLength > 0) {
                    $countInserted = $this->import($data, $loopData);
                    OwLogger::addVisibleLog($this->logger, "$countInserted synced.");
                }
            } catch (Throwable $error) {
                // Includes the fetch itself: a single failed page must not abort
                // the whole run, but we also must not loop on it forever.
                OwLogger::exception($this->logger, $this->getName() . " sync error on page $page", $error);

                return;
            }

            // --test means "show me a sample", so it stops after one page.
            // Without this it merely made the pages smaller and still walked the
            // entire source — slower than a normal run, not faster.
            if ($this->testMode) {
                OwLogger::addVisibleLog($this->logger, $this->getName() . ': TEST MODE — stopping after the first page.');

                return;
            }

            if (!$this->hasMorePages($page, $dataLength, $pageSize)) {
                return;
            }

            ++$page;
        }
    }

    private function runExport(array $loopData): void
    {
        try {
            OwLogger::addVisibleLog($this->logger, $this->getName() . ' exporting data...');
            $data          = $this->setExportData();
            $countInserted = $this->export($data, $loopData);
            OwLogger::addVisibleLog($this->logger, "$countInserted synced.");
        } catch (Throwable $error) {
            OwLogger::exception($this->logger, $this->getName() . ' export error', $error);
        }
    }

    /**
     * Whether the source has another page after this one.
     *
     * The default is the usual "a full page probably means there is more"
     * heuristic; override it whenever the source reports a real total.
     */
    protected function hasMorePages(int $page, int $fetchedRows, int $pageSize): bool
    {
        return $fetchedRows >= $pageSize;
    }

    /**
     * Get lock TTL in seconds
     * Return 0 to skip locking for this sync
     * Override this method in child classes to set custom TTL
     *
     * @return int Lock TTL in seconds, 0 to disable locking
     */
    protected function getLockTtl(): int
    {
        return 0; // Default: no locking
    }

    /**
     * Get lock name based on sync name
     */
    private function getLockName(): string
    {
        $syncName = strtolower(str_replace(' ', '-', $this->getName()));

        return 'optiweb-' . $syncName . '-sync';
    }

    /**
     * Acquire file-based lock
     * Returns true if lock acquired, false if already locked
     */
    private function acquireLock(string $lockName, int $lockTtl): bool
    {
        $lockDir = $this->getLockDirectory();
        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            throw new \RuntimeException("Cannot create lock directory: $lockDir");
        }

        $this->lockFile = $lockDir . '/' . $lockName . '.lock';

        // Clean up stale locks (older than TTL)
        $this->cleanupStaleLock();

        $this->lockHandle = @fopen($this->lockFile, 'c+');
        if ($this->lockHandle === false) {
            $this->lockHandle = null;

            return false;
        }

        // Try to acquire exclusive lock (non-blocking)
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;

            return false;
        }

        // Write PID and timestamp to lock file
        ftruncate($this->lockHandle, 0);
        fwrite($this->lockHandle, (string) json_encode([
            'pid' => getmypid(),
            'timestamp' => time(),
            'ttl' => $lockTtl,
        ]));
        fflush($this->lockHandle);

        return true;
    }

    /**
     * Release file-based lock
     */
    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }

        if ($this->lockFile !== null && file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }

        $this->lockFile = null;
    }

    /**
     * Clean up stale lock files (older than TTL)
     */
    private function cleanupStaleLock(): void
    {
        if ($this->lockFile === null || !file_exists($this->lockFile)) {
            return;
        }

        $lockData = @file_get_contents($this->lockFile);
        if ($lockData === false) {
            return;
        }

        $data = @json_decode($lockData, true);
        if (!is_array($data) || !isset($data['timestamp']) || !isset($data['ttl'])) {
            // Invalid lock file, try to remove it
            @unlink($this->lockFile);

            return;
        }

        $lockAge = time() - $data['timestamp'];
        if ($lockAge <= $data['ttl']) {
            return;
        }

        // Lock is older than the TTL — only reclaim it if the owning process died.
        $pid = $data['pid'] ?? null;
        if ($pid !== null) {
            $isRunning = function_exists('posix_kill') ? posix_kill((int) $pid, 0) : false;
            if (!$isRunning) {
                @unlink($this->lockFile);
            }

            return;
        }

        @unlink($this->lockFile);
    }

    /**
     * Get lock directory path
     */
    private function getLockDirectory(): string
    {
        // Use LOCK_FOLDER environment variable, fallback to default
        $lockDir = $_ENV['LOCK_FOLDER'] ?? $_SERVER['LOCK_FOLDER'] ?? getenv('LOCK_FOLDER');

        if (empty($lockDir)) {
            // Fallback to default path
            $baseDir = dirname(__DIR__, 4);
            $lockDir = $baseDir . '/../../var/optiweb-sync-locks';
        }

        return $lockDir;
    }

    protected function initialize(): void
    {
    }

    protected function loopThrough(): array
    {
        return ['1' => '1'];
    }

    protected function fetchPage(int $page, int $pageSize, array $loopData): array
    {
        return [];
    }

    protected function import(array $dataArray, array $loopData): int
    {
        return 0;
    }

    protected function export(array $dataArray, array $loopData): int
    {
        return 0;
    }

    protected function setExportData(): array
    {
        return [];
    }

    protected function finalize(): void
    {
    }
}
