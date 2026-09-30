<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * The only place that talks HTTP to OpManager. The API key travels ONLY in the
 * `apiKey` header (query-parameter auth is deprecated since OpManager 12.8.100 and
 * listAlarms is header-only, spec §14). redact() still scrubs any message that
 * could embed a URL.
 */
final class Client implements DeviceSource, AlarmSource
{
    public const DEVICE_LIST_PATH = '/api/json/device/listDevices';
    public const ALARM_LIST_PATH  = '/api/json/alarm/listAlarms';

    /** @var callable(string, array<string, string>, array<string, string>): array{status: int, body: string} */
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

    public function listActiveAlarms(): array
    {
        return AlarmParser::parse($this->get(self::ALARM_LIST_PATH, ['alertType' => 'ActiveAlarms']));
    }

    public function alarmByEntity(string $entity): ?array
    {
        foreach (AlarmParser::parse($this->get(self::ALARM_LIST_PATH, ['entity' => $entity])) as $alarm) {
            if ($alarm['entity_key'] === $entity) {
                return $alarm;
            }
        }
        return null;
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
            $response = ($this->transport)(rtrim($this->baseUrl, '/') . $path, $query, ['apiKey' => $this->apiKey]);
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
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function guzzleTransport(string $url, array $query, array $headers): array
    {
        $http = new \GuzzleHttp\Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => $this->verifySsl,
            'http_errors'     => false,
        ]);
        $response = $http->request('GET', $url, ['query' => $query, 'headers' => $headers]);
        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }
}
