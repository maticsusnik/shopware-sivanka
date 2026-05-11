<?php declare(strict_types=1);

namespace OptiwebSync\Client;

use Closure;
use Exception;
use OptiwebSync\Helper\ApiHelper;
use OptiwebSync\Helper\EnvHelper;
use OptiwebSync\Helper\GlobalVariables;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ShopwareClient implements ClientInterface
{
    private string $token = '';
    private int $tokenTimeout = 0;
    private array $bulkData = [];

    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    private function getToken(): void
    {
        if ($this->token !== '' && $this->tokenTimeout > time()) {
            return;
        }

        $url = EnvHelper::read('SHOPWARE_API_URL', self::class) . 'oauth/token';
        $payload = [
            'grant_type'    => 'client_credentials',
            'client_id'     => EnvHelper::read('SHOPWARE_API_ID', self::class),
            'client_secret' => EnvHelper::read('SHOPWARE_API_SECRET', self::class),
        ];

        try {
            $response = $this->client->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => $payload,
                'timeout' => 10.0,
            ]);

            $data = $response->toArray();

            if (empty($data['access_token'])) {
                throw new \RuntimeException('Shopware auth response missing access_token');
            }

            $this->token        = $data['access_token'];
            $this->tokenTimeout = time() + 540;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Shopware authentication failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Core HTTP method with retry on 5xx and automatic token refresh on 401.
     */
    private function request(
        string $endpoint,
        string $method = 'POST',
        ?string $body = null,
        array $headers = [],
        bool $sendToken = true,
        bool $queueIndexing = false,
        string $contentType = 'application/json',
    ): array {
        if ($sendToken) {
            $this->getToken();
        }

        $url = EnvHelper::read('SHOPWARE_API_URL', self::class) . $endpoint;

        $defaultHeaders = [
            'Content-Type'     => $contentType,
            'single-operation' => '1',
        ];

        if ($queueIndexing) {
            $defaultHeaders['indexing-behavior'] = 'use-queue-indexing';
        }

        if ($sendToken) {
            $defaultHeaders['Authorization'] = 'Bearer ' . $this->token;
        }

        $isBulk  = str_contains($endpoint, '_action/sync');
        $timeout = $isBulk ? 120.0 : 30.0;

        $options = [
            'headers' => array_merge($defaultHeaders, $headers),
            'timeout' => $timeout,
        ];

        if (!empty($body)) {
            $options['body'] = $body;
        }

        $retryableCodes = [500, 502, 503, 504];
        $lastError      = null;
        $lastStatusCode = 0;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response   = $this->client->request($method, $url, $options);
                $statusCode = $response->getStatusCode();

                // Refresh token on 401 and retry once
                if ($statusCode === 401 && $sendToken) {
                    $this->token        = '';
                    $this->tokenTimeout = 0;
                    $this->getToken();
                    $options['headers']['Authorization'] = 'Bearer ' . $this->token;
                    $response   = $this->client->request($method, $url, $options);
                    $statusCode = $response->getStatusCode();
                }

                if (in_array($statusCode, $retryableCodes, true)) {
                    $lastStatusCode = $statusCode;
                    $lastError      = $this->describeStatus($statusCode);
                    if ($attempt < 3) {
                        sleep($attempt);
                        continue;
                    }
                }

                $content = $response->getContent(false);
                $decoded = json_decode($content, true);

                if ($statusCode >= 400) {
                    $detail = $decoded['errors'] ?? $decoded['message'] ?? $content;
                    if (is_array($detail)) {
                        $detail = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    return [
                        'status'     => 'error',
                        'response'   => null,
                        'error'      => $this->describeStatus($statusCode) . ': ' . $detail,
                        'statusCode' => $statusCode,
                    ];
                }

                if (isset($decoded['errors'])) {
                    return [
                        'status'     => 'error',
                        'response'   => null,
                        'error'      => json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'statusCode' => $statusCode,
                    ];
                }

                return ['status' => 'ok', 'response' => $decoded, 'error' => null, 'statusCode' => $statusCode];
            } catch (\Throwable $e) {
                $lastError      = $e->getMessage();
                $lastStatusCode = 0;
                if ($attempt < 3) {
                    sleep($attempt);
                }
            }
        }

        return ['status' => 'error', 'response' => null, 'error' => $lastError, 'statusCode' => $lastStatusCode];
    }

    private function describeStatus(int $statusCode): string
    {
        return match (true) {
            $statusCode === 400 => 'HTTP 400 Validation error',
            $statusCode === 401 => 'HTTP 401 Authentication failed',
            $statusCode === 403 => 'HTTP 403 Forbidden',
            $statusCode === 404 => 'HTTP 404 Not found',
            $statusCode === 429 => 'HTTP 429 Rate limit exceeded',
            $statusCode >= 500  => "HTTP $statusCode Server error",
            default             => "HTTP $statusCode",
        };
    }

    public function upsertData(string $endpoint, array $data, bool $newEntry, string $id = ''): array
    {
        $url    = $newEntry ? $endpoint : $endpoint . $id;
        $method = $newEntry ? 'POST' : 'PATCH';

        return $this->request($url, $method, (string) json_encode($data));
    }

    public function bulkAddData(string $uniqueIdentifier, string $entity, string $action, array $payload, bool $queueIndexing = false): void
    {
        if (empty($payload)) {
            return;
        }

        $this->bulkData[][$uniqueIdentifier] = [
            'entity'  => $entity,
            'action'  => $action,
            'payload' => $payload,
        ];

        if (count($this->bulkData) >= GlobalVariables::BATCH_SIZE) {
            $this->bulkDataProcessQueue($queueIndexing);
        }
    }

    public function bulkDataProcessQueue(bool $queueIndexing = false): void
    {
        if (empty($this->bulkData)) {
            return;
        }

        $json           = json_encode(array_merge(...$this->bulkData));
        $this->bulkData = [];

        if ($json === false) {
            throw new \RuntimeException('ShopwareClient: failed to JSON-encode bulk data');
        }

        $result = $this->request('_action/sync', 'POST', $json, [], true, $queueIndexing);

        if ($result['status'] === 'error') {
            throw new \RuntimeException('ShopwareClient bulk sync error: ' . ($result['error'] ?? 'unknown'));
        }
    }

    public function readData(string $endpoint, string $data = '', string $method = 'POST'): array
    {
        $result = $this->request($endpoint, $method, $data ?: null);

        return $result['status'] === 'ok' && isset($result['response']['data'])
            ? (array) $result['response']['data']
            : [];
    }

    public function getShopwareEntries(string $endpoint, array $includes, Closure $func, array $filter = []): array
    {
        $entries      = [];
        $page         = 1;
        $keepFetching = true;

        while ($keepFetching) {
            $params = [
                'limit' => GlobalVariables::BATCH_SIZE,
                'page'  => $page,
            ];

            if (!empty($includes)) {
                $params['includes'] = [$endpoint => $includes];
            }

            if (!empty($filter)) {
                $params['filter'] = [$filter];
            }

            try {
                $data = $this->readData('search/' . $endpoint, (string) json_encode($params));
            } catch (\Throwable) {
                $data = [];
            }

            $entries[] = $func($data);

            if (count($data) < GlobalVariables::BATCH_SIZE) {
                $keepFetching = false;
            } else {
                $page++;
            }
        }

        $result = [];
        foreach ($entries as $entry) {
            if (array_keys($entry) === range(0, count($entry) - 1)) {
                foreach ($entry as $v) {
                    $result[] = $v;
                }
            } else {
                foreach ($entry as $k => $v) {
                    if (!isset($result[$k])) {
                        $result[$k] = $v;
                    } else {
                        $result[$k] += $v;
                    }
                }
            }
        }

        return $result;
    }

    public function getShopwareId(string $endpoint, array $filter, int $limit = 1, array $sort = []): string
    {
        $params = [
            'limit'  => $limit,
            'filter' => $filter,
        ];

        if (!empty($sort)) {
            $params['sort'] = $sort;
        }

        try {
            $data = $this->readData('search-ids/' . $endpoint, (string) json_encode($params));

            return $data[0] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function getDefaultSalesChannelId(): string
    {
        return $this->getShopwareId('sales-channel', [[
            'type'  => 'equals',
            'field' => 'name',
            'value' => GlobalVariables::DEFAULT_SALES_CHANNEL,
        ]]);
    }

    public function getDefaultCurrencyId(): string
    {
        return $this->getShopwareId('currency', [[
            'type'  => 'equals',
            'field' => 'isoCode',
            'value' => 'EUR',
        ]]);
    }

    public function getDefaultTaxId(): string
    {
        return $this->getShopwareId(
            'tax',
            [[
                'type'       => 'range',
                'field'      => 'position',
                'parameters' => ['gt' => 0],
            ]],
            1,
            [['field' => 'position']],
        );
    }

    public function uploadMedia(
        string $mediaId,
        string $mediaName,
        string $mediaExtension,
        string $mediaPath,
        ?string $image = null,
        ?string $contentType = null,
    ): void {
        $binary = $image ?? (string) file_get_contents($mediaPath);
        $mime   = $contentType ?? (string) mime_content_type($mediaPath);

        $url = ApiHelper::addUrlParameters('_action/media/' . $mediaId . '/upload', [
            'extension' => $mediaExtension,
            'fileName'  => $mediaName,
        ]);

        $this->request($url, 'POST', $binary, [], true, false, $mime);
    }

    public function updateOrderState(string $type, string $id, string $state, bool $sendMail = false): void
    {
        $typeMap = [
            'order'       => 'order',
            'transaction' => 'order_transaction',
            'delivery'    => 'order_delivery',
        ];

        $mappedType = $typeMap[$type] ?? null;
        if ($mappedType === null) {
            return;
        }

        $url = ApiHelper::addUrlParameters('_action/' . $mappedType . '/' . $id . '/state/' . $state, []);
        $this->request($url, 'POST', (string) json_encode(['sendMail' => $sendMail]));
    }

    public function getRelationships(array $data, array $relationships): array
    {
        $result = [];
        foreach ($relationships as $relation) {
            $api    = $data[$relation]['links']['related'];
            $path   = str_replace(EnvHelper::read('SHOPWARE_API_URL', self::class), '', $api);
            $result[$relation] = $this->readData($path, '', 'GET');
        }

        return $result;
    }

    public function getSingleRelationship(array $data, string $relationship): array
    {
        $api  = $data['relationships'][$relationship]['links']['related'];
        $path = str_replace(EnvHelper::read('SHOPWARE_API_URL', self::class), '', $api);

        return $this->readData($path, '', 'GET');
    }
}
