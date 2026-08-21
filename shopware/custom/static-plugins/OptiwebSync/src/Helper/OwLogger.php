<?php declare(strict_types=1);

namespace OptiwebSync\Helper;

use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class OwLogger
{
    private static bool $cliMode = true;

    /**
     * @throws Exception
     */
    public static function generate(string $loggerName = '', string $folder = '', bool $loggerFileNameWithTime = false, string $customNameAddon = ''): Logger
    {
        self::$cliMode = (PHP_SAPI === 'cli');

        $dateString = $loggerFileNameWithTime ? date('Ymd_His') : date('Ymd');
        $folder = !empty($folder) ? $folder . '/' : '';
        $loggerNameFinal = !empty($loggerName) ? $loggerName : 'log';
        $logDir = EnvHelper::read('LOG_FOLDER', self::class) . $folder;

        if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            throw new Exception("Cannot create log directory: $logDir");
        }

        $logFile = $logDir . $loggerNameFinal . $customNameAddon . '_' . $dateString . '.log';

        $logger = new Logger('OptiwebSync');
        $logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));

        $logger->info('Sync started', ['service' => $loggerName]);
        return $logger;
    }

    public static function finishLogger(Logger $logger): void
    {
        $logger->info('Sync finished');
    }

    public static function addVisibleLog(Logger $logger, string $message, array $context = []): void
    {
        $logger->info($message, $context);
        if (self::$cliMode) {
            echo '[' . date('d.m.Y H:i:s') . '] ' . $message . "\n";
        }
    }

    public static function addLog(Logger $logger, string $message, array $context = []): void
    {
        $logger->info($message, $context);
    }

    public static function warning(Logger $logger, string $message, array $context = []): void
    {
        $logger->warning($message, $context);
        if (self::$cliMode) {
            echo '[' . date('d.m.Y H:i:s') . '] WARNING: ' . $message . "\n";
        }
    }

    public static function error(Logger $logger, string $message, array $context = []): void
    {
        $logger->error($message, $context);
        if (self::$cliMode) {
            echo '[' . date('d.m.Y H:i:s') . '] ERROR: ' . $message . "\n";
        }
    }
}
