<?php declare(strict_types=1);

namespace OptiwebSync\ScheduledTask\Product;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ImportProductStockTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'optiweb.import_product_stock_task';
    }

    public static function getDefaultInterval(): int
    {
        return 900; // 15 minutes
    }
}
