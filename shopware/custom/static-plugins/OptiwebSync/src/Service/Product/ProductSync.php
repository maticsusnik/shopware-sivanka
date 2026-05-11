<?php declare(strict_types=1);

namespace OptiwebSync\Service\Product;

use Doctrine\DBAL\Connection;
use OptiwebSync\Client\MinimaxClient;
use OptiwebSync\Client\ShopwareClient;
use OptiwebSync\Helper\GlobalVariables;
use OptiwebSync\Helper\OwLogger;
use OptiwebSync\Helper\ShopwareApiHelper;
use OptiwebSync\Service\SyncBase\AbstractSyncBase;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductSync extends AbstractSyncBase
{
    private string $taxId        = '';
    private string $currencyId   = '';
    private string $salesChannelId = '';

    /** @var array<string, string> productNumber → Shopware product ID */
    private array $productNumberMap = [];

    public function __construct(
        SystemConfigService $systemConfigService,
        ShopwareApiHelper $shopwareApiHelper,
        Connection $connection,
        private readonly MinimaxClient $minimaxClient,
        private readonly ShopwareClient $shopwareClient,
    ) {
        parent::__construct($systemConfigService, $shopwareApiHelper, $connection);
    }

    // -------------------------------------------------------------------------
    // SyncBaseInterface
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return 'Product';
    }

    public function getSyncType(): string
    {
        return 'import';
    }

    public function getSyncOrigin(): string
    {
        return 'minimax';
    }

    public function getSyncCommandNames(): array
    {
        return ['product', 'products'];
    }

    protected function getLockTtl(): int
    {
        return 7200;
    }

    // -------------------------------------------------------------------------
    // Sync lifecycle
    // -------------------------------------------------------------------------

    protected function initialize(): void
    {
        $this->logger = OwLogger::generate('ProductImportSync', 'product-import-sync', true);

        $this->taxId          = $this->shopwareClient->getDefaultTaxId();
        $this->currencyId     = $this->shopwareClient->getDefaultCurrencyId();
        $this->salesChannelId = $this->shopwareClient->getDefaultSalesChannelId();
        $this->productNumberMap = $this->buildProductMap();

        OwLogger::addVisibleLog($this->logger, sprintf(
            'ProductSync initialized. Tax: %s | Currency: %s | SalesChannel: %s | Existing products: %d',
            $this->taxId,
            $this->currencyId,
            $this->salesChannelId,
            count($this->productNumberMap),
        ));
    }

    protected function fetchPage(int $page, int $pageSize, array $loopData): array
    {
        return $this->minimaxClient->getProductsPage($page, $pageSize);
    }

    protected function import(array $dataArray, array $loopData): int
    {
        $products = $dataArray;
        OwLogger::addVisibleLog($this->logger, 'Processing ' . count($products) . ' products from Minimax.');

        $creates = [];
        $updates = [];

        foreach ($products as $product) {
            $sku   = trim($product['sku'] ?? '');
            $name  = trim($product['name'] ?? '');
            $price = (float) ($product['price'] ?? 0.0);
            $stock = max(0, (int) ($product['stock'] ?? 0));

            if ($sku === '' || $name === '') {
                continue;
            }

            $priceEntry = [[
                'currencyId' => $this->currencyId,
                'gross'      => $price,
                'net'        => round($price / GlobalVariables::PRICE_GROSS_MULTIPLIER, 6),
                'linked'     => true,
            ]];

                    if (isset($this->productNumberMap[$sku])) {
                $updates[] = [
                    'id'    => $this->productNumberMap[$sku]['id'],
                    'price' => $priceEntry,
                    'stock' => $stock,
                ];
            }

        }

        $this->flushBulk('create', $creates);
        $this->flushBulk('upsert', $updates);

        OwLogger::addVisibleLog($this->logger, sprintf(
            'ProductSync: %d created, %d updated.',
            count($creates),
            count($updates),
        ));

        return count($creates) + count($updates);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string, array{id: string, stock: int, propertyIds: array, optionIds: array, categoryIds: array, parentId: string|null}> productNumber → data */
    private function buildProductMap(): array
    {
        $sql = <<<SQL
            SELECT
                LOWER(HEX(id))        AS id,
                product_number,
                stock
            FROM product
            WHERE product_number IS NOT NULL AND product_number != ''
        SQL;

        $map = [];

        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            $map[$row['product_number']] = [
                'id'          => $row['id'],
                'stock'       => (int) $row['stock']
            ];
        }

        return $map;
    }

    /** @param array<int, array<string, mixed>> $payload */
    private function flushBulk(string $action, array $payload): void
    {
        if (empty($payload)) {
            return;
        }

        $this->shopwareClient->bulkAddData('product-' . $action, 'product', $action, $payload);
        $this->shopwareClient->bulkDataProcessQueue(true);
    }
}
