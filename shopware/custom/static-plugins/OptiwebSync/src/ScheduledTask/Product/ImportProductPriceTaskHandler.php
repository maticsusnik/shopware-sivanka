<?php declare(strict_types=1);

namespace OptiwebSync\ScheduledTask\Product;

use OptiwebSync\Service\Product\ProductPriceSync;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: ImportProductPriceTask::class)]
class ImportProductPriceTaskHandler extends ScheduledTaskHandler
{
    private ProductPriceSync $productPriceSync;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        ProductPriceSync $productPriceSync
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
        $this->productPriceSync = $productPriceSync;
    }

    public function run(): void
    {
        try {
            $this->productPriceSync->sync(['test' => false]);
        } catch (\Throwable $e) {
            $this->exceptionLogger->error('[ImportProductPriceTaskHandler] Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
