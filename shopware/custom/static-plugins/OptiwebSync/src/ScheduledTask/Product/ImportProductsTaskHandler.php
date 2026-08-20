<?php declare(strict_types=1);

namespace OptiwebSync\ScheduledTask\Product;

use OptiwebSync\Service\Product\ProductSync;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: ImportProductsTask::class)]
class ImportProductsTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ProductSync $productSync,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        try {
            $this->productSync->sync(['test' => false]);
        } catch (\Throwable $e) {
            $this->exceptionLogger->error('[ImportProductsTaskHandler] Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
