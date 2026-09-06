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

    /**
     * Only consulted for an item that items/pricelists did not cover; the
     * pricelist states net and gross outright.
     */
    private bool $priceIncludesVat = true;

    /** Items priced by falling back to Item.Price instead of the pricelist. */
    private int $priceFallbacks = 0;

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
        $this->priceIncludesVat = $this->minimaxClient->pricesIncludeVat();

        $this->currencyId = $this->resolveCurrencyId();
        $this->taxRateMap = $this->buildTaxRateMap();
        $this->loadDefaultTax();
        $this->productMap = $this->buildProductMap();

        if ($this->defaultTaxId === '' || $this->currencyId === '') {
            throw new \RuntimeException('ProductSync: could not resolve the default tax or currency.');
        }

        OwLogger::addVisibleLog($this->logger, sprintf(
            'ProductSync initialized. Default tax: %.2f%% (%s) | Currency: %s | Known products: %d | '
            . 'Prices: items/pricelists (Item.Price fallback treats it as %s) | Only SKU: %s | Force write: %s',
            $this->defaultTaxRate,
            $this->defaultTaxId,
            $this->currencyId,
            count($this->productMap),
            $this->priceIncludesVat ? 'gross' : 'net',
            $this->setId ?? '(all)',
            $this->ignoreHash ? 'yes' : 'no',
        ));
    }

    protected function fetchPage(int $page, int $pageSize, array $loopData): array
    {
        // --setId=<SKU> narrows the run to a single product: one item, one page.
        if ($this->setId !== null) {
            $this->sourceHasMorePages = false;
            $rows                     = $this->minimaxClient->getProductBySku($this->setId);
            $this->logClientWarnings();

            return $rows;
        }

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

            // Stock is floored, never rounded: Minimax carries fractional
            // quantities for anything sold by the metre (23.5 m of lining), and
            // rounding 23.5 up to 24 advertises a metre that does not exist.
            $stock = max(0, (int) floor((float) ($product['stock'] ?? 0)));

            // A VAT rate we could not resolve is NOT 0% — fall back to the shop's
            // default tax rather than silently zero-rating the product.
            $vat   = $product['vat'];
            $taxId = $this->resolveTaxId($vat, $sku);
            $rate  = $vat !== null ? (float) $vat : $this->defaultTaxRate;

            [$gross, $net] = $this->resolvePrice($product, $rate);
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

            // -i / --ignoreHash forces the write even when nothing differs,
            // which is how a product is repaired after a bad import.
            if (!$this->ignoreHash && $this->isUnchanged($existing, $stock, $taxId, $gross, $net)) {
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

        if ($this->priceFallbacks > 0) {
            OwLogger::warning($this->logger, sprintf(
                'ProductSync: %d product(s) had no items/pricelists row; their price was derived from Item.Price '
                . 'treated as %s.',
                $this->priceFallbacks,
                $this->priceIncludesVat ? 'gross' : 'net',
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
     * Resolve a Minimax item's Shopware gross/net pair.
     *
     * Minimax's items/pricelists states PriceWithoutVAT and PriceWithVAT for
     * every item, so normally nothing is derived here — which matters, because
     * a bare `Item.Price` is net or gross depending on the organisation's
     * price-entry setting and guessing wrong shifts the whole catalogue by the
     * VAT rate. Only an item missing from the pricelist is derived from
     * `Item.Price`, using that setting as read from items/settings.
     *
     * @param array{net?: float|null, gross?: float|null, price?: float} $product
     *
     * @return array{0: float, 1: float} [gross, net]
     */
    private function resolvePrice(array $product, float $vatRate): array
    {
        $net   = $product['net'] ?? null;
        $gross = $product['gross'] ?? null;

        if ($net !== null && $gross !== null) {
            return [round((float) $gross, 4), round((float) $net, 6)];
        }

        ++$this->priceFallbacks;

        $price  = (float) ($product['price'] ?? 0.0);
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

    /**
     * Pick the tax an item falls back to when its Minimax rate cannot be read.
     *
     * Preferably the Shopware tax matching Minimax's own standard rate (VatRate
     * code "S" — 22% in SI). Ordering the tax table by position is not enough on
     * its own: taxes this sync creates land at position 0, so a reduced 9.5% rate
     * created for one item ends up ahead of the shop's standard rate and every
     * unresolved product is then under-taxed.
     */
    private function loadDefaultTax(): void
    {
        $standard = $this->minimaxClient->getStandardVatPercent();

        if ($standard !== null && isset($this->taxRateMap[$this->rateKey($standard)])) {
            $this->defaultTaxId   = $this->taxRateMap[$this->rateKey($standard)];
            $this->defaultTaxRate = $standard;

            return;
        }

        // No usable standard rate — take the highest rate the shop defines, which
        // errs towards over- rather than under-taxing.
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, tax_rate FROM tax ORDER BY tax_rate DESC, position ASC LIMIT 1'
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
            'id'       => $id,
            'name'     => $name,
            'taxRate'  => $percent,
            // Sorted behind the shop's own rates on purpose: position 0 would put
            // a sync-created reduced rate ahead of the standard one everywhere
            // Shopware orders taxes by position.
            'position' => 100,
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
