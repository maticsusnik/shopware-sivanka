<?php

namespace OptiwebSync\Helper;

use Exception;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApiHelper
{
    private HttpClientInterface $client;
    private string $token;
    private array $vascoToken;
    private string $pimToken;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
        //$this->pimToken = EnvHelper::read('PIM_SYNC_API_TOKEN', self::class);
        $this->pimToken = "";
        $this->vascoToken = ['value' => '', 'expiration' => time()];
    }

    /**
     * Add query parameters to a URL
     */
    public static function addUrlParameters(string $url, array $params): string
    {
        return $url . '?' . http_build_query($params);
    }

    /**
     * GET API call and extract specific array key
     */
    public function callApi(string $url, string $arrayKey, string $origin): array
    {
        $this->token = $this->pimToken;

        if($origin == 'vasco') {
            if( $this->vascoToken['value'] == '' || $this->vascoToken['expiration'] <= time()){
                try {
                    $tokenData = $this->vascoGenerateToken();
                    $this->token = $tokenData['value'];
                } catch (\Exception $e) {
                    return [
                        'status' => 'error',
                        'response' => null,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        try {
            $response = $this->client->request('GET', $url, $this->getDefaultOptions());
            $data = $response->toArray();

            return [
                'status' => 'ok',
                'response' => $data[$arrayKey] ?? $data,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'response' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * POST JSON data to API
     */
    public function sendDataToApi(string $url, string $data, string $origin = ""): array
    {

        if($origin == 'vasco') {
            if( $this->vascoToken['value'] == '' || $this->vascoToken['expiration'] <= time()){
                try {
                    $tokenData = $this->vascoGenerateToken();
                    $this->token = $tokenData['value'];
                } catch (\Exception $e) {
                    return [
                        'status' => 'error',
                        'response' => null,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        try {
            $response = $this->client->request('POST', $url, array_merge(
                $this->getDefaultOptions(),
                [
                    'headers' => array_merge(
                        $this->getDefaultOptions()['headers'],
                        ['Content-Type' => 'application/json']
                    ),
                    'body' => $data,
                ]
            ));

            return [
                'status' => 'ok',
                'response' => $response->getContent(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'response' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * GET external URL and return raw response with headers
     */
    public function externalUrlCall(string $url): array
    {
        try {
            $response = $this->client->request('GET', $url, [
                'timeout' => 30,
            ]);

            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false); // No exception on error status

            unset($response);
            gc_collect_cycles();

            if ($status !== 200) {
                return [
                    'status' => 'error',
                    'response' => null,
                    'error' => "Bad status code: $status",
                ];
            }

            return [
                'status' => 'ok',
                'response' => [
                    'headers' => $headers,
                    'body' => $body,
                ],
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'response' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Common HTTP options with Bearer token and timeout
     */
    private function getDefaultOptions(): array
    {
        return [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
            ],
            'timeout' => 30.0,
        ];
    }

    /**
     * @throws Exception
     */
    private function vascoGenerateToken(): array
    {
        $url = EnvHelper::read('VASCO_URL', self::class) . 'Avtentikacija';

        $payload = [
            'username' => EnvHelper::read('VASCO_USER', self::class),
            'password' => EnvHelper::read('VASCO_PASS', self::class),
            'taxNumber' => EnvHelper::read('VASCO_DAVCNA', self::class),
        ];

        try {
            $response = $this->client->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
                'timeout' => 5,
            ]);

            $content = $response->getContent(); // This throws on non-2xx
            $data = json_decode($content);

            if (!isset($data->apiKey)) {
                throw new \Exception("Missing apiKey in response");
            }

            $expiration = isset($data->expiration) ? strtotime($data->expiration) : time();

            return [
                'value' => $data->apiKey,
                'expiration' => $expiration,
            ];

        } catch (TransportExceptionInterface|\Throwable $e) {
            // You can log or rethrow more detailed error info here
            throw new \Exception('Vasco token request failed: ' . $e->getMessage(), 0, $e);
        }
    }


}
