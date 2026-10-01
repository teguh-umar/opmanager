<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * The only place that talks HTTP to OpManager. The API key travels ONLY in the
 * `apiKey` header (query-parameter auth is deprecated since OpManager 12.8.100 and
 * listAlarms is header-only, spec §14). redact() still scrubs any message that
 * could embed a URL.
 */
final class Client implements DeviceSource, AlarmSource, AlarmWriter
{
    public const DEVICE_LIST_PATH = '/api/json/device/listDevices';
    public const ALARM_LIST_PATH  = '/api/json/alarm/listAlarms';
    public const ADD_NOTES_PATH   = '/api/json/alarm/addNotes';
    public const CLEAR_ALARM_PATH = '/api/json/alarm/clearAlarm';

    /** @var callable(string, array<string, string>, array<string, string>, ?array<string, string>): array{status: int, body: string} a non-null form means POST */
    private $transport;

    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private bool $verifySsl = true,
        ?callable $transport = null,
        private int $timeout = 30,
    ) {
        $this->transport = $transport ?? $this->curlTransport(...);
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

    public function addNote(string $entity, string $notes): void
    {
        $this->post(self::ADD_NOTES_PATH, ['entity' => $entity, 'notes' => $notes]);
    }

    public function clearAlarm(string $entity): void
    {
        $this->post(self::CLEAR_ALARM_PATH, ['entity' => $entity]);
    }

    /**
     * OpManager answers HTTP 200 with {"error":{...}} on refusal (spec §14). Success shapes
     * differ per call (addNotes: "result", clearAlarm: "ackMessage"), so only "error" counts.
     *
     * @param array<string, string> $form
     */
    private function post(string $path, array $form): void
    {
        $data = json_decode($this->request($path, [], $form), true);
        if (is_array($data) && array_key_exists('error', $data)) {
            throw new ClientException(sprintf('OpManager %s refused: %s', $path, ResponseGuard::errorMessage($data['error'])));
        }
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): string
    {
        return $this->request($path, $query);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string>|null $form
     */
    private function request(string $path, array $query = [], ?array $form = null): string
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new ClientException('OpManager URL or API key is not configured');
        }
        try {
            $response = ($this->transport)(rtrim($this->baseUrl, '/') . $path, $query, ['apiKey' => $this->apiKey], $form);
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
     * Plain ext-curl (a GLPI requirement) rather than Guzzle: another plugin can ship its own
     * older Guzzle and win the autoload race (telegrambot bundles 6.2.1), which breaks
     * GLPI's Guzzle 7 with "Undefined constant ClientInterface::MAJOR_VERSION".
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param array<string, string>|null $form POST body; null = GET
     * @return array{status: int, body: string}
     */
    private function curlTransport(string $url, array $query, array $headers, ?array $form = null): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($form !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body];
    }
}
