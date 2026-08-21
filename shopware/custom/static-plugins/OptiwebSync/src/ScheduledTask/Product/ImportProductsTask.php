<?php declare(strict_types=1);

namespace OptiwebSync\ScheduledTask\Product;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ImportProductsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'optiweb.import_products_task';
    }

    public static function getDefaultInterval(): int
    {
        return 1200; // 20min
        // return 3600; // 60 minutes
    }
}
