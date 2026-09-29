<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * The only place that talks HTTP to OpManager. The API key travels as the
 * `apiKey` query parameter, so every message that could contain a URL is passed
 * through redact() before it leaves this class (Review Focus 3).
 */
final class Client implements DeviceSource
{
    public const DEVICE_LIST_PATH = '/api/json/device/listDevices';

    /** @var callable(string, array<string, string>): array{status: int, body: string} */
    private $transport;

    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private bool $verifySsl = true,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? $this->guzzleTransport(...);
    }

    public function listDevices(): array
    {
        return DeviceParser::parse($this->get(self::DEVICE_LIST_PATH));
    }

    public static function redact(string $text): string
    {
        return (string) preg_replace('/(apiKey=)[^&\s"\']+/i', '$1***', $text);
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): string
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new ClientException('OpManager URL or API key is not configured');
        }
        try {
            $response = ($this->transport)(rtrim($this->baseUrl, '/') . $path, ['apiKey' => $this->apiKey] + $query);
        } catch (ClientException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ClientException(self::redact(sprintf('OpManager %s failed: %s', $path, $e->getMessage())));
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ClientException(sprintf('OpManager %s answered HTTP %d', $path, $response['status']));
        }
        return $response['body'];
    }

    /**
     * @param array<string, string> $query
     * @return array{status: int, body: string}
     */
    private function guzzleTransport(string $url, array $query): array
    {
        $http = new \GuzzleHttp\Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => $this->verifySsl,
            'http_errors'     => false,
        ]);
        $response = $http->request('GET', $url, ['query' => $query]);
        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }
}
