<?php declare(strict_types=1);

namespace OptiwebSync\Client;

use OptiwebSync\Helper\EnvHelper;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimax REST API client.
 *
 * Authentication: OAuth 2.0 password grant
 * Docs: https://help.minimax.si/help/api-navodila-za-razvijalce
 *
 * Required env vars:
 *   MINIMAX_CLIENT_ID, MINIMAX_CLIENT_SECRET, MINIMAX_USERNAME, MINIMAX_PASSWORD
 * Optional:
 *   MINIMAX_LOCALE  (default: si)  — one of: si, rs, hr
 */
class MinimaxClient implements ClientInterface
{
    private const PAGE_SIZE = 100;

    private const ORG_ID = '239849';

    private string $accessToken = '';
    private int    $tokenExpiry = 0;
    private string $orgId       = self::ORG_ID;

    // Lookup caches — populated on first use, reset on token refresh
    private array $vatRateCache  = [];  // (string)percent → int ID
    private array $currencyCache = [];  // isoCode → int ID
    private array $countryCache  = [];  // isoCode → int|null ID
    private array $itemCache     = [];  // SKU → int ID
    private array $stockMap      = [];  // itemId → float quantity (lazy-loaded once per session)
    private bool  $stockMapLoaded = false;

    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Fetch all items/products from Minimax, paginated.
     *
     * @return array<int, array{id: int, sku: string, name: string, price: float, stock: float}>
     */
    public function getAllProducts(): array
    {
        $this->ensureAuthenticated();
        $orgId    = $this->getOrganizationId();
        $baseUrl  = $this->baseUrl();
        $products = [];
        $page     = 1;

        do {
            $url  = $baseUrl . 'api/orgs/' . $orgId . '/items?PageSize=' . self::PAGE_SIZE . '&CurrentPage=' . $page . '&SortField=ItemId&Order=A';
            $data = $this->get($url);

            // Minimax wraps list results in a 'Rows' key; fall back to direct array
            $rows = $data['Rows'] ?? (isset($data[0]) ? $data : []);

            foreach ($rows as $item) {
                $normalized = $this->normalizeProduct($item);
                if ($normalized['sku'] !== '') {
                    // Populate item cache while we have the data
                    if ($normalized['id'] > 0) {
                        $this->itemCache[$normalized['sku']] = $normalized['id'];
                    }
                    $products[] = $normalized;
                }
            }

            $page++;
        } while (count($rows) === self::PAGE_SIZE);

        // Merge real stock quantities from the dedicated stocks endpoint
        $stockMap = $this->getStockMap($orgId);
        foreach ($products as &$product) {
            $product['stock'] = $stockMap[$product['sku']] ?? 0;
        }
        unset($product);

        return $products;
    }

    /**
     * Fetch a single page of items with stock merged in.
     * Stock map is loaded once per session and cached.
     *
     * @return array<int, array{id: int, sku: string, name: string, price: float, stock: float}>
     */
    public function getProductsPage(int $page, int $pageSize): array
    {
        $this->ensureAuthenticated();
        $orgId = $this->getOrganizationId();

        $url  = $this->baseUrl() . 'api/orgs/' . $orgId . '/items?PageSize=' . $pageSize . '&CurrentPage=' . $page . '&SortField=ItemId&Order=A';
        $data = $this->get($url);
        $rows = $data['Rows'] ?? (isset($data[0]) ? $data : []);

        $stockMap = $this->getStockMap($orgId);
        $products = [];

        foreach ($rows as $item) {
            $normalized = $this->normalizeProduct($item);
            if ($normalized['sku'] !== '') {
                if ($normalized['id'] > 0) {
                    $this->itemCache[$normalized['sku']] = $normalized['id'];
                }
                $normalized['stock'] = $stockMap[$normalized['sku']] ?? 0;
                $products[] = $normalized;
            }
        }

        return $products;
    }

    /**
     * Fetch all stock quantities for the organisation (cached per session).
     *
     * @return array<int, float>  itemId → quantity
     */
    private function getStockMap(string $orgId): array
    {
        if ($this->stockMapLoaded) {
            return $this->stockMap;
        }

        $this->loadStockPage($orgId, 1);
        $this->stockMapLoaded = true;

        return $this->stockMap;
    }

    private function loadStockPage(string $orgId, int $page): void
    {
        $url  = $this->baseUrl() . 'api/orgs/' . $orgId . '/stocks?PageSize=' . self::PAGE_SIZE . '&CurrentPage=' . $page;
        $data = $this->get($url);
        $rows = $data['Rows'] ?? (isset($data[0]) ? $data : []);

        foreach ($rows as $row) {
            $itemId   = $row['ItemCode'];
            $quantity = (int) ($row['Quantity'] ?? $row['TotalQuantity'] ?? $row['StockQuantity'] ?? 0.0);

            if ($quantity > 0) {
                $this->stockMap[$itemId] = $quantity;
            }
        }

        if (count($rows) === self::PAGE_SIZE) {
            $this->loadStockPage($orgId, $page + 1);
        }
    }

    /**
     * Export a Shopware order to Minimax as an issued invoice.
     *
     * Input array shape:
     *   customer: [name, code, address, postalCode, city, countryIso, vatId, subjectToVat]
     *   date:     string (ISO datetime or YYYY-MM-DD)
     *   note:     string
     *   lines:    [[sku, quantity, unitPriceNet, vatPercent], ...]
     *
     * Returns ['status'=>'ok','invoiceId'=>int] or ['status'=>'error','error'=>string].
     */
    public function createOrder(array $orderData): array
    {
        try {
            $this->ensureAuthenticated();
            $orgId = $this->getOrganizationId();

            $customerId = $this->findOrCreateCustomer($orderData['customer'], $orgId);
            $currencyId = $this->resolveCurrencyId('EUR', $orgId);

            $invoiceRows = [];
            foreach ($orderData['lines'] as $line) {
                $sku    = (string) ($line['sku'] ?? '');
                $itemId = $this->findItemIdByCode($sku, $orgId);

                if ($itemId === null) {
                    error_log('[MinimaxClient] createOrder: SKU "' . $sku . '" not found in Minimax — line skipped');
                    continue;
                }

                $vatRateId = $this->resolveVatRateId((float) ($line['vatPercent'] ?? 22.0), $orgId);

                $invoiceRows[] = [
                    'Item'     => ['ID' => $itemId],
                    'Quantity' => (float) ($line['quantity'] ?? 1),
                    'Price'    => (float) ($line['unitPriceNet'] ?? 0),
                    'VatRate'  => ['ID' => $vatRateId],
                ];
            }

            if (empty($invoiceRows)) {
                throw new \RuntimeException('No valid invoice rows — all SKUs missing in Minimax');
            }

            $date = (new \DateTime($orderData['date'] ?? 'now'))->format('Y-m-d');

            $payload = [
                'Customer'        => ['ID' => $customerId],
                'DateIssued'      => $date,
                'DateTransaction' => $date,
                'Currency'        => ['ID' => $currencyId],
                'Status'          => 'O',
                'InvoiceType'     => 'R',
                'Note'            => $orderData['note'] ?? '',
                'InvoiceRows'     => $invoiceRows,
            ];

            $url      = $this->baseUrl() . 'api/orgs/' . $orgId . '/issuedinvoices';
            $response = $this->post($url, $payload);

            $invoiceId = $response['IssuedInvoiceID'] ?? $response['ID'] ?? null;

            if ($invoiceId === null) {
                throw new \RuntimeException(
                    'Minimax invoice created but no ID in response: ' . json_encode($response),
                );
            }

            return ['status' => 'ok', 'invoiceId' => $invoiceId];

        } catch (\RuntimeException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
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
        $expiresIn         = (int) ($data['expires_in'] ?? 3600);
        $this->tokenExpiry = time() + $expiresIn;

        // Reset all caches on new token
        $this->orgId          = self::ORG_ID;
        $this->vatRateCache   = [];
        $this->currencyCache  = [];
        $this->countryCache   = [];
        $this->itemCache      = [];
        $this->stockMap       = [];
        $this->stockMapLoaded = false;
    }

    private function getOrganizationId(): string
    {
        if ($this->orgId !== '') {
            return $this->orgId;
        }

        $url  = $this->baseUrl() . 'api/currentuser/orgs';
        $orgs = $this->get($url);

        if (empty($orgs)) {
            throw new \RuntimeException('Minimax: no organisations found for this user');
        }

        $first = $orgs[0] ?? [];
        $id    = (string) ($first['OrganisationId'] ?? $first['Id'] ?? '');

        if ($id === '') {
            throw new \RuntimeException(
                'Minimax: unable to determine organisation ID from response: ' . json_encode($first),
            );
        }

        $this->orgId = $id;

        return $this->orgId;
    }

    // -------------------------------------------------------------------------
    // Customer management
    // -------------------------------------------------------------------------

    private function findOrCreateCustomer(array $customer, string $orgId): int
    {
        $code     = (string) ($customer['code'] ?? '');
        $existing = $this->searchCustomerByCode($code, $orgId);

        if ($existing !== null) {
            return $existing;
        }

        return $this->createCustomer($customer, $orgId);
    }

    private function searchCustomerByCode(string $code, string $orgId): ?int
    {
        if ($code === '') {
            return null;
        }

        try {
            $url  = $this->baseUrl() . 'api/orgs/' . $orgId . '/customers?Code=' . urlencode($code);
            $data = $this->get($url);
        } catch (\RuntimeException) {
            return null;
        }

        $rows = $data['Rows'] ?? (isset($data[0]) ? $data : []);
        $first = $rows[0] ?? null;

        if ($first === null) {
            return null;
        }

        $id = $first['CustomerID'] ?? $first['ID'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    private function createCustomer(array $customer, string $orgId): int
    {
        $currencyId = $this->resolveCurrencyId('EUR', $orgId);
        $countryId  = $this->resolveCountryId($customer['countryIso'] ?? 'SI', $orgId);

        $payload = [
            'Name'              => $customer['name'] ?? '',
            'Code'              => $customer['code'] ?? '',
            'Address'           => $customer['address'] ?? '',
            'PostalCode'        => $customer['postalCode'] ?? '',
            'City'              => $customer['city'] ?? '',
            'Currency'          => ['ID' => $currencyId],
            'SubjectToVAT'      => ($customer['subjectToVat'] ?? false) ? 'Y' : 'N',
            'EInvoiceIssuing'   => 'SeNePripravlja',
        ];

        if ($countryId !== null) {
            $payload['Country'] = ['ID' => $countryId];
        }

        if (!empty($customer['vatId'])) {
            $payload['TaxNumber'] = $customer['vatId'];
        }

        $url      = $this->baseUrl() . 'api/orgs/' . $orgId . '/customers';
        $response = $this->post($url, $payload);

        $id = $response['CustomerID'] ?? $response['ID'] ?? null;

        if ($id === null) {
            throw new \RuntimeException(
                'Minimax customer created but no ID in response: ' . json_encode($response),
            );
        }

        return (int) $id;
    }

    // -------------------------------------------------------------------------
    // Reference data resolution (cached)
    // -------------------------------------------------------------------------

    private function resolveCurrencyId(string $isoCode, string $orgId): int
    {
        if (isset($this->currencyCache[$isoCode])) {
            return $this->currencyCache[$isoCode];
        }

        $url  = $this->baseUrl() . "api/orgs/$orgId/currencies/code('$isoCode')";
        $data = $this->get($url);

        $id = $data['CurrencyID'] ?? $data['ID'] ?? null;

        if ($id === null) {
            throw new \RuntimeException("Minimax: currency '$isoCode' not found");
        }

        $this->currencyCache[$isoCode] = (int) $id;

        return $this->currencyCache[$isoCode];
    }

    /**
     * Resolve Minimax VAT rate ID by percentage.
     * Fetches the full VAT rate list once per session and caches by percent string key.
     */
    private function resolveVatRateId(float $percent, string $orgId): int
    {
        $key = number_format($percent, 2);

        if (isset($this->vatRateCache[$key])) {
            return $this->vatRateCache[$key];
        }

        // Populate the full cache on first call
        if (empty($this->vatRateCache)) {
            $url   = $this->baseUrl() . "api/orgs/$orgId/vatrates";
            $data  = $this->get($url);
            $rates = $data['Rows'] ?? (isset($data[0]) ? $data : []);

            foreach ($rates as $rate) {
                $ratePercent = number_format((float) ($rate['TaxRate'] ?? $rate['TaxRateValue'] ?? 0), 2);
                $rateId      = $rate['TaxRateID'] ?? $rate['ID'] ?? null;

                if ($rateId !== null) {
                    $this->vatRateCache[$ratePercent] = (int) $rateId;
                }
            }
        }

        if (!isset($this->vatRateCache[$key])) {
            throw new \RuntimeException("Minimax: VAT rate $percent% not found");
        }

        return $this->vatRateCache[$key];
    }

    private function resolveCountryId(string $isoCode, string $orgId): ?int
    {
        if (array_key_exists($isoCode, $this->countryCache)) {
            return $this->countryCache[$isoCode];
        }

        try {
            $url  = $this->baseUrl() . "api/orgs/$orgId/countries/code('$isoCode')";
            $data = $this->get($url);
        } catch (\RuntimeException) {
            $this->countryCache[$isoCode] = null;

            return null;
        }

        $id = $data['CountryID'] ?? $data['ID'] ?? null;
        $this->countryCache[$isoCode] = $id !== null ? (int) $id : null;

        return $this->countryCache[$isoCode];
    }

    private function findItemIdByCode(string $sku, string $orgId): ?int
    {
        if ($sku === '') {
            return null;
        }

        if (isset($this->itemCache[$sku])) {
            return $this->itemCache[$sku];
        }

        try {
            $url  = $this->baseUrl() . 'api/orgs/' . $orgId . '/items?Code=' . urlencode($sku);
            $data = $this->get($url);
        } catch (\RuntimeException) {
            return null;
        }

        $rows  = $data['Rows'] ?? (isset($data[0]) ? $data : []);
        $first = $rows[0] ?? null;

        if ($first === null) {
            return null;
        }

        $id = $first['ItemID'] ?? $first['ID'] ?? null;

        if ($id === null) {
            return null;
        }

        $this->itemCache[$sku] = (int) $id;

        return $this->itemCache[$sku];
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    private function get(string $url): array
    {
        try {
            $response   = $this->client->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Accept'        => 'application/json',
                ],
                'timeout' => 30.0,
            ]);
            $statusCode = $response->getStatusCode();
            $data       = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Minimax GET ' . $url . ' failed: ' . $e->getMessage(), 0, $e);
        }

        if ($statusCode >= 400) {
            $detail = is_array($data) ? json_encode($data) : (string) $data;
            throw new \RuntimeException("Minimax GET $url returned HTTP $statusCode: $detail");
        }

        return $data;
    }

    private function post(string $url, array $body): array
    {
        $this->ensureAuthenticated();

        try {
            $response   = $this->client->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body'    => (string) json_encode($body),
                'timeout' => 30.0,
            ]);
            $statusCode = $response->getStatusCode();
            $data       = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Minimax POST ' . $url . ' failed: ' . $e->getMessage(), 0, $e);
        }

        if ($statusCode >= 400) {
            $detail = is_array($data) ? json_encode($data) : (string) $data;
            throw new \RuntimeException("Minimax POST $url returned HTTP $statusCode: $detail");
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Product normalisation
    // -------------------------------------------------------------------------

    /**
     * Map a raw Minimax item to a simple product array.
     *
     * Field names are based on Minimax SI API conventions.
     * Verify against an actual API response and adjust as needed.
     *
     * Typical fields:
     *   ItemId / ID  → id (Minimax internal ID, used for order lines)
     *   Code         → sku
     *   Name / Title → name
     *   Price        → price (gross; Minimax stores gross for SI market)
     *   stock        → populated separately via getStockMap()
     */
    private function normalizeProduct(array $item): array
    {
        $id    = (int)    ($item['ItemId'] ?? $item['ItemID'] ?? $item['ID'] ?? 0);
        $sku   = (string) ($item['Code'] ?? $item['ItemCode'] ?? '');
        $name  = (string) ($item['Title'] ?? $item['Name'] ?? '');
        $price = (float)  ($item['Price'] ?? $item['SalePrice'] ?? $item['RetailPrice'] ?? 0.0);

        return ['id' => $id, 'sku' => $sku, 'name' => $name, 'price' => $price, 'stock' => 0.0];
    }

    // -------------------------------------------------------------------------
    // URL helpers
    // -------------------------------------------------------------------------

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
