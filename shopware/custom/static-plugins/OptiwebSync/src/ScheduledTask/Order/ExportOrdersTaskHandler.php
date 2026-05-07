<?php declare(strict_types=1);

namespace OptiwebSync\ScheduledTask\Order;

use OptiwebSync\Service\Order\OrderExportSync;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: ExportOrdersTask::class)]
class ExportOrdersTaskHandler extends ScheduledTaskHandler
{
    private OrderExportSync $exportOrdersSync;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        OrderExportSync $exportOrdersSync
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
        $this->exportOrdersSync = $exportOrdersSync;
    }

    public function run(): void
    {
        try {
            $this->exportOrdersSync->sync(['test' => false]);
        } catch (\Throwable $e) {
            $this->exceptionLogger->error('[ExportOrdersTaskHandler] Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
