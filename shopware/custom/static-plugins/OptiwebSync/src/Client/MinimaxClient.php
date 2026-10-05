<?php declare(strict_types=1);

namespace OptiwebSync\Client;

use OptiwebSync\Helper\EnvHelper;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimax REST API client.
 *
 * Authentication: OAuth 2.0 password grant
 * Docs: https://moj.minimax.si/SI/API/Help
 *
 * Required env vars:
 *   MINIMAX_CLIENT_ID, MINIMAX_CLIENT_SECRET, MINIMAX_USERNAME, MINIMAX_PASSWORD
 * Optional:
 *   MINIMAX_LOCALE  (default: si)  — one of: si, rs, hr
 *   MINIMAX_ORG_ID  organisation id; auto-discovered via api/currentuser/orgs when unset
 *
 * Verified against the published API reference. Two conventions are worth
 * remembering, because getting either wrong fails silently rather than loudly:
 *
 *   1. List endpoints accept ONLY their documented filter parameters. An unknown
 *      parameter (e.g. `?Code=x` on /items, `?TaxNumber=x` on /customers) is
 *      ignored and the endpoint answers with the *unfiltered* first page. Single
 *      records are looked up through the `code({code})` route instead — and that
 *      route takes the bare value, not a quoted one.
 *   2. Document rows carry prices WITHOUT VAT. IssuedInvoiceRow distinguishes
 *      `Price` from `PriceWithVAT`; OrderRow has only `Price`, and no VatRate at
 *      all — VAT is derived from the referenced Item. So OrderRow.Price is net.
 *   3. An *item's* `Price`, unlike a document row's, is net or gross depending on
 *      the organisation's "Vnos cen v šifrantu Artikli" VAT-period setting —
 *      GET items/settings answers D (incl. VAT) or N (excl.). Org 239849 is on
 *      D, so Item.Price is GROSS there. Rather than derive from a setting that
 *      can change under us, prices are read from GET items/pricelists, which
 *      states PriceWithoutVAT and PriceWithVAT explicitly for every item.
 */
class MinimaxClient implements ClientInterface
{
    private const PAGE_SIZE   = 100;
    private const MAX_RETRIES = 3;

    /** Candidate keys for the percent value inside a VatRate record. */
    private const VAT_PERCENT_KEYS = ['Percent', 'Percentage', 'Rate', 'VatRatePercent', 'Value'];

    /** Minimax VatRate.Code for the standard rate — 22% in SI. */
    private const VAT_CODE_STANDARD = 'S';

    private string $accessToken = '';
    private int    $tokenExpiry = 0;
    private string $orgId       = '';

    // Lookup caches — populated on first use
    private array $currencyCache   = [];  // isoCode → int ID
    private array $countryCache    = [];  // isoCode → int|null ID
    private array $itemCache       = [];  // SKU → int|null ID
    private array $stockMap        = [];  // SKU → float quantity
    private bool  $stockMapLoaded  = false;
    private array $vatPercentCache = [];  // VatRate ID → float|null percent
    private array $priceMap        = [];  // SKU → array{net: float, gross: float}
    private bool  $priceMapLoaded  = false;
    private ?bool $pricesIncludeVat = null;
    private bool  $vatRatesLoaded  = false;
    private ?float $standardVatPercent = null;

    /** @var list<string> Non-fatal problems worth surfacing to the sync log. */
    private array $warnings = [];

    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    // -------------------------------------------------------------------------
    // Products
    // -------------------------------------------------------------------------

    /**
     * Fetch one page of items with stock quantity and VAT percent merged in.
     *
     * GET api/orgs/{orgId}/items — returns SearchResult<ItemSearch>:
     *   { Rows: [...], TotalRows: n, CurrentPageNumber: n, PageSize: n }
     * ItemSearch fields used: ItemId, Code, Title, Price, VatRate (mMApiFkField).
     *
     * Paging is driven by TotalRows/PageSize from the response rather than by
     * counting returned rows: rows without a usable SKU are filtered out here,
     * so a row count can never be a reliable "is there another page" signal.
     *
     * @return array{rows: array<int, array{id: int, sku: string, name: string, net: float|null, gross: float|null, price: float, vat: float|null, stock: float}>, totalRows: int, page: int, pageSize: int, hasMore: bool}
     */
    public function getProductsPage(int $page, int $pageSize): array
    {
        $this->ensureAuthenticated();
        $orgId    = $this->getOrganizationId();
        $pageSize = $this->clampPageSize($pageSize);

        $data = $this->get($this->url($orgId, 'items', [
            'PageSize'    => $pageSize,
            'CurrentPage' => $page,
            'SortField'   => 'ItemId',
            'Order'       => 'A',
        ]));

        $rows      = $this->rows($data);
        $totalRows = (int) ($data['TotalRows'] ?? 0);
        $stockMap  = $this->getStockMap($orgId);
        $products  = [];

        foreach ($rows as $item) {
            $sku = trim((string) ($item['Code'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $id = (int) ($item['ItemId'] ?? 0);
            if ($id > 0) {
                $this->itemCache[$sku] = $id;
            }

            $products[] = $this->buildProductRow($item, $sku, $id, $orgId, $stockMap);
        }

        return [
            'rows'      => $products,
            'totalRows' => $totalRows,
            'page'      => $page,
            'pageSize'  => $pageSize,
            'hasMore'   => $totalRows > 0
                ? $page * $pageSize < $totalRows
                : count($rows) >= $pageSize,
        ];
    }

    /**
     * Fetch a single item by SKU, shaped exactly like a getProductsPage() row.
     *
     * Backs `optiweb:sync product --setId=<SKU>`. Stock and prices still come
     * from the shared maps, so one product costs the same two list calls as a
     * full run — but only that product is written.
     *
     * @return array<int, array{id: int, sku: string, name: string, net: float|null, gross: float|null, price: float, vat: float|null, stock: float}>
     */
    public function getProductBySku(string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return [];
        }

        $this->ensureAuthenticated();
        $orgId = $this->getOrganizationId();

        $item = $this->fetchItemByCode($sku, $orgId);
        if ($item === null) {
            $this->warn(sprintf('SKU "%s" not found in Minimax.', $sku));

            return [];
        }

        // The code() route answers a full Item (Name), the SearchString
        // fallback an ItemSearch (Title) — accept either.
        $item['Title'] ??= $item['Name'] ?? '';
        $code = trim((string) ($item['Code'] ?? $sku));

        return [$this->buildProductRow($item, $code, (int) ($item['ItemId'] ?? 0), $orgId, $this->getStockMap($orgId))];
    }

    /**
     * Shape one Minimax item into the row the product import consumes.
     *
     * @param array<string, mixed> $item
     * @param array<string, float> $stockMap
     *
     * @return array{id: int, sku: string, name: string, net: float|null, gross: float|null, price: float, vat: float|null, stock: float}
     */
    private function buildProductRow(array $item, string $sku, int $id, string $orgId, array $stockMap): array
    {
        if ($id > 0) {
            $this->itemCache[$sku] = $id;
        }

        $priced = $this->getPriceMap($orgId)[$sku] ?? null;

        return [
            'id'    => $id,
            'sku'   => $sku,
            'name'  => trim((string) ($item['Title'] ?? '')),
            // Authoritative pair from items/pricelists. null means the item has
            // no pricelist row and the caller must derive from `price`.
            'net'   => $priced['net'] ?? null,
            'gross' => $priced['gross'] ?? null,
            // Raw Item.Price — net or gross per the organisation's VAT-period
            // setting, so only usable together with pricesIncludeVat().
            'price' => (float) ($item['Price'] ?? 0.0),
            // null (not 0.0) means "unknown" — the caller must NOT treat an
            // unresolved VAT rate as a 0% rate.
            'vat'   => $this->resolveVatPercent($item['VatRate'] ?? null),
            'stock' => (float) ($stockMap[$sku] ?? 0),
        ];
    }

    /**
     * Selling prices for the whole catalogue, keyed by item code.
     *
     * GET api/orgs/{orgId}/items/pricelists — returns ListResult<ItemPriceListItem>
     * with PriceWithoutVAT and PriceWithVAT stated separately, so neither the VAT
     * rate nor the organisation's price-entry setting has to be applied by us.
     *
     * Unlike the other list endpoints this one is NOT paged (ListResult carries
     * no TotalRows) — one unfiltered call returns the entire catalogue.
     *
     * @return array<string, array{net: float, gross: float}>
     */
    private function getPriceMap(string $orgId): array
    {
        if ($this->priceMapLoaded) {
            return $this->priceMap;
        }

        // Marked loaded up front: on a failed call the import falls back to
        // deriving from Item.Price rather than retrying this per product.
        $this->priceMapLoaded = true;

        try {
            $data = $this->get($this->url($orgId, 'items/pricelists'));
        } catch (\RuntimeException $e) {
            $this->warn('Could not read items/pricelists, falling back to Item.Price: ' . $e->getMessage());

            return $this->priceMap;
        }

        foreach ($this->rows($data) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));
            if ($code === '' || !isset($row['PriceWithoutVAT'], $row['PriceWithVAT'])) {
                continue;
            }

            $this->priceMap[$code] = [
                'net'   => (float) $row['PriceWithoutVAT'],
                'gross' => (float) $row['PriceWithVAT'],
            ];
        }

        return $this->priceMap;
    }

    /**
     * Whether this organisation enters item prices inclusive of VAT.
     *
     * GET api/orgs/{orgId}/items/settings — PricesIncludeVAT is "D" (with VAT)
     * or "N" (without). Only needed for items that items/pricelists did not
     * cover; the pricelist states both figures outright.
     */
    public function pricesIncludeVat(): bool
    {
        if ($this->pricesIncludeVat !== null) {
            return $this->pricesIncludeVat;
        }

        $this->ensureAuthenticated();

        try {
            $data = $this->get($this->url($this->getOrganizationId(), 'items/settings'));
        } catch (\RuntimeException $e) {
            $this->warn('Could not read items/settings; assuming item prices include VAT: ' . $e->getMessage());

            return $this->pricesIncludeVat = true;
        }

        return $this->pricesIncludeVat = strtoupper(trim((string) ($data['PricesIncludeVAT'] ?? 'D'))) === 'D';
    }

    /**
     * Stock quantities for the whole organisation, keyed by item code.
     *
     * GET api/orgs/{orgId}/stocks — returns SearchResult<StockListItem> with
     * fields Item, ItemName, ItemCode, ItemEANCode, UnitOfMeasurement,
     * AveragePurchasePrice, SellingPrice, Quantity, Value, BatchNumber.
     *
     * `Mode=1` also returns items that are not currently in stock but have been
     * at some point — without it an item that dropped to zero simply vanishes
     * from the response. A missing code means 0 either way, but Mode=1 makes
     * "went out of stock" explicit rather than inferred.
     *
     * Rows come aggregated per item unless ResultsByBatchNumber=D is requested
     * (we don't), so one row per code is expected — quantities are nonetheless
     * summed so a batch-split response could not silently overwrite itself.
     *
     * @return array<string, float> item code → quantity
     */
    private function getStockMap(string $orgId): array
    {
        if ($this->stockMapLoaded) {
            return $this->stockMap;
        }

        $page = 1;

        do {
            $data = $this->get($this->url($orgId, 'stocks', [
                'PageSize'    => self::PAGE_SIZE,
                'CurrentPage' => $page,
                'Mode'        => 1,
                // Explicit ordering: without it the server is free to return an
                // unstable order and paging could repeat or skip rows.
                'SortField'   => 'ItemCode',
                'Order'       => 'A',
            ]));

            $rows      = $this->rows($data);
            $totalRows = (int) ($data['TotalRows'] ?? 0);

            foreach ($rows as $row) {
                $code = trim((string) ($row['ItemCode'] ?? ''));
                if ($code === '') {
                    continue;
                }

                $this->stockMap[$code] = ($this->stockMap[$code] ?? 0.0) + (float) ($row['Quantity'] ?? 0.0);
            }

            $hasMore = $totalRows > 0
                ? $page * self::PAGE_SIZE < $totalRows
                : count($rows) >= self::PAGE_SIZE;
            ++$page;
        } while ($hasMore);

        $this->stockMapLoaded = true;

        return $this->stockMap;
    }

    /**
     * Load the organisation's VAT rates in one call.
     *
     * GET api/orgs/{orgId}/vatrates — SearchResult<VatRate> of {VatRateId, Code,
     * Percent}. Six rows for a Slovenian organisation, so this replaces one HTTP
     * round trip per distinct rate. Failure is non-fatal: resolveVatPercent()
     * still falls back to following each rate's ResourceUrl.
     */
    private function loadVatRates(string $orgId): void
    {
        if ($this->vatRatesLoaded) {
            return;
        }

        $this->vatRatesLoaded = true;

        try {
            $data = $this->get($this->url($orgId, 'vatrates', ['PageSize' => self::PAGE_SIZE, 'CurrentPage' => 1]));
        } catch (\RuntimeException $e) {
            $this->warn('Could not read the VAT rate list, falling back to per-rate lookups: ' . $e->getMessage());

            return;
        }

        foreach ($this->rows($data) as $row) {
            $id = (int) ($row['VatRateId'] ?? $row['ID'] ?? 0);
            if ($id <= 0 || !isset($row['Percent']) || !is_numeric($row['Percent'])) {
                continue;
            }

            $this->vatPercentCache[$id] = (float) $row['Percent'];

            if (strtoupper(trim((string) ($row['Code'] ?? ''))) === self::VAT_CODE_STANDARD) {
                $this->standardVatPercent = (float) $row['Percent'];
            }
        }
    }

    /**
     * The organisation's standard VAT percent (VatRate.Code "S"), or null.
     *
     * The product import uses it to pick the Shopware tax that stands in for an
     * item whose own rate could not be resolved — a shop-side "first by position"
     * guess can land on a reduced rate, which would under-tax the product.
     */
    public function getStandardVatPercent(): ?float
    {
        $this->ensureAuthenticated();
        $this->loadVatRates($this->getOrganizationId());

        return $this->standardVatPercent;
    }

    /**
     * Resolve an item's VAT percent from its VatRate link.
     *
     * Served from the organisation's rate list; an ID missing from that list
     * falls back to following the ResourceUrl that Minimax puts on every
     * mMApiFkField. Returns null when the rate cannot be established — callers
     * must fall back to a sensible default rather than assume 0%.
     */
    private function resolveVatPercent(mixed $vatRate): ?float
    {
        if (!is_array($vatRate)) {
            return null;
        }

        $id = (int) ($vatRate['ID'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $this->loadVatRates($this->getOrganizationId());

        if (array_key_exists($id, $this->vatPercentCache)) {
            return $this->vatPercentCache[$id];
        }

        $percent     = null;
        $resourceUrl = (string) ($vatRate['ResourceUrl'] ?? '');

        if ($resourceUrl === '') {
            $this->warn(sprintf('VAT rate %d has no ResourceUrl to resolve the percent from.', $id));

            return $this->vatPercentCache[$id] = null;
        }

        try {
            $record = $this->get($this->absoluteUrl($resourceUrl));

            foreach (self::VAT_PERCENT_KEYS as $key) {
                if (isset($record[$key]) && is_numeric($record[$key])) {
                    $percent = (float) $record[$key];
                    break;
                }
            }

            if ($percent === null) {
                $this->warn(sprintf(
                    'VAT rate %d (%s) has no recognisable percent field; available keys: %s',
                    $id,
                    (string) ($vatRate['Name'] ?? '?'),
                    implode(', ', array_keys($record)),
                ));
            }
        } catch (\RuntimeException $e) {
            $this->warn(sprintf('Could not read VAT rate %d: %s', $id, $e->getMessage()));
        }

        return $this->vatPercentCache[$id] = $percent;
    }

    // -------------------------------------------------------------------------
    // Orders
    // -------------------------------------------------------------------------

    /**
     * Export an order to Minimax as an issued sales order (Naročilo).
     *
     * POST api/orgs/{orgId}/orders
     *
     * Order fields per the reference: ReceivedIssued ("I" issued / "P" received,
     * required), Year (required), Date, Customer, Customer* free-text address,
     * Recipient* delivery address, Reference, Currency, Notes, Status
     * ("P" confirmed / "O" draft / "Z" terminated / "R" invalidated), OrderRows.
     *
     * OrderRow fields: Item, Warehouse, ItemName, ItemCode, Description,
     * Quantity, DiscountPercent, Price, UnitOfMeasurement. A row carries neither
     * VatRate nor PriceWithVAT, so `unitPrice` MUST be net.
     *
     * Input array shape:
     *   date:        string  ISO datetime or YYYY-MM-DD
     *   year:        int     optional — derived from date when omitted
     *   reference:   string  free-text reference (Shopware order number)
     *   note:        string  order notes
     *   currencyIso: string  ISO currency code (default: EUR)
     *   customer:    [name, email, code, address, postalCode, city, countryIso, vatId]
     *   recipient:   [name, address, postalCode, city, countryIso]   (optional)
     *   lines:       [[sku, name, quantity, unitPrice (NET), discountPercent, unitOfMeasurement, description], ...]
     *
     * @return array{status: string, orderId?: int|string, error?: string}
     */
    public function createOrder(array $orderData): array
    {
        try {
            $payload = $this->buildOrderPayload($orderData);
            $orgId   = $this->getOrganizationId();

            $response = $this->post($this->url($orgId, 'orders'), $payload);
            $orderId  = $response['OrderId'] ?? $response['OrderID'] ?? $response['ID'] ?? null;

            if ($orderId === null) {
                // The order may well have been created — surface the raw body so
                // the caller can investigate instead of retrying into a duplicate.
                return [
                    'status' => 'error',
                    'error'  => 'Order POST succeeded but no order id in response: ' . json_encode($response),
                ];
            }

            return ['status' => 'ok', 'orderId' => $orderId];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Build the Minimax order payload without sending it.
     *
     * Used by createOrder() and by the export's dry-run mode, so a dry run
     * exercises the same customer/item/currency resolution as a real export.
     */
    public function buildOrderPayload(array $orderData): array
    {
        $this->ensureAuthenticated();
        $orgId = $this->getOrganizationId();

        $customer   = $orderData['customer'] ?? [];
        $customerId = $this->findOrCreateCustomer($customer, $orgId);
        $currencyId = $this->resolveCurrencyId((string) ($orderData['currencyIso'] ?? 'EUR'), $orgId);

        $orderRows = [];
        foreach ($orderData['lines'] ?? [] as $line) {
            $sku    = trim((string) ($line['sku'] ?? ''));
            $itemId = $sku !== '' ? $this->findItemIdByCode($sku, $orgId) : null;

            $row = [
                'ItemCode' => $sku,
                'ItemName' => (string) ($line['name'] ?? ''),
                'Quantity' => (float) ($line['quantity'] ?? 1),
                'Price'    => (float) ($line['unitPrice'] ?? 0),
            ];

            // Reference the Minimax item when the SKU resolves; otherwise keep
            // the row as a free-text line so nothing — shipping, discounts,
            // custom items — is silently dropped from the order.
            if ($itemId !== null) {
                $row['Item'] = ['ID' => $itemId];
            } elseif ($sku !== '') {
                $this->warn(sprintf('SKU "%s" not found in Minimax — exported as a free-text row.', $sku));
            }

            if ((float) ($line['discountPercent'] ?? 0) > 0) {
                $row['DiscountPercent'] = (float) $line['discountPercent'];
            }
            if (!empty($line['unitOfMeasurement'])) {
                $row['UnitOfMeasurement'] = (string) $line['unitOfMeasurement'];
            }
            if (!empty($line['description'])) {
                $row['Description'] = (string) $line['description'];
            }

            $orderRows[] = $row;
        }

        if (empty($orderRows)) {
            throw new \RuntimeException('No order rows to export');
        }

        $date = (new \DateTimeImmutable((string) ($orderData['date'] ?? 'now')))->format('Y-m-d');
        $year = (int) ($orderData['year'] ?? substr($date, 0, 4));

        $payload = [
            'ReceivedIssued' => 'I',   // issued order
            'Year'           => $year,
            'Date'           => $date,
            'Customer'       => ['ID' => $customerId],
            'Currency'       => ['ID' => $currencyId],
            'Status'         => 'O',   // draft
            'OrderRows'      => $orderRows,
        ];

        if (!empty($orderData['reference'])) {
            $payload['Reference'] = (string) $orderData['reference'];
        }
        if (!empty($orderData['note'])) {
            $payload['Notes'] = (string) $orderData['note'];
        }

        $this->applyAddress($payload, 'Customer', $customer, $orgId);
        $this->applyAddress($payload, 'Recipient', $orderData['recipient'] ?? [], $orgId);

        return $payload;
    }

    /**
     * Populate the free-text address fields for one address block, either the
     * `Customer…` (billing) or the `Recipient…` (delivery) set.
     *
     * The country goes in as an mMApiFkField by ID when it resolves; only when
     * it does not do we fall back to the free-text name — the reference
     * explicitly prohibits RecipientCountryName when RecipientCountry is the
     * home country.
     */
    private function applyAddress(array &$payload, string $prefix, array $address, string $orgId): void
    {
        if (empty($address['name']) && empty($address['address'])) {
            return;
        }

        foreach (['name' => 'Name', 'address' => 'Address', 'postalCode' => 'PostalCode', 'city' => 'City'] as $src => $field) {
            if (!empty($address[$src])) {
                $payload[$prefix . $field] = (string) $address[$src];
            }
        }

        $countryIso = trim((string) ($address['countryIso'] ?? ''));
        if ($countryIso === '') {
            return;
        }

        $countryId = $this->resolveCountryId($countryIso, $orgId);
        if ($countryId !== null) {
            $payload[$prefix . 'Country'] = ['ID' => $countryId];
        } else {
            $payload[$prefix . 'CountryName'] = $countryIso;
        }
    }

    // -------------------------------------------------------------------------
    // Authentication & organisation
    // -------------------------------------------------------------------------

    private function ensureAuthenticated(): void
    {
        if ($this->accessToken !== '' && $this->tokenExpiry > time() + 60) {
            return;
        }

        $this->authenticate();
    }

    private function authenticate(): void
    {
        $locale = $this->locale();
        $url    = 'https://moj.minimax.' . $locale . '/' . $locale . '/AUT/oauth20/token';

        $payload = http_build_query([
            'grant_type'    => 'password',
            'client_id'     => EnvHelper::read('MINIMAX_CLIENT_ID', self::class),
            'client_secret' => EnvHelper::read('MINIMAX_CLIENT_SECRET', self::class),
            'username'      => EnvHelper::read('MINIMAX_USERNAME', self::class),
            'password'      => EnvHelper::read('MINIMAX_PASSWORD', self::class),
        ]);

        try {
            $response   = $this->client->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body'    => $payload,
                'timeout' => 15.0,
            ]);
            $statusCode = $response->getStatusCode();
            $data       = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Minimax authentication request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($statusCode !== 200 || empty($data['access_token'])) {
            $detail = $data['error_description'] ?? $data['error'] ?? 'unknown';
            throw new \RuntimeException("Minimax authentication failed (HTTP $statusCode): $detail");
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = time() + (int) ($data['expires_in'] ?? 3600);
    }

    /**
     * Organisation id — from MINIMAX_ORG_ID when set, otherwise discovered via
     * api/currentuser/orgs (whose rows carry OrganisationId).
     */
    private function getOrganizationId(): string
    {
        if ($this->orgId !== '') {
            return $this->orgId;
        }

        $configured = trim((string) ($_SERVER['MINIMAX_ORG_ID'] ?? $_ENV['MINIMAX_ORG_ID'] ?? getenv('MINIMAX_ORG_ID') ?: ''));
        if ($configured !== '') {
            return $this->orgId = $configured;
        }

        $this->ensureAuthenticated();
        $orgs = $this->get($this->baseUrl() . 'api/currentuser/orgs');

        if (empty($orgs)) {
            throw new \RuntimeException('Minimax: no organisations found for this user');
        }

        if (count($orgs) > 1) {
            $this->warn(sprintf(
                'Minimax user has access to %d organisations; set MINIMAX_ORG_ID to pin one explicitly.',
                count($orgs),
            ));
        }

        $first = $orgs[0] ?? [];
        $id    = (string) ($first['OrganisationId'] ?? $first['Id'] ?? '');

        if ($id === '') {
            throw new \RuntimeException(
                'Minimax: unable to determine organisation ID from response: ' . json_encode($first),
            );
        }

        return $this->orgId = $id;
    }

    // -------------------------------------------------------------------------
    // Customer management
    // -------------------------------------------------------------------------

    /**
     * Resolve the Minimax customer for an order: match by VAT (tax) number
     * first, then by the Code we file customers under (their e-mail), and create
     * a new partner only when neither matches.
     */
    private function findOrCreateCustomer(array $customer, string $orgId): int
    {
        $vatId = trim((string) ($customer['vatId'] ?? ''));
        if ($vatId !== '') {
            $existing = $this->searchCustomerByTaxNumber($vatId, $orgId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $code = trim((string) ($customer['code'] ?? $customer['email'] ?? ''));
        if ($code !== '') {
            $existing = $this->findCustomerIdByCode($code, $orgId);
            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->createCustomer($customer, $orgId);
    }

    /**
     * Find a customer by tax number.
     *
     * The /customers list accepts only a SimpleSearchFilter (SearchString,
     * CurrentPage, PageSize, SortField, Order) — there is NO TaxNumber filter,
     * and an unknown parameter would be ignored, handing back the unfiltered
     * first page. So we search by string and then verify the match ourselves.
     */
    private function searchCustomerByTaxNumber(string $vatId, string $orgId): ?int
    {
        $taxNumber = $this->taxNumberDigits($vatId);
        if ($taxNumber === '') {
            return null;
        }

        try {
            $data = $this->get($this->url($orgId, 'customers', [
                'SearchString' => $taxNumber,
                'PageSize'     => self::PAGE_SIZE,
                'CurrentPage'  => 1,
            ]));
        } catch (\RuntimeException) {
            return null;
        }

        foreach ($this->rows($data) as $row) {
            $candidates = array_filter([
                $this->taxNumberDigits((string) ($row['TaxNumber'] ?? '')),
                $this->taxNumberDigits((string) ($row['VATIdentificationNumber'] ?? '')),
            ]);

            if (in_array($taxNumber, $candidates, true)) {
                $id = $row['CustomerId'] ?? $row['CustomerID'] ?? $row['ID'] ?? null;
                if ($id !== null) {
                    return (int) $id;
                }
            }
        }

        return null;
    }

    /**
     * Exact customer lookup by Code (unique within the organisation).
     *
     * GET api/orgs/{orgId}/customers/code({code}) — the route template takes the
     * bare value, so quoting it would search for a literal quoted string.
     */
    private function findCustomerIdByCode(string $code, string $orgId): ?int
    {
        try {
            $data = $this->get($this->codeUrl($orgId, 'customers', $code));
        } catch (\RuntimeException) {
            return null;
        }

        $record = $this->rows($data)[0] ?? $data;
        $id     = $record['CustomerId'] ?? $record['CustomerID'] ?? $record['ID'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    private function createCustomer(array $customer, string $orgId): int
    {
        $vatId     = trim((string) ($customer['vatId'] ?? ''));
        $countryIso = (string) ($customer['countryIso'] ?? 'SI');
        $countryId = $this->resolveCountryId($countryIso, $orgId);

        $payload = [
            'Name'            => (string) ($customer['name'] ?? ''),
            'Code'            => (string) ($customer['code'] ?? ''),
            'Address'         => (string) ($customer['address'] ?? ''),
            'PostalCode'      => (string) ($customer['postalCode'] ?? ''),
            'City'            => (string) ($customer['city'] ?? ''),
            'Currency'        => ['ID' => $this->resolveCurrencyId('EUR', $orgId)],
            // D = legal person / sole trader subject to VAT (has a VAT ID),
            // N = end user (B2C, no VAT ID). "M" — legal person not subject to
            // VAT — is not distinguishable from a Shopware order.
            'SubjectToVAT'    => $vatId !== '' ? 'D' : 'N',
            'EInvoiceIssuing' => 'SeNePripravlja',
        ];

        if ($countryId !== null) {
            $payload['Country'] = ['ID' => $countryId];
        } elseif ($countryIso !== '') {
            $payload['CountryName'] = $countryIso;
        }

        if ($vatId !== '') {
            $payload['TaxNumber']               = $this->taxNumberDigits($vatId);
            $payload['VATIdentificationNumber'] = $vatId;
        }

        $response = $this->post($this->url($orgId, 'customers'), $payload);
        $id       = $response['CustomerId'] ?? $response['CustomerID'] ?? $response['ID'] ?? null;

        if ($id === null) {
            throw new \RuntimeException(
                'Minimax customer created but no ID in response: ' . json_encode($response),
            );
        }

        return (int) $id;
    }

    private function taxNumberDigits(string $vatId): string
    {
        return preg_replace('/\D+/', '', $vatId) ?? '';
    }

    // -------------------------------------------------------------------------
    // Reference data resolution (cached)
    // -------------------------------------------------------------------------

    private function resolveCurrencyId(string $isoCode, string $orgId): int
    {
        if (isset($this->currencyCache[$isoCode])) {
            return $this->currencyCache[$isoCode];
        }

        $data = $this->get($this->codeUrl($orgId, 'currencies', $isoCode));
        $id   = $data['CurrencyId'] ?? $data['CurrencyID'] ?? $data['ID'] ?? null;

        if ($id === null) {
            throw new \RuntimeException("Minimax: currency '$isoCode' not found");
        }

        return $this->currencyCache[$isoCode] = (int) $id;
    }

    private function resolveCountryId(string $isoCode, string $orgId): ?int
    {
        if ($isoCode === '') {
            return null;
        }

        if (array_key_exists($isoCode, $this->countryCache)) {
            return $this->countryCache[$isoCode];
        }

        try {
            $data = $this->get($this->codeUrl($orgId, 'countries', $isoCode));
        } catch (\RuntimeException) {
            return $this->countryCache[$isoCode] = null;
        }

        $id = $data['CountryId'] ?? $data['CountryID'] ?? $data['ID'] ?? null;

        return $this->countryCache[$isoCode] = $id !== null ? (int) $id : null;
    }

    /**
     * Resolve a Minimax item id from a SKU.
     *
     * Uses the code({code}) route: the /items list has no Code filter, so
     * `?Code=x` would be ignored and the first item of the whole catalogue
     * returned — silently attaching the wrong item to an order row.
     */
    private function findItemIdByCode(string $sku, string $orgId): ?int
    {
        if ($sku === '') {
            return null;
        }

        if (array_key_exists($sku, $this->itemCache)) {
            return $this->itemCache[$sku];
        }

        $item = $this->fetchItemByCode($sku, $orgId);
        $id   = $item['ItemId'] ?? $item['ItemID'] ?? $item['ID'] ?? null;

        return $this->itemCache[$sku] = $id !== null ? (int) $id : null;
    }

    /**
     * Read one item by its exact code, or null when there is no such item.
     *
     * The code({code}) route is tried first, but it puts the code inside a URL
     * *path segment* and IIS rejects a good part of this catalogue there: a
     * percent-encoded slash ("11870/2") and a trailing dot-suffix that looks
     * like a file extension ("VELVET 100G B.02") both answer 404 at the web
     * server, before the API ever sees them. 301 of 3 637 codes are affected.
     *
     * So a 404 falls back to `?SearchString=`, which is a documented /items
     * filter and travels in the query string. SearchString matches loosely, so
     * the rows are re-checked for an exact Code before one is accepted —
     * returning a near-miss would attach the wrong item to an order row, which
     * is the very failure the code() route exists to prevent.
     *
     * @return array<string, mixed>|null
     */
    private function fetchItemByCode(string $sku, string $orgId): ?array
    {
        try {
            $data   = $this->get($this->codeUrl($orgId, 'items', $sku));
            $record = $this->rows($data)[0] ?? $data;

            if (isset($record['ItemId']) || isset($record['ItemID']) || isset($record['ID'])) {
                return $record;
            }
        } catch (\RuntimeException) {
            // Fall through to the search fallback below.
        }

        try {
            $data = $this->get($this->url($orgId, 'items', [
                'SearchString' => $sku,
                'PageSize'     => self::PAGE_SIZE,
                'CurrentPage'  => 1,
            ]));
        } catch (\RuntimeException) {
            return null;
        }

        foreach ($this->rows($data) as $row) {
            if (trim((string) ($row['Code'] ?? '')) === $sku) {
                return $row;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Warnings
    // -------------------------------------------------------------------------

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Drain accumulated non-fatal warnings so the caller can log them.
     *
     * @return list<string>
     */
    public function takeWarnings(): array
    {
        $warnings       = $this->warnings;
        $this->warnings = [];

        return $warnings;
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    private function get(string $url): array
    {
        $this->ensureAuthenticated();

        return $this->send('GET', $url, null);
    }

    private function post(string $url, array $body): array
    {
        $this->ensureAuthenticated();

        return $this->send('POST', $url, $body);
    }

    /**
     * Send a request, refreshing the token on 401 and backing off on 429/5xx.
     *
     * Only GETs are retried on a server-side error: a POST that may already have
     * been applied must never be replayed, or a flaky connection turns into
     * duplicate orders and duplicate customers.
     */
    private function send(string $method, string $url, ?array $body): array
    {
        $lastError = 'unknown error';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $options = [
                'headers' => array_filter([
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Accept'        => 'application/json',
                    'Content-Type'  => $body !== null ? 'application/json' : null,
                ]),
                'timeout' => 30.0,
            ];

            if ($body !== null) {
                $options['body'] = (string) json_encode($body);
            }

            try {
                $response   = $this->client->request($method, $url, $options);
                $statusCode = $response->getStatusCode();
                $data       = $response->toArray(false);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt < self::MAX_RETRIES && $method === 'GET') {
                    sleep($attempt);
                    continue;
                }

                throw new \RuntimeException("Minimax $method $url failed: $lastError", 0, $e);
            }

            if ($statusCode === 401 && $attempt < self::MAX_RETRIES) {
                $this->accessToken = '';
                $this->tokenExpiry = 0;
                $this->authenticate();
                continue;
            }

            if (($statusCode === 429 || $statusCode >= 500) && $attempt < self::MAX_RETRIES && $method === 'GET') {
                $lastError = "HTTP $statusCode";
                sleep($attempt * 2);
                continue;
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException(
                    "Minimax $method $url returned HTTP $statusCode: " . json_encode($data),
                );
            }

            return is_array($data) ? $data : [];
        }

        throw new \RuntimeException("Minimax $method $url failed after retries: $lastError");
    }

    // -------------------------------------------------------------------------
    // URL helpers
    // -------------------------------------------------------------------------

    private function url(string $orgId, string $resource, array $query = []): string
    {
        $url = $this->baseUrl() . 'api/orgs/' . rawurlencode($orgId) . '/' . $resource;

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    /** Single-record lookup route: api/orgs/{orgId}/{resource}/code({code}) */
    private function codeUrl(string $orgId, string $resource, string $code): string
    {
        return $this->url($orgId, $resource) . '/code(' . rawurlencode($code) . ')';
    }

    /**
     * Resolve a URL Minimax handed back to us into an absolute one.
     *
     * Record links such as a VatRate's `ResourceUrl` arrive relative to the API
     * root ("/api/orgs/239849/vatrates/36"). HttpClient rejects those outright
     * ("scheme is missing"), so they must be joined onto baseUrl() — which
     * already carries the locale path prefix (/si/API/) that a root-relative
     * resolve against the host would drop.
     */
    private function absoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return $this->baseUrl() . ltrim($url, '/');
    }

    /**
     * Unwrap a SearchResult: list endpoints answer { Rows: [...], TotalRows: n }.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $data): array
    {
        if (isset($data['Rows']) && is_array($data['Rows'])) {
            return $data['Rows'];
        }

        return isset($data[0]) && is_array($data[0]) ? $data : [];
    }

    private function clampPageSize(int $pageSize): int
    {
        return $pageSize > 0 ? min($pageSize, self::PAGE_SIZE) : self::PAGE_SIZE;
    }

    private function baseUrl(): string
    {
        $locale = $this->locale();

        return 'https://moj.minimax.' . $locale . '/' . $locale . '/API/';
    }

    private function locale(): string
    {
        $locale = $_SERVER['MINIMAX_LOCALE'] ?? $_ENV['MINIMAX_LOCALE'] ?? getenv('MINIMAX_LOCALE');

        return in_array($locale, ['si', 'rs', 'hr'], true) ? $locale : 'si';
    }
}
