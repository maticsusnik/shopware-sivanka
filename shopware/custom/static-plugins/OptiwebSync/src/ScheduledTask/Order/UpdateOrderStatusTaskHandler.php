<?php declare(strict_types=1);

namespace OptiwebSync\ScheduledTask\Order;

use OptiwebSync\Service\Order\OrderStatusSync;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: UpdateOrderStatusTask::class)]
class UpdateOrderStatusTaskHandler extends ScheduledTaskHandler
{
    private OrderStatusSync $orderStatusSync;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        OrderStatusSync $orderStatusSync
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
        $this->orderStatusSync = $orderStatusSync;
    }

    public function run(): void
    {
        try {
            $this->orderStatusSync->sync(['test' => false]);
        } catch (\Throwable $e) {
            $this->exceptionLogger->error('[UpdateOrderStatusTaskHandler] Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
