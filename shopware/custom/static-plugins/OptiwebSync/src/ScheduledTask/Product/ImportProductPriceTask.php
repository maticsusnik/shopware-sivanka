<?php declare(strict_types=1);

namespace OptiwebSync\ScheduledTask\Product;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ImportProductPriceTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'optiweb.import_product_price_task';
    }

    public static function getDefaultInterval(): int
    {
        return 1800; // 30 minutes
    }
}
