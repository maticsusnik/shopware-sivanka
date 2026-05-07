<?php declare(strict_types=1);

namespace OptiwebSync\ScheduledTask\Product;

use OptiwebSync\Service\Category\CategorySync;
use OptiwebSync\Service\Manufacturer\ManufacturerSync;
use OptiwebSync\Service\Product\ProductSync;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: ImportProductsTask::class)]
class ImportProductsTaskHandler extends ScheduledTaskHandler
{
    private ProductSync $productSync;
    private CategorySync $categorySync;
    private ManufacturerSync $manufacturerSync;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        ProductSync $productSync,
        CategorySync $categorySync,
        ManufacturerSync $manufacturerSync
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
        $this->productSync = $productSync;
        $this->categorySync = $categorySync;
        $this->manufacturerSync = $manufacturerSync;
    }

    public function run(): void
    {
        try {
            $this->categorySync->sync(['test' => false]);
            $this->manufacturerSync->sync(['test' => false]);
            $this->productSync->sync(['test' => false]);
        } catch (\Throwable $e) {
            $this->exceptionLogger->error('[ImportProductsTaskHandler] Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
