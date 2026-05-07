<?php declare(strict_types=1);

namespace OptiwebSync\ScheduledTask\Product;

use OptiwebSync\Service\Product\ProductStockSync;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: ImportProductStockTask::class)]
class ImportProductStockTaskHandler extends ScheduledTaskHandler
{
    private ProductStockSync $productStockSync;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        ProductStockSync $productStockSync
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
        $this->productStockSync = $productStockSync;
    }

    public function run(): void
    {
        try {
            $this->productStockSync->sync(['test' => false]);
        } catch (\Throwable $e) {
            $this->exceptionLogger->error('[ImportProductStockTaskHandler] Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
