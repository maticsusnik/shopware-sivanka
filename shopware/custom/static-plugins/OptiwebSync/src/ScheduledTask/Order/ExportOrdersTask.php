<?php declare(strict_types=1);

namespace OptiwebSync\ScheduledTask\Order;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ExportOrdersTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'optiweb.export_orders_task';
    }

    public static function getDefaultInterval(): int
    {
        return 300; // 5 minutes
    }
}
