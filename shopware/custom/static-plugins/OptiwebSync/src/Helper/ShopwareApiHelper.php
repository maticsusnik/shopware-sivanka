<?php declare(strict_types=1);

namespace OptiwebSync\Helper;

use Exception;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Closure;


class ShopwareApiHelper
{
    private HttpClientInterface $client;
    private string $token = '';
    private int $tokenTimeout = 0;
    private array $bulkData = [];

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    private function getToken(): void
    {
        if ($this->token !== '' && $this->tokenTimeout > time()) {
            return;
        }

        $url = EnvHelper::read("SHOPWARE_API_URL", self::class) . 'oauth/token';
        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => EnvHelper::read("SHOPWARE_API_ID", self::class),
            'client_secret' => EnvHelper::read("SHOPWARE_API_SECRET", self::class),
        ];

        try {
            $response = $this->client->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $payload,
                'timeout' => 10.0,
            ]);

            $data = $response->toArray();
            $this->token = $data['access_token'];
            $this->tokenTimeout = time() + 540;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Unable to fetch Shopware API token: ' . $e->getMessage());
        }
    }

    /**
     * @throws \Throwable
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     */
    private function request(string $endpoint, string $method = 'POST', ?string $body = null, array $headers = [], bool $sendToken = true, bool $queueIndexing = false, string $contentType = 'application/json'): array
    {
        if ($sendToken) {
            $this->getToken();
        }

        $url = EnvHelper::read("SHOPWARE_API_URL", self::class) . $endpoint;

        $defaultHeaders = [
            'Content-Type' => $contentType,
            'single-operation' => '1',
        ];

        if ($queueIndexing) {
            $defaultHeaders['indexing-behavior'] = 'use-queue-indexing';
        }

        if ($sendToken) {
            $defaultHeaders['Authorization'] = 'Bearer ' . $this->token;
        }

        $options = [
            'headers' => array_merge($defaultHeaders, $headers),
            'timeout' => 120.0,
        ];

        if (!empty($body)) {
            $options['body'] = $body;
        }

        try {
            $response = $this->client->request($method, $url, $options);
            $content = $response->getContent(false);
            $decoded = json_decode($content, true);

            if (isset($decoded['errors'])) {
                throw new \RuntimeException(
                    json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            }

            return ['status' => 'ok', 'response' => $decoded, 'error' => null];
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function upsertData(string $endpoint, array $data, bool $newEntry, string $id = ''): array
    {
        $json = json_encode($data);
        $url = $newEntry ? $endpoint : $endpoint . $id;
        $method = $newEntry ? 'POST' : 'PATCH';

        return $this->request($url, $method, $json);
    }

    public function bulkAddData(string $uniqueIdentifier, string $entity, string $action, array $payload, bool $queueIndexing = false): void
    {
        if (empty($payload)) {
            return;
        }

        $this->bulkData[][$uniqueIdentifier] = [
            'entity' => $entity,
            'action' => $action,
            'payload' => $payload,
        ];

        if (count($this->bulkData) >= GlobalVariables::BATCH_SIZE) {
            $this->bulkDataProcessQueue($queueIndexing);
        }
    }

    public function bulkDataProcessQueue(bool $queueIndexing = true): void
    {
        if (empty($this->bulkData)) {
            return;
        }

        $json = json_encode(array_merge(...$this->bulkData));
        if ($json !== false) {
            try{
                $this->request('_action/sync', 'POST', $json, [], true, $queueIndexing);
            }catch (\Throwable $e){
                $message = substr($e->getMessage(), 0, 500);
                throw new \RuntimeException('Unable to process data. Error: ' . $message);
            }
        }

        $this->bulkData = [];
    }

    public function readData(string $endpoint, string $data = '', string $method = 'POST'): array
    {
        $result = $this->request($endpoint, $method, $data);
        return $result['status'] === 'ok' && isset($result['response']['data'])
            ? (array) $result['response']['data']
            : [];
    }

    public function getShopwareEntryDetailedInformation(string $endpoint): array
    {
        $params = [];

        try {
            $data = $this->readData($endpoint, json_encode($params), 'GET');
        } catch (Exception $e) {
            $data = [];
        }

        return $data;
    }

    public function getShopwareEntries(string $endpoint, array $includes, Closure $func, array $filter = []): array
    {
        $entries = [];
        $page = 1;
        $keepFetching = true;

        while ($keepFetching) {
            $params = [
                'limit' => GlobalVariables::BATCH_SIZE,
                'page' => $page,
            ];

            if (!empty($includes)) {
                $params['includes'] = [$endpoint => $includes];
            }

            if (!empty($filter)) {
                $params['filter'] = [$filter];
            }

            $json = json_encode($params);
            try {
                $data = $this->readData('search/' . $endpoint, $json);
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

        $returnArray = [];
        foreach ($entries as $entry) {
            if($this->hasSequentialKeys($entry)){
                foreach ($entry as $v) {
                    $returnArray[] = $v;
                }
            }else {
                foreach ($entry as $k => $v) {
                    if (!isset($returnArray[$k])) {
                        $returnArray[$k] = $v;
                    } else {
                        $returnArray[$k] += $v;
                    }
                }
            }


        }


        return $returnArray;
    }

    private function hasSequentialKeys(array $array): bool
    {
        return array_keys($array) === range(0, count($array) - 1);
    }

    public function getShopwareId(string $endpoint, array $filter, int $limit = 1, array $sort = []): string
    {
        $params = [
            'limit' => $limit,
            'filter' => $filter,
        ];

        if (!empty($sort)) {
            $params['sort'] = $sort;
        }

        try {
            $data = $this->readData('search-ids/' . $endpoint, json_encode($params));
            return $data[0] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function getDefaultSalesChannelId(): string
    {
        return $this->getShopwareId('sales-channel', [
            [
                'type' => 'equals',
                'field' => 'name',
                'value' => GlobalVariables::DEFAULT_SALES_CHANNEL,
            ],
        ]);
    }

    public function getDefaultCurrencyId(): string
    {
        return $this->getShopwareId(
            'currency',
            [[
                'type' => 'equals',
                'field' => 'isoCode',
                'value' => 'EUR',
            ]]
        );
    }

    public function getDefaultTaxId(): string
    {
        return $this->getShopwareId(
            'tax',
            [[
                 'type' => 'range',
                 'field' => 'position',
                 'parameters' => ['gt' => 0],
             ]],
            1,
            [['field' => 'position']]
        );
    }

    public function uploadMedia(string $mediaId, string $mediaName, string $mediaExtension, string $mediaPath, ?string $image = null, ?string $contentType = null): void
    {
        $binary = $image ?? file_get_contents($mediaPath);
        $mime = $contentType ?? mime_content_type($mediaPath);

        $urlParams = [
            'extension' => $mediaExtension,
            'fileName' => $mediaName,
        ];

        $url = ApiHelper::addUrlParameters('_action/media/' . $mediaId . '/upload', $urlParams);

        $this->request($url, 'POST', $binary, [], true, false, $mime);
    }

    public function updateOrderState(string $type, string $id, string $state, bool $sendMail = false): void
    {
        $typeMap = [
            'order' => 'order',
            'transaction' => 'order_transaction',
            'delivery' => 'order_delivery',
        ];

        $mappedType = $typeMap[$type] ?? null;
        if (!$mappedType) {
            return;
        }

        $url = ApiHelper::addUrlParameters('_action/' . $mappedType . '/' . $id . '/state/' . $state, []);
        $this->request($url, 'POST', json_encode(['sendMail' => $sendMail]));
    }

    public function getRelationships(array $data, array $relationships): array
    {
        $result = [];
        foreach ($relationships as $relation) {
            $api = $data[$relation]['links']['related'];
            $path = str_replace(EnvHelper::read("SHOPWARE_API_URL", self::class), '', $api);
            $result[$relation] = $this->readData($path, '', 'GET');
        }
        return $result;
    }

    public function getSingleRelationship(array $data, string $relationship): array
    {
        $api = $data['relationships'][$relationship]['links']['related'];
        $path = str_replace(EnvHelper::read("SHOPWARE_API_URL", self::class), '', $api);
        return $this->readData($path, '', 'GET');
    }
}
