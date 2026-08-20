<?php declare(strict_types=1);

namespace OptiwebSync\Service\Product;

use Doctrine\DBAL\Connection;
use Monolog\Logger;
use OptiwebSync\Client\MinimaxClient;
use OptiwebSync\Client\ShopwareClient;
use OptiwebSync\Helper\OwLogger;
use OptiwebSync\Service\SyncBase\AbstractSyncBase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Imports price, stock and VAT rate from Minimax into Shopware.
 *
 * Products that exist in Minimax but not in Shopware are created **inactive**:
 * the sync owns number/name/price/stock/tax, an editor owns everything else,
 * including whether the product goes live.
 */
class ProductSync extends AbstractSyncBase
{
    /** Price differences below this are treated as "unchanged". */
    private const PRICE_EPSILON = 0.0001;

    private string $defaultTaxId   = '';
    private float  $defaultTaxRate = 0.0;
    private string $currencyId     = '';

    /** Whether Minimax item prices already include VAT. */
    private bool $priceIncludesVat = false;

    /** @var array<string, array{id: string, stock: int, taxId: string, gross: ?float, net: ?float}> productNumber → current Shopware state */
    private array $productMap = [];

    /** @var array<string, string> tax rate (e.g. "22.00") → Shopware tax ID */
    private array $taxRateMap = [];

    /** Set by fetchPage() from the Minimax paging metadata. */
    private bool $sourceHasMorePages = false;

    private int $created = 0;
    private int $updated = 0;
    private int $unchanged = 0;
    private int $skipped = 0;

    /** Products whose Minimax VAT rate could not be resolved. */
    private int $defaultTaxFallbacks = 0;

    /** @var list<string> A sample of the SKUs above, for the summary log. */
    private array $defaultTaxFallbackSkus = [];

    public function __construct(
        SystemConfigService $systemConfigService,
        Connection $connection,
        private readonly MinimaxClient $minimaxClient,
        private readonly ShopwareClient $shopwareClient,
    ) {
        parent::__construct($systemConfigService, $connection);
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

    protected function createLogger(): Logger
    {
        return OwLogger::generate('ProductImportSync', 'product-import-sync', true);
    }

    // -------------------------------------------------------------------------
    // Sync lifecycle
    // -------------------------------------------------------------------------

    protected function initialize(): void
    {
        $this->priceIncludesVat = (bool) $this->systemConfigService->get('OptiwebSync.config.minimaxItemPriceIncludesVat');

        $this->loadDefaultTax();
        $this->currencyId = $this->resolveCurrencyId();
        $this->taxRateMap = $this->buildTaxRateMap();
        $this->productMap = $this->buildProductMap();

        if ($this->defaultTaxId === '' || $this->currencyId === '') {
            throw new \RuntimeException('ProductSync: could not resolve the default tax or currency.');
        }

        OwLogger::addVisibleLog($this->logger, sprintf(
            'ProductSync initialized. Default tax: %s (%.2f%%) | Currency: %s | Known products: %d | Minimax prices include VAT: %s',
            $this->defaultTaxId,
            $this->defaultTaxRate,
            $this->currencyId,
            count($this->productMap),
            $this->priceIncludesVat ? 'yes' : 'no',
        ));
    }

    protected function fetchPage(int $page, int $pageSize, array $loopData): array
    {
        $result = $this->minimaxClient->getProductsPage($page, $pageSize);

        $this->sourceHasMorePages = (bool) $result['hasMore'];
        $this->logClientWarnings();

        return $result['rows'];
    }

    protected function hasMorePages(int $page, int $fetchedRows, int $pageSize): bool
    {
        return $this->sourceHasMorePages;
    }

    protected function import(array $dataArray, array $loopData): int
    {
        $upserts = [];

        foreach ($dataArray as $product) {
            $sku  = trim((string) ($product['sku'] ?? ''));
            $name = trim((string) ($product['name'] ?? ''));

            if ($sku === '' || $name === '') {
                ++$this->skipped;
                continue;
            }

            $price = (float) ($product['price'] ?? 0.0);
            $stock = max(0, (int) round((float) ($product['stock'] ?? 0)));

            // A VAT rate we could not resolve is NOT 0% — fall back to the shop's
            // default tax rather than silently zero-rating the product.
            $vat   = $product['vat'];
            $taxId = $this->resolveTaxId($vat, $sku);
            $rate  = $vat !== null ? (float) $vat : $this->defaultTaxRate;

            [$gross, $net] = $this->splitPrice($price, $rate);
            $existing      = $this->productMap[$sku] ?? null;

            if ($existing === null) {
                $id = Uuid::randomHex();

                $upserts[] = [
                    'id'            => $id,
                    'productNumber' => $sku,
                    'name'          => $name,
                    'taxId'         => $taxId,
                    'stock'         => $stock,
                    'price'         => $this->pricePayload($gross, $net),
                    // Created deactivated on purpose: an editor decides when a
                    // freshly imported product is fit to go live.
                    'active'        => false,
                ];
                ++$this->created;

                // Remember it immediately: a SKU repeated later in the same run
                // must update this row, not insert a second one and trip the
                // product_number unique constraint (which fails the whole batch).
                $this->productMap[$sku] = [
                    'id'    => $id,
                    'stock' => $stock,
                    'taxId' => $taxId,
                    'gross' => $gross,
                    'net'   => $net,
                ];

                continue;
            }

            if ($this->isUnchanged($existing, $stock, $taxId, $gross, $net)) {
                ++$this->unchanged;
                continue;
            }

            $upserts[] = [
                'id'    => $existing['id'],
                'taxId' => $taxId,
                'stock' => $stock,
                'price' => $this->pricePayload($gross, $net),
            ];
            ++$this->updated;
        }

        if ($this->dryRun) {
            OwLogger::addVisibleLog($this->logger, sprintf('ProductSync DRY RUN: would write %d products.', count($upserts)));

            return count($upserts);
        }

        $this->flush($upserts);

        return count($upserts);
    }

    protected function finalize(): void
    {
        $this->logClientWarnings();

        if ($this->defaultTaxFallbacks > 0) {
            OwLogger::warning($this->logger, sprintf(
                'ProductSync: %d product(s) had no usable Minimax VAT rate and fell back to the default tax (%.2f%%). Sample SKUs: %s',
                $this->defaultTaxFallbacks,
                $this->defaultTaxRate,
                implode(', ', $this->defaultTaxFallbackSkus),
            ));
        }

        OwLogger::addVisibleLog($this->logger, sprintf(
            'ProductSync totals: %d created (inactive), %d updated, %d unchanged, %d skipped.',
            $this->created,
            $this->updated,
            $this->unchanged,
            $this->skipped,
        ));
    }

    // -------------------------------------------------------------------------
    // Pricing
    // -------------------------------------------------------------------------

    /**
     * Split a Minimax item price into Shopware's gross/net pair.
     *
     * Minimax exposes a single `Price` per item plus a separate VatRate link. Its
     * document rows follow the convention `Price` = without VAT and
     * `PriceWithVAT` = with VAT, so an item price is treated as net by default.
     * The `minimaxItemPriceIncludesVat` setting flips that for an organisation
     * that maintains gross item prices instead.
     *
     * @return array{0: float, 1: float} [gross, net]
     */
    private function splitPrice(float $price, float $vatRate): array
    {
        $factor = 1 + ($vatRate / 100);

        if ($this->priceIncludesVat) {
            return [
                round($price, 4),
                $factor > 0 ? round($price / $factor, 6) : round($price, 6),
            ];
        }

        return [
            round($price * $factor, 4),
            round($price, 6),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function pricePayload(float $gross, float $net): array
    {
        return [[
            'currencyId' => $this->currencyId,
            'gross'      => $gross,
            'net'        => $net,
            'linked'     => true,
        ]];
    }

    /**
     * @param array{id: string, stock: int, taxId: string, gross: ?float, net: ?float} $existing
     */
    private function isUnchanged(array $existing, int $stock, string $taxId, float $gross, float $net): bool
    {
        if ($existing['stock'] !== $stock || $existing['taxId'] !== $taxId) {
            return false;
        }

        if ($existing['gross'] === null || $existing['net'] === null) {
            return false;
        }

        return abs($existing['gross'] - $gross) < self::PRICE_EPSILON
            && abs($existing['net'] - $net) < self::PRICE_EPSILON;
    }

    // -------------------------------------------------------------------------
    // Shopware lookups
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{id: string, stock: int, taxId: string, gross: ?float, net: ?float}>
     */
    private function buildProductMap(): array
    {
        $sql = <<<'SQL'
            SELECT
                LOWER(HEX(id))     AS id,
                product_number,
                stock,
                LOWER(HEX(tax_id)) AS tax_id,
                price
            FROM product
            WHERE version_id = :liveVersion
              AND product_number IS NOT NULL
              AND product_number != ''
        SQL;

        $map = [];

        $rows = $this->connection->fetchAllAssociative($sql, [
            'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
        ]);

        foreach ($rows as $row) {
            [$gross, $net] = $this->readStoredPrice($row['price']);

            $map[$row['product_number']] = [
                'id'    => $row['id'],
                'stock' => (int) $row['stock'],
                'taxId' => (string) ($row['tax_id'] ?? ''),
                'gross' => $gross,
                'net'   => $net,
            ];
        }

        return $map;
    }

    /**
     * Read the default-currency gross/net out of a stored product price JSON.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function readStoredPrice(mixed $priceJson): array
    {
        if (!is_string($priceJson) || $priceJson === '') {
            return [null, null];
        }

        $decoded = json_decode($priceJson, true);
        if (!is_array($decoded) || $decoded === []) {
            return [null, null];
        }

        // Stored keyed by currency id with a "c" prefix, as written by
        // PriceFieldSerializer: { "c<currencyId>": { net, gross, linked, ... } }.
        $entry = $decoded['c' . $this->currencyId] ?? $decoded[$this->currencyId] ?? reset($decoded);

        if (!is_array($entry) || !isset($entry['gross'], $entry['net'])) {
            return [null, null];
        }

        return [(float) $entry['gross'], (float) $entry['net']];
    }

    private function loadDefaultTax(): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, tax_rate FROM tax ORDER BY position ASC, tax_rate DESC LIMIT 1'
        );

        if (is_array($row)) {
            $this->defaultTaxId   = (string) $row['id'];
            $this->defaultTaxRate = (float) $row['tax_rate'];
        }
    }

    private function resolveCurrencyId(): string
    {
        $id = $this->connection->fetchOne(
            "SELECT LOWER(HEX(id)) FROM currency WHERE iso_code = 'EUR' LIMIT 1"
        );

        return is_string($id) && $id !== '' ? $id : Defaults::CURRENCY;
    }

    /**
     * Build a map of Shopware tax rate → tax ID, e.g. "22.00" → "<uuid>".
     *
     * @return array<string, string>
     */
    private function buildTaxRateMap(): array
    {
        $map = [];

        foreach ($this->connection->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id, tax_rate FROM tax') as $row) {
            $map[$this->rateKey((float) $row['tax_rate'])] = (string) $row['id'];
        }

        return $map;
    }

    /**
     * Resolve the Shopware tax ID for a Minimax VAT percent, creating the tax
     * entity on the fly when the shop has no matching rate.
     *
     * An unknown (null) or non-positive rate always falls back to the shop
     * default: a 0% rate created from a failed lookup would quietly make the
     * product tax-free.
     */
    private function resolveTaxId(?float $percent, string $sku): string
    {
        if ($percent === null || $percent <= 0.0) {
            // Counted rather than logged per item: on a catalogue-wide lookup
            // failure this would otherwise be one log line per product.
            ++$this->defaultTaxFallbacks;
            if (count($this->defaultTaxFallbackSkus) < 20) {
                $this->defaultTaxFallbackSkus[] = $sku;
            }

            return $this->defaultTaxId;
        }

        $key = $this->rateKey($percent);

        if (isset($this->taxRateMap[$key])) {
            return $this->taxRateMap[$key];
        }

        if ($this->dryRun) {
            OwLogger::addVisibleLog($this->logger, sprintf('ProductSync DRY RUN: would create a %s%% tax.', $key));

            return $this->defaultTaxId;
        }

        $id     = Uuid::randomHex();
        $name   = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . '%';
        $result = $this->shopwareClient->upsertData('tax', [
            'id'      => $id,
            'name'    => $name,
            'taxRate' => $percent,
        ], true);

        if (($result['status'] ?? '') !== 'ok') {
            OwLogger::warning($this->logger, sprintf(
                'ProductSync: could not create %s%% tax (%s) — falling back to the default tax.',
                $key,
                $result['error'] ?? 'unknown error',
            ));

            return $this->defaultTaxId;
        }

        OwLogger::addVisibleLog($this->logger, sprintf('ProductSync: created missing %s tax rate.', $name));

        return $this->taxRateMap[$key] = $id;
    }

    private function rateKey(float $percent): string
    {
        return number_format($percent, 2, '.', '');
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Write products through the sync API.
     *
     * `upsert` is the only write action the sync API accepts (alongside
     * `delete`), for both new and existing rows.
     *
     * @param array<int, array<string, mixed>> $payload
     */
    private function flush(array $payload): void
    {
        if (empty($payload)) {
            return;
        }

        $this->shopwareClient->bulkAddData('product-upsert', 'product', 'upsert', $payload, true);
        $this->shopwareClient->bulkDataProcessQueue(true);
    }

    private function logClientWarnings(): void
    {
        foreach ($this->minimaxClient->takeWarnings() as $warning) {
            OwLogger::warning($this->logger, 'Minimax: ' . $warning);
        }
    }
}
