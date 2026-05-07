<?php declare(strict_types=1);

namespace OptiwebSync\Service\SyncBase;

use Doctrine\DBAL\Connection;
use Exception;
use Monolog\Logger;
use OptiwebSync\Helper\ApiHelper;
use OptiwebSync\Helper\EnvHelper;
use OptiwebSync\Helper\ShopwareApiHelper;
use OptiwebSync\Helper\GlobalVariables;
use OptiwebSync\Helper\OwLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

abstract class AbstractSyncBase implements SyncBaseInterface
{
    protected ShopwareApiHelper $shopwareApiHelper;
    protected SystemConfigService $systemConfigService;
    protected Logger $logger;
    protected bool $ignoreHash;
    protected bool $ignoreMedia;
    protected ?string $setId;
    private ?string $lockFile = null;
    private $lockHandle = null;

    public function __construct(
        SystemConfigService $systemConfigService,
        ShopwareApiHelper $shopwareApiHelper,
        protected Connection $connection,
        protected ApiHelper $apiHelper
    )
    {
        $this->systemConfigService = $systemConfigService;
        $this->shopwareApiHelper = $shopwareApiHelper;
        $this->connection = $connection;
        $this->apiHelper = $apiHelper;
    }

    public function sync(array $options): void
    {
        $lockTtl = $this->getLockTtl();
        $lockAcquired = false;

        if ($lockTtl > 0) {
            $lockName = $this->getLockName();
            if (!$this->acquireLock($lockName, $lockTtl)) {
                $this->initialize();
                OwLogger::addVisibleLog(
                    $this->logger,
                    $this->getName() . ' sync SKIPPED: Another sync is already running. Lock: ' . $lockName
                );
                OwLogger::finishLogger($this->logger);
                return;
            }
            $lockAcquired = true;
        }

        try {
            try {
                $this->initialize();
            } catch (\Throwable $e) {
                error_log('[OptiwebSync] ' . $this->getName() . ' initialization failed: ' . $e->getMessage());
                throw $e;
            }

            OwLogger::addVisibleLog($this->logger, $this->getName() . ' sync START.');

            $getRows = $options['test'] ? 10 : GlobalVariables::BATCH_SIZE;
            $this->ignoreHash = array_key_exists('ignoreHash', $options) && (bool)$options['ignoreHash'];
            $this->ignoreMedia = array_key_exists('ignoreMedia', $options) && (bool)$options['ignoreMedia'];
            $this->setId = array_key_exists('setId', $options) && !empty($options['setId']) ? $options['setId'] : null;
            $loopThrough = $this->loopThrough();
            $syncType = $this->getSyncType();
            $data = [];

            foreach ($loopThrough as $key => $value) {

                $logValue = $value;
                if (is_array($logValue)) {
                    $logValue = $value['anQid'] ?? $value['name'] ?? '';
                }
                OwLogger::addVisibleLog($this->logger, $this->getName() . ": sync loop $logValue.");

                if ($syncType === 'import') {

                    $count = 1;
                    $getMoreData = true;

                    while ($getMoreData) {
                        $params = [
                            'page' => $count,
                            'limit' => $getRows,
                        ];
                        $apiData = [];
                        if ($this->getSyncOrigin() == 'vasco') {
                            $params = $this->addApiParameters([], ["key" => $key, "value" => $value]);
                            $params['Segment'] = $count;
                            $params['SegmentSize'] = $getRows;
                            $apiUrl = $this->apiHelper->addUrlParameters(EnvHelper::read("VASCO_URL", self::class) . $this->getSyncEndpoint(), $params);
                        } else {
                            $params = $this->addApiParameters($params, ["key" => $key, "value" => $value]);
                            $apiUrl = $this->apiHelper->addUrlParameters(EnvHelper::read("PIM_SYNC_API_URL", self::class) . $this->getSyncEndpoint(), $params);
                        }
                        $apiData = $this->apiHelper->callApi($apiUrl, $this->getSyncArrayKey(), $this->getSyncOrigin());

                        try {

                            if (isset($apiData['error'])) {
                                OwLogger::addVisibleLog($this->logger, $apiData['error']);
                                throw new Exception($apiData['error']);
                            }

                            OwLogger::addVisibleLog($this->logger, $this->getName() . ": " . $count . " rows from $count.");

                            $data = $apiData['response'] ?? [];

                            $dataLength = count($data);
                            if ($dataLength > GlobalVariables::BATCH_SIZE) {
                                OwLogger::addVisibleLog($this->logger, "Importing $dataLength items...");

                                $dataChunk = array_chunk($data, GlobalVariables::BATCH_SIZE);
                                $dataChunkLength = count($dataChunk);
                                $i = 1;
                                foreach ($dataChunk as $chunk) {
                                    $countInserted = $this->import($chunk, ["key" => $key, "value" => $value]);
                                    OwLogger::addVisibleLog($this->logger, "$countInserted items synced, chunk $i/$dataChunkLength.");
                                    $i++;
                                }
                            } else {
                                $countInserted = $this->import($data, ["key" => $key, "value" => $value]);
                                OwLogger::addVisibleLog($this->logger, "$countInserted synced.");
                            }

                        } catch (Throwable $error) {
                            OwLogger::error($this->logger, $this->getName() . ' sync error', ['error' => $error->getMessage()]);
                        }

                        if (count($data) == GlobalVariables::BATCH_SIZE) {
                            $count++;
                        } else {
                            $getMoreData = false;
                        }

                    }

                }

                if ($syncType === 'export') {
                    $data = $this->setExportData();
                    try {
                        OwLogger::addVisibleLog($this->logger, $this->getName() . " Exporting data...");
                        $countInserted = $this->export($data, ["key" => $key, "value" => $value]);
                        OwLogger::addVisibleLog($this->logger, "$countInserted synced.");
                    } catch (Throwable $error) {
                        OwLogger::error($this->logger, $this->getName() . ' export error', ['error' => $error->getMessage()]);
                    }
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
        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true)) {
            throw new \RuntimeException("Cannot create lock directory: $lockDir");
        }

        $this->lockFile = $lockDir . '/' . $lockName . '.lock';
        
        // Clean up stale locks (older than TTL)
        $this->cleanupStaleLock();

        // Try to acquire exclusive lock (non-blocking)
        $this->lockHandle = @fopen($this->lockFile, 'c+');
        if ($this->lockHandle === false) {
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
        fwrite($this->lockHandle, json_encode([
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
        if ($lockAge > $data['ttl']) {
            // Lock is stale, check if process is still running
            $pid = $data['pid'] ?? null;
            if ($pid !== null) {
                // Check if process is still running
                $isRunning = function_exists('posix_kill') ? posix_kill((int)$pid, 0) : false;
                if (!$isRunning) {
                    // Process is dead, remove stale lock
                    @unlink($this->lockFile);
                }
            } else {
                // No PID, remove if stale
                @unlink($this->lockFile);
            }
        }
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
        return ["1" => "1"];
    }

    protected function addApiParameters(array $params, array $loopData): array
    {
        return $params;
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
