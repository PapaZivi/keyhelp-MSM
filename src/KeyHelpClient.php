<?php
final class KeyHelpClient
{
    private const DEFAULT_ENDPOINTS = [
        'users' => '/api/v2/clients',
        'clients' => '/api/v2/clients',
        'domains' => '/api/v2/domains',
        'domain_detail' => '/api/v2/domains/{id}',
        'client_detail' => '/api/v2/clients/{id}',
        'hosting_plans' => '/api/v2/hosting-plans',
        'server' => '/api/v2/server',
    ];

    public function __construct(private array $config, private array $server) {}

    public function serverInfo(): array
    {
        return $this->request('GET', $this->endpoint('server'), null, 'Serverstatus und Systeminformationen des KeyHelp-Servers abrufen.');
    }

    public function listDomains(): array
    {
        return $this->request('GET', $this->endpoint('domains'), null, 'Domainliste des KeyHelp-Servers abrufen.');
    }

    public function getDomain(string|int $id): array
    {
        return $this->request('GET', $this->endpoint('domain_detail', ['id' => (string)$id]), null, 'Detaildaten einer Domain abrufen, inklusive Besitzer-ID.');
    }

    public function listUsers(): array
    {
        return $this->request('GET', $this->endpoint('clients'), null, 'Clientliste abrufen, um Besitzer-IDs auf Benutzernamen aufzuloesen.');
    }

    public function getClient(string|int $id): array
    {
        return $this->request('GET', $this->endpoint('client_detail', ['id' => (string)$id]), null, 'Clientdetails abrufen, um id_user einer Domain auf den Benutzernamen abzubilden.');
    }

    public function createUser(array $payload): array
    {
        return $this->request('POST', $this->endpoint('clients'), $payload, 'KeyHelp-Client auf dem Zielserver anlegen.');
    }


    public function listHostingPlans(): array
    {
        return $this->request('GET', $this->endpoint('hosting_plans'), null, 'Hostingplaene des KeyHelp-Servers abrufen.');
    }
    public function createHostingPackage(array $payload): array
    {
        return $this->request('POST', $this->endpoint('hosting_plans'), $payload, 'Hostingpaket auf den Zielserver übertragen.');
    }

    private function endpoint(string $key, array $params = []): string
    {
        $map = array_replace(self::DEFAULT_ENDPOINTS, $this->config['keyhelp']['endpoint_map'] ?? []);
        $endpoint = $map[$key] ?? throw new RuntimeException('Unbekannter API-Endpunkt: ' . $key);
        foreach ($params as $name => $value) {
            $endpoint = str_replace('{' . $name . '}', rawurlencode((string)$value), $endpoint);
        }
        return $endpoint;
    }

    private function authHeader(): string
    {
        $auth = $this->config['keyhelp']['auth'] ?? [];
        $header = $auth['header'] ?? 'X-API-Key';
        $prefix = $auth['prefix'] ?? '';
        return $header . ': ' . $prefix . $this->server['api_token'];
    }

    private function request(string $method, string $path, ?array $payload = null, string $purpose = ''): array
    {
        $url = rtrim($this->server['base_url'], '/') . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            $this->authHeader(),
        ];
        $this->log(3, 'KeyHelp API request', [
            'purpose' => $purpose,
            'server' => $this->server['name'] ?? '',
            'method' => $method,
            'url' => $url,
            'headers' => $this->maskedHeaders($headers),
            'payload' => $this->maskedPayload($payload),
        ]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => (int)$this->config['keyhelp']['timeout'],
            CURLOPT_SSL_VERIFYPEER => (bool)$this->config['keyhelp']['verify_tls'],
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $this->log(4, 'KeyHelp API response', [
            'purpose' => $purpose,
            'server' => $this->server['name'] ?? '',
            'method' => $method,
            'url' => $url,
            'status' => $status,
            'error' => $error,
            'body' => $this->decodeBodyForLog($body),
        ]);
        if ($body === false || $error !== '') {
            throw new RuntimeException($this->server['name'] . ': cURL-Fehler: ' . $error);
        }
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : $body;
            throw new RuntimeException($this->server['name'] . ': HTTP ' . $status . ' - ' . $message);
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function log(int $level, string $message, array $context = []): void
    {
        if (class_exists('Logger')) {
            (new Logger($this->config))->log($level, $message, $context);
        }
    }

    private function maskedHeaders(array $headers): array
    {
        return array_map(function (string $header): string {
            if (preg_match('/^(Authorization|X-API-Key)\s*:/i', $header)) {
                [$name] = explode(':', $header, 2);
                return $name . ': ***';
            }
            return $header;
        }, $headers);
    }

    private function maskedPayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }
        return $this->maskSensitive($payload);
    }

    private function decodeBodyForLog(string|false $body): mixed
    {
        if ($body === false) {
            return false;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $this->maskSensitive($decoded) : $body;
    }

    private function maskSensitive(array $data): array
    {
        foreach ($data as $key => $value) {
            $lower = strtolower((string)$key);
            if (str_contains($lower, 'password') || str_contains($lower, 'token') || str_contains($lower, 'secret') || str_contains($lower, 'hash')) {
                $data[$key] = '***';
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->maskSensitive($value);
            }
        }
        return $data;
    }
}