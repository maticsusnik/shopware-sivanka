<?php declare(strict_types=1);

namespace OptiwebSync\Helper;

use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Throwable;

/**
 * File logging for the syncs.
 *
 * Layout: {LOG_FOLDER}/{sync-folder}/{Y-m-d}/{name}[_addon]_{His}.log
 *
 * One directory per day so a day's runs stay together and a day's worth can be
 * dropped in one go, which is what retention does: date directories older than
 * RETENTION_DAYS are deleted when a logger is created.
 */
class OwLogger
{
    /** Date directories older than this many days are deleted on the next run. */
    private const RETENTION_DAYS = 14;

    private static bool $cliMode = true;

    /**
     * @throws Exception
     */
    public static function generate(string $loggerName = '', string $folder = '', bool $loggerFileNameWithTime = false, string $customNameAddon = ''): Logger
    {
        self::$cliMode = (PHP_SAPI === 'cli');

        $loggerNameFinal = $loggerName !== '' ? $loggerName : 'log';
        $baseDir         = rtrim(EnvHelper::read('LOG_FOLDER', self::class), '/') . '/'
            . ($folder !== '' ? trim($folder, '/') . '/' : '');

        self::prune($baseDir);

        $logDir = $baseDir . date('Y-m-d') . '/';

        if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            throw new Exception("Cannot create log directory: $logDir");
        }

        // One file per run keeps concurrent runs from interleaving; without the
        // time suffix a day's runs all append to the same file.
        $suffix  = $loggerFileNameWithTime ? date('His') : 'day';
        $logFile = $logDir . $loggerNameFinal . $customNameAddon . '_' . $suffix . '.log';

        $logger = new Logger('OptiwebSync');
        $logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));

        $logger->info('Sync started', ['service' => $loggerName, 'file' => $logFile]);

        if (self::$cliMode) {
            echo '[' . date('d.m.Y H:i:s') . '] Logging to ' . $logFile . "\n";
        }

        return $logger;
    }

    public static function finishLogger(Logger $logger): void
    {
        $logger->info('Sync finished');
    }

    public static function addVisibleLog(Logger $logger, string $message, array $context = []): void
    {
        $logger->info($message, $context);
        self::echoLine('', $message, $context);
    }

    public static function addLog(Logger $logger, string $message, array $context = []): void
    {
        $logger->info($message, $context);
    }

    public static function warning(Logger $logger, string $message, array $context = []): void
    {
        $logger->warning($message, $context);
        self::echoLine('WARNING: ', $message, $context);
    }

    public static function error(Logger $logger, string $message, array $context = []): void
    {
        $logger->error($message, $context);
        self::echoLine('ERROR: ', $message, $context);
    }

    /**
     * Log an error together with everything known about the exception behind it.
     *
     * "ProductSync failed on page 3" on its own is not actionable — the class,
     * message and origin of the throwable are what make it one.
     */
    public static function exception(Logger $logger, string $message, Throwable $e, array $context = []): void
    {
        self::error($logger, $message, $context + self::describe($e));
    }

    /**
     * Flatten a throwable (and its causes) into loggable context.
     *
     * @return array<string, mixed>
     */
    public static function describe(Throwable $e): array
    {
        $context = [
            'error' => $e->getMessage(),
            'type'  => $e::class,
            'at'    => basename($e->getFile()) . ':' . $e->getLine(),
        ];

        $causes = [];
        for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            $causes[] = $previous::class . ': ' . $previous->getMessage();
        }

        if ($causes !== []) {
            $context['causedBy'] = implode(' <- ', $causes);
        }

        return $context;
    }

    /**
     * Echo a line on CLI, context included.
     *
     * The context is where the reason lives — an error whose message says what
     * failed but not why is the same as no error at all.
     */
    private static function echoLine(string $prefix, string $message, array $context): void
    {
        if (!self::$cliMode) {
            return;
        }

        $suffix = '';
        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (is_string($encoded) && $encoded !== '[]' && $encoded !== '{}') {
                $suffix = ' ' . $encoded;
            }
        }

        echo '[' . date('d.m.Y H:i:s') . '] ' . $prefix . $message . $suffix . "\n";
    }

    /**
     * Delete date directories older than the retention window.
     *
     * Only YYYY-MM-DD directories are considered, so nothing else that happens
     * to live under the log folder is ever touched.
     */
    private static function prune(string $baseDir): void
    {
        if (!is_dir($baseDir)) {
            return;
        }

        $cutoff = strtotime('-' . self::RETENTION_DAYS . ' days midnight');
        if ($cutoff === false) {
            return;
        }

        foreach ((array) scandir($baseDir) as $entry) {
            if (!is_string($entry) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry) !== 1) {
                continue;
            }

            $day = strtotime($entry . ' midnight');
            if ($day === false || $day >= $cutoff) {
                continue;
            }

            $dir = $baseDir . $entry;
            if (!is_dir($dir)) {
                continue;
            }

            foreach ((array) glob($dir . '/*.log') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }

            @rmdir($dir);
        }
    }
}
