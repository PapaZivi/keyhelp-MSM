<?php
final class Repository
{
    public function __construct(private PDO $pdo) {}


    public function serverRefreshInterval(): int
    {
        $value = (int)$this->setting('server_refresh_interval', '60');
        return in_array($value, self::refreshIntervalOptions(), true) ? $value : 60;
    }

    public function updateServerRefreshInterval(int $seconds): int
    {
        if (!in_array($seconds, self::refreshIntervalOptions(), true)) {
            throw new RuntimeException('Ungueltiges Refresh-Intervall.');
        }
        $this->saveSetting('server_refresh_interval', (string)$seconds);
        return $seconds;
    }

    public function locale(string $default = 'de'): string
    {
        $value = $this->setting('locale', $default);
        return array_key_exists($value, i18n_supported_locales()) ? $value : $default;
    }

    public function updateLocale(string $locale): string
    {
        if (!array_key_exists($locale, i18n_supported_locales())) {
            throw new RuntimeException('Ungueltige Sprache.');
        }
        $this->saveSetting('locale', $locale);
        return $locale;
    }

    public static function refreshIntervalOptions(): array
    {
        return [5, 15, 30, 60, 90, 120, 180, 300];
    }

    private function setting(string $key, string $default = ''): string
    {
        $this->ensureSettingsTable();
        $stmt = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    }

    private function saveSetting(string $key, string $value): void
    {
        $this->ensureSettingsTable();
        $stmt = $this->pdo->prepare('INSERT INTO app_settings(setting_key, setting_value) VALUES(?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP');
        $stmt->execute([$key, $value]);
    }

    private function ensureSettingsTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    public function servers(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM servers' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function server(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM servers WHERE id = ?');
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        return $server ?: null;
    }

    public function domain(int $id): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT d.*, s.name AS server_name, duplicates.server_count AS duplicate_server_count
            FROM domains d
            JOIN servers s ON s.id = d.server_id
            JOIN (
                SELECT domain, COUNT(DISTINCT server_id) AS server_count
                FROM domains
                GROUP BY domain
            ) duplicates ON duplicates.domain = d.domain
            WHERE d.id = ?
        ');
        $stmt->execute([$id]);
        $domain = $stmt->fetch();
        return $domain ?: null;
    }

    public function domains(): array
    {
        return $this->pdo->query('
            SELECT d.*, s.name AS server_name, duplicates.server_count AS duplicate_server_count
            FROM domains d
            JOIN servers s ON s.id = d.server_id
            JOIN (
                SELECT domain, COUNT(DISTINCT server_id) AS server_count
                FROM domains
                GROUP BY domain
            ) duplicates ON duplicates.domain = d.domain
            ORDER BY d.domain, s.name
        ')->fetchAll();
    }
    public function packages(): array
    {
        return $this->pdo->query('SELECT p.*, s.name AS server_name FROM hosting_packages p LEFT JOIN servers s ON s.id = p.server_id ORDER BY p.name')->fetchAll();
    }

    public function actions(): array
    {
        return $this->pdo->query("SELECT a.*, s.name AS server_name FROM planned_actions a LEFT JOIN servers s ON s.id = a.server_id WHERE a.status = 'pending' ORDER BY a.created_at, a.id")->fetchAll();
    }

    public function addServer(array $data): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO servers(name, base_url, api_token, active) VALUES(?, ?, ?, 1)');
        $stmt->execute([$data['name'], $data['base_url'], $data['api_token']]);
    }

    public function updateServer(array $data): void
    {
        $active = isset($data['active']) ? 1 : 0;
        if (trim((string)($data['api_token'] ?? '')) !== '') {
            $stmt = $this->pdo->prepare('UPDATE servers SET name = ?, base_url = ?, api_token = ?, active = ? WHERE id = ?');
            $stmt->execute([$data['name'], $data['base_url'], $data['api_token'], $active, $data['id']]);
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE servers SET name = ?, base_url = ?, active = ? WHERE id = ?');
        $stmt->execute([$data['name'], $data['base_url'], $active, $data['id']]);
    }

    public function addPackage(array $data): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO hosting_packages(name, description, limits_json, scope, server_id) VALUES(?, ?, ?, ?, ?)');
        $stmt->execute([$data['name'], $data['description'] ?? '', $data['limits_json'] ?: '{}', $data['scope'], $data['server_id'] ?: null]);
        $this->queue('create_hosting_package', $data['server_id'] ?: null, $data);
    }

    public function saveHostingPlan(int $serverId, array $plan): void
    {
        $externalId = (string)($plan['id'] ?? $plan['external_id'] ?? '');
        $name = $this->hostingPlanName($plan);
        if ($externalId === '' || $name === '') {
            return;
        }
        $description = (string)($plan['description'] ?? '');
        $stmt = $this->pdo->prepare('INSERT INTO hosting_packages(external_id, name, description, limits_json, scope, server_id, synced_at) VALUES(?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), limits_json = VALUES(limits_json), scope = VALUES(scope), synced_at = CURRENT_TIMESTAMP');
        $stmt->execute([
            $externalId,
            $name,
            $description,
            json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'server',
            $serverId,
        ]);
    }

    public function deleteHostingPlansExcept(int $serverId, array $externalIds): void
    {
        $externalIds = array_values(array_filter(array_map('strval', $externalIds)));
        if ($externalIds === []) {
            $stmt = $this->pdo->prepare('DELETE FROM hosting_packages WHERE server_id = ? AND external_id IS NOT NULL');
            $stmt->execute([$serverId]);
            return;
        }
        $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
        $stmt = $this->pdo->prepare('DELETE FROM hosting_packages WHERE server_id = ? AND external_id IS NOT NULL AND external_id NOT IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$serverId], $externalIds));
    }

    private function hostingPlanName(array $plan): string
    {
        foreach (['name', 'title', 'description'] as $key) {
            if (isset($plan[$key]) && trim((string)$plan[$key]) !== '') {
                return trim((string)$plan[$key]);
            }
        }
        return '';
    }
    public function queue(string $type, ?int $serverId, array $payload): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO planned_actions(type, server_id, payload_json) VALUES(?, ?, ?)');
        $stmt->execute([$type, $serverId, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    public function saveDomain(int $serverId, array $domain, array $usersById = []): void
    {
        $name = $domain['domain'] ?? $domain['name'] ?? $domain['domainName'] ?? null;
        if (!$name) {
            return;
        }
        $ownerId = DomainOwner::id($domain);
        $ownerName = DomainOwner::name($domain, $usersById);
        $stmt = $this->pdo->prepare('INSERT INTO domains(server_id, external_id, domain, owner_external_id, owner_name, registered_at, next_billing_at, registrar, domain_status, is_disabled, delete_on, synced_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE external_id = VALUES(external_id), owner_external_id = VALUES(owner_external_id), owner_name = VALUES(owner_name), domain_status = VALUES(domain_status), is_disabled = VALUES(is_disabled), delete_on = VALUES(delete_on), synced_at = CURRENT_TIMESTAMP');
        $stmt->execute([
            $serverId,
            (string)($domain['id'] ?? ''),
            strtolower(trim((string)$name, " \t\n\r\0\x0B.")),
            $ownerId,
            $ownerName,
            $domain['registered_at'] ?? null,
            $domain['next_billing_at'] ?? null,
            $domain['registrar'] ?? null,
            isset($domain['status']) ? (int)$domain['status'] : null,
            !empty($domain['is_disabled']) ? 1 : 0,
            $this->dateOnly($domain['delete_on'] ?? null),
        ]);
    }

    private function dateOnly(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable((string)$value))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    public function deleteDomain(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM domains WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteDomainsExcept(int $serverId, array $domainNames): void
    {
        $domainNames = array_values(array_filter($domainNames));
        if ($domainNames === []) {
            $stmt = $this->pdo->prepare('DELETE FROM domains WHERE server_id = ?');
            $stmt->execute([$serverId]);
            return;
        }
        $placeholders = implode(',', array_fill(0, count($domainNames), '?'));
        $stmt = $this->pdo->prepare('DELETE FROM domains WHERE server_id = ? AND domain NOT IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$serverId], $domainNames));
    }

    public function updateDomainBilling(array $data): void
    {
        $stmt = $this->pdo->prepare('UPDATE domains SET registered_at = ?, next_billing_at = ?, registrar = ? WHERE id = ?');
        $stmt->execute([$data['registered_at'] ?: null, $data['next_billing_at'] ?: null, $data['registrar'] ?: null, $data['id']]);
    }

    public function markAction(int $id, string $status, array $result = []): void
    {
        $stmt = $this->pdo->prepare('UPDATE planned_actions SET status = ?, result_json = ?, executed_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$status, json_encode($result, JSON_UNESCAPED_UNICODE), $id]);
    }

    public function createSyncRun(string $status, string $message = ''): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sync_runs(status, message) VALUES(?, ?)');
        $stmt->execute([$status, $message]);
        return (int)$this->pdo->lastInsertId();
    }

    public function finishSyncRun(int $id, string $status, string $message): void
    {
        $stmt = $this->pdo->prepare('UPDATE sync_runs SET status = ?, message = ?, finished_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$status, $message, $id]);
    }
}