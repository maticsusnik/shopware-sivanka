<?php declare(strict_types=1);

namespace OptiwebSync\ScheduledTask\Order;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class UpdateOrderStatusTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'optiweb.update_order_status_task';
    }

    public static function getDefaultInterval(): int
    {
        return 900; // 15 minutes
    }
}
