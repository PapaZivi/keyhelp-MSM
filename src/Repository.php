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
    public function themeMode(): string
    {
        $value = $this->setting('theme_mode', 'auto');
        return in_array($value, self::themeModeOptions(), true) ? $value : 'auto';
    }

    public function updateThemeMode(string $mode): string
    {
        if (!in_array($mode, self::themeModeOptions(), true)) {
            throw new RuntimeException('Ungueltiger Darkmode.');
        }
        $this->saveSetting('theme_mode', $mode);
        return $mode;
    }

    public function formatSettings(): array
    {
        return [
            'locale' => $this->setting('format_locale', 'auto'),
            'currency' => $this->setting('currency_code', 'EUR'),
            'date_format' => $this->setting('date_format', 'auto'),
            'time_format' => $this->setting('time_format', 'auto'),
            'decimal_separator' => $this->setting('decimal_separator', 'auto'),
        ];
    }

    public function updateFormatSettings(array $data): array
    {
        $current = $this->formatSettings();
        $locale = (string)($data['format_locale'] ?? $current['locale']);
        if ($locale !== 'auto' && !preg_match('/^[a-z]{2,3}(?:[-_][A-Z]{2})?$/', $locale)) {
            throw new RuntimeException('Ungueltige Formatierung.');
        }
        $currency = strtoupper(trim((string)($data['currency_code'] ?? $current['currency'])));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('Ungueltige Waehrung.');
        }
        $dateFormat = (string)($data['date_format'] ?? $current['date_format']);
        if (!in_array($dateFormat, self::dateFormatOptions(), true)) {
            throw new RuntimeException('Ungueltiges Datumsformat.');
        }
        $timeFormat = (string)($data['time_format'] ?? $current['time_format']);
        if (!in_array($timeFormat, self::timeFormatOptions(), true)) {
            throw new RuntimeException('Ungueltiges Uhrzeitformat.');
        }
        $decimalSeparator = (string)($data['decimal_separator'] ?? $current['decimal_separator']);
        if (!in_array($decimalSeparator, self::decimalSeparatorOptions(), true)) {
            throw new RuntimeException('Ungueltiges Dezimalzeichen.');
        }
        $this->saveSetting('format_locale', $locale);
        $this->saveSetting('currency_code', $currency);
        $this->saveSetting('date_format', $dateFormat);
        $this->saveSetting('time_format', $timeFormat);
        $this->saveSetting('decimal_separator', $decimalSeparator);
        return $this->formatSettings();
    }

    public function usernamePattern(): string
    {
        return $this->setting('username_pattern', '{{servername_short}}_user_{{NUMBER:2}}');
    }

    public function updateUsernamePattern(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            $pattern = '{{servername_short}}_user_{{NUMBER:2}}';
        }
        $this->validateUsernamePattern($pattern);
        $this->saveSetting('username_pattern', $pattern);
        return $pattern;
    }

    private function validateUsernamePattern(string $pattern): void
    {
        $allowed = ['servername', 'servername_short', 'NUMBER'];
        preg_match_all('/\{\{([A-Za-z_]+)(?::(\d+))?\}\}/', $pattern, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $variable = $match[1];
            if (!in_array($variable, $allowed, true)) {
                throw new RuntimeException(t('config.invalid_username_pattern'));
            }
            if (isset($match[2]) && $variable !== 'NUMBER') {
                throw new RuntimeException(t('config.invalid_username_pattern'));
            }
        }

        $withoutVariables = preg_replace('/\{\{[A-Za-z_]+(?::\d+)?\}\}/', '', $pattern) ?? '';
        if (
            preg_match('/\b(?:servername|servername_short|NUMBER)\b/', $withoutVariables)
            || str_contains($withoutVariables, '{{')
            || str_contains($withoutVariables, '}}')
        ) {
            throw new RuntimeException(t('config.invalid_username_pattern'));
        }
    }

    public static function refreshIntervalOptions(): array
    {
        return [0, 5, 15, 30, 60, 90, 120, 180, 300];
    }
    public static function themeModeOptions(): array
    {
        return ['auto', 'light', 'dark'];
    }

    public static function formatLocaleOptions(): array
    {
        return ['auto', 'de-DE', 'en-US', 'en-GB', 'fr-FR', 'it-IT', 'es-ES'];
    }

    public static function dateFormatOptions(): array
    {
        return ['auto', 'dmy', 'mdy', 'ymd'];
    }

    public static function timeFormatOptions(): array
    {
        return ['auto', '24', '12'];
    }

    public static function decimalSeparatorOptions(): array
    {
        return ['auto', 'comma', 'dot'];
    }

    public static function billingFrequencyOptions(): array
    {
        return ['monthly', 'bimonthly', 'quarterly', 'halfyearly', 'yearly'];
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
        if ($this->pdo->inTransaction()) {
            return;
        }
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
            SELECT d.*, s.name AS server_name, lu.display_name AS local_user_name, duplicates.server_count AS duplicate_server_count
            FROM domains d
            JOIN servers s ON s.id = d.server_id
            LEFT JOIN local_users lu ON lu.id = d.local_user_id
            LEFT JOIN (
                SELECT domain, COUNT(DISTINCT server_id) AS server_count
                FROM domains
                WHERE is_deleted = 0
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
            SELECT d.*, s.name AS server_name, lu.display_name AS local_user_name, duplicates.server_count AS duplicate_server_count
            FROM domains d
            JOIN servers s ON s.id = d.server_id
            LEFT JOIN local_users lu ON lu.id = d.local_user_id
            LEFT JOIN (
                SELECT domain, COUNT(DISTINCT server_id) AS server_count
                FROM domains
                WHERE is_deleted = 0
                GROUP BY domain
            ) duplicates ON duplicates.domain = d.domain
            ORDER BY d.is_deleted, d.domain, s.name
        ')->fetchAll();
    }

    public function usersByServer(): array
    {
        $groups = [];
        foreach ($this->servers(true) as $server) {
            $groups[(int)$server['id']] = [
                'server' => $server,
                'users' => [],
                'error' => '',
            ];
        }
        $rows = $this->pdo->query('
            SELECT u.*, s.name AS server_name, lu.display_name AS local_user_name, lu.email AS local_user_email
            FROM keyhelp_users u
            JOIN servers s ON s.id = u.server_id
            LEFT JOIN local_users lu ON lu.id = u.local_user_id
            WHERE s.active = 1
            ORDER BY s.name, u.username, u.email
        ')->fetchAll();
        foreach ($rows as $row) {
            $serverId = (int)$row['server_id'];
            if (!isset($groups[$serverId])) {
                continue;
            }
            $groups[$serverId]['users'][] = $row;
        }
        return array_values($groups);
    }

    public function packages(): array
    {
        return $this->pdo->query('SELECT p.*, s.name AS server_name FROM hosting_packages p LEFT JOIN servers s ON s.id = p.server_id ORDER BY p.name')->fetchAll();
    }

    public function package(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT p.*, s.name AS server_name FROM hosting_packages p LEFT JOIN servers s ON s.id = p.server_id WHERE p.id = ?');
        $stmt->execute([$id]);
        $package = $stmt->fetch();
        return $package ?: null;
    }
    public function actions(): array
    {
        return $this->pdo->query("SELECT a.*, s.name AS server_name FROM planned_actions a LEFT JOIN servers s ON s.id = a.server_id WHERE a.status = 'pending' ORDER BY a.created_at, a.id")->fetchAll();
    }

    public function deleteServer(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM servers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deletePackage(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM hosting_packages WHERE id = ?');
        $stmt->execute([$id]);
    }
    public function addServer(array $data): void
    {
        $ssh = $this->serverSshSettings($data);
        $stmt = $this->pdo->prepare('INSERT INTO servers(name, base_url, api_token, ssh_link_enabled, ssh_port, ssh_username, active) VALUES(?, ?, ?, ?, ?, ?, 1)');
        $stmt->execute([$data['name'], $data['base_url'], $data['api_token'], $ssh['enabled'], $ssh['port'], $ssh['username']]);
    }

    public function updateServer(array $data): void
    {
        $active = isset($data['active']) ? 1 : 0;
        $ssh = $this->serverSshSettings($data);
        if (trim((string)($data['api_token'] ?? '')) !== '') {
            $stmt = $this->pdo->prepare('UPDATE servers SET name = ?, base_url = ?, api_token = ?, ssh_link_enabled = ?, ssh_port = ?, ssh_username = ?, active = ? WHERE id = ?');
            $stmt->execute([$data['name'], $data['base_url'], $data['api_token'], $ssh['enabled'], $ssh['port'], $ssh['username'], $active, $data['id']]);
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE servers SET name = ?, base_url = ?, ssh_link_enabled = ?, ssh_port = ?, ssh_username = ?, active = ? WHERE id = ?');
        $stmt->execute([$data['name'], $data['base_url'], $ssh['enabled'], $ssh['port'], $ssh['username'], $active, $data['id']]);
    }

    private function serverSshSettings(array $data): array
    {
        $port = max(1, min(65535, (int)($data['ssh_port'] ?? 22)));
        $username = trim((string)($data['ssh_username'] ?? ''));
        return [
            'enabled' => isset($data['ssh_link_enabled']) ? 1 : 0,
            'port' => $port,
            'username' => $username === '' ? null : $username,
        ];
    }

    public function addPackage(array $data): void
    {
        $payload = $this->hostingPackagePayload($data);
        $scope = ((string)($data['server_id'] ?? '') !== '') ? 'server' : 'system';
        $serverId = ((string)($data['server_id'] ?? '') !== '') ? (int)$data['server_id'] : null;
        $stmt = $this->pdo->prepare('INSERT INTO hosting_packages(name, description, limits_json, scope, server_id) VALUES(?, ?, ?, ?, ?)');
        $stmt->execute([$payload['name'], $data['description'] ?? '', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $scope, $serverId]);
    }

    public function updatePackage(array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Hostingpaket nicht gefunden.');
        }
        $currentStmt = $this->pdo->prepare('SELECT * FROM hosting_packages WHERE id = ?');
        $currentStmt->execute([$id]);
        $current = $currentStmt->fetch();
        if (!$current) {
            throw new RuntimeException('Hostingpaket nicht gefunden.');
        }
        $payload = $this->hostingPackagePayload($data);
        $scope = ((string)($data['server_id'] ?? '') !== '') ? 'server' : 'system';
        $serverId = ((string)($data['server_id'] ?? '') !== '') ? (int)$data['server_id'] : null;
        $stmt = $this->pdo->prepare('UPDATE hosting_packages SET name = ?, description = ?, limits_json = ?, scope = ?, server_id = ? WHERE id = ?');
        $stmt->execute([$payload['name'], $data['description'] ?? '', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $scope, $serverId, $id]);

    }

    private function hostingPackagePayload(array $data): array
    {
        if (isset($data['limits_json']) && trim((string)$data['limits_json']) !== '') {
            $decoded = json_decode((string)$data['limits_json'], true);
            if (is_array($decoded) && !isset($data['disk_space'])) {
                $decoded['name'] = trim((string)($data['name'] ?? $decoded['name'] ?? ''));
                return $decoded;
            }
        }
        return [
            'name' => trim((string)($data['name'] ?? '')),
            'resources' => $this->packageResourceLimitsPayload($data),
            'permissions' => $this->packagePermissionsPayload($data),
            'php' => $this->packagePhpPayload($data),
            'php_fpm' => $this->packagePhpFpmPayload($data),
        ];
    }

    private function packageResourceLimitsPayload(array $data): array
    {
        $limits = [];
        foreach (['disk_space', 'traffic'] as $field) {
            $limits[$field] = !empty($data[$field . '_unlimited']) ? -1 : $this->packageByteLimit((string)($data[$field] ?? '0'), (string)($data[$field . '_unit'] ?? 'MiB'));
        }
        $map = [
            'domains' => 'domains',
            'subdomains' => 'subdomains',
            'email_accounts' => 'email_accounts',
            'email_addresses' => 'email_addresses',
            'email_forwarders' => 'email_forwardings',
            'databases' => 'databases',
            'ftp_users' => 'ftp_users',
            'scheduled_tasks' => 'scheduled_tasks',
        ];
        foreach ($map as $formField => $apiField) {
            $value = (int)($data[$formField] ?? 0);
            $limits[$apiField] = !empty($data[$formField . '_unlimited']) || $value < 0 ? -1 : $value;
        }
        if (empty($data['permission_ftp'])) {
            $limits['ftp_users'] = 0;
        }
        return $limits;
    }

    private function packageByteLimit(string $value, string $unit): int
    {
        $number = max(0, (int)$value);
        return $unit === 'GiB' ? $number * 1024 * 1024 * 1024 : $number * 1024 * 1024;
    }

    private function packagePermissionsPayload(array $data): array
    {
        $map = [
            'ftp' => 'ftp',
            'php' => 'php',
            'perl_cgi' => 'perl',
            'ssh' => 'ssh',
            'backup' => 'backup',
            'file_manager' => 'file_manager',
            'dns_editor' => 'dns_editor',
            'domain_security' => 'domain_security',
            'certificate_management' => 'certificate_management',
            'database_remote_access' => 'database_remote_access',
            'email_catch_all' => 'email_catchall',
            'delete_main_domains' => 'delete_main_domain',
            'panel_access' => 'panel_access',
            'update_contact_data' => 'update_contact_data',
            'applications' => 'applications',
            'restricted_ssh' => 'ssh_jail',
        ];
        $permissions = [];
        foreach ($map as $formField => $apiField) {
            $permissions[$apiField] = !empty($data['permission_' . $formField]);
        }
        if (!empty($permissions['ssh_jail'])) {
            $permissions['ssh'] = true;
        }
        return $permissions;
    }

    private function packagePhpPayload(array $data): array
    {
        return [
            'memory_limit' => (string)($data['php_memory_limit'] ?? '128M'),
            'max_execution_time' => (int)($data['php_max_execution_time'] ?? 60),
            'post_max_size' => (string)($data['php_post_max_size'] ?? '72M'),
            'upload_max_filesize' => (string)($data['php_upload_max_filesize'] ?? '64M'),
            'open_basedir' => (string)($data['php_open_basedir'] ?? ''),
            'disable_functions' => (string)($data['php_disable_functions'] ?? ''),
            'sendmail_from' => (string)($data['php_sendmail_from'] ?? ''),
            'env_variables' => (string)($data['php_environment_variables'] ?? ''),
            'extra_directives_immutable' => (string)($data['php_extra_directives_immutable'] ?? ''),
            'extra_directives_mutable' => (string)($data['php_extra_directives_mutable'] ?? ''),
        ];
    }

    private function packagePhpFpmPayload(array $data): array
    {
        return [
            'pm' => (string)($data['php_fpm_pm'] ?? 'ondemand'),
            'max_children' => max(1, (int)($data['php_fpm_max_children'] ?? 3)),
            'max_requests' => max(0, (int)($data['php_fpm_max_requests'] ?? 0)),
            'status' => !empty($data['php_fpm_status_enabled']),
            'status_ip_restriction' => trim((string)($data['php_fpm_status_ips'] ?? '')) ?: null,
        ];
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
        $stmt = $this->pdo->prepare('INSERT INTO domains(server_id, local_user_id, external_id, domain, owner_external_id, owner_name, registered_at, next_billing_at, registrar, domain_status, is_disabled, is_deleted, deleted_at, suspend_on, delete_on, synced_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE local_user_id = COALESCE(VALUES(local_user_id), local_user_id), external_id = VALUES(external_id), owner_external_id = VALUES(owner_external_id), owner_name = VALUES(owner_name), domain_status = VALUES(domain_status), is_disabled = VALUES(is_disabled), is_deleted = 0, deleted_at = NULL, suspend_on = VALUES(suspend_on), delete_on = VALUES(delete_on), synced_at = CURRENT_TIMESTAMP');
        $stmt->execute([
            $serverId,
            $this->localUserIdForRemoteOwner($serverId, $ownerId),
            (string)($domain['id'] ?? ''),
            strtolower(trim((string)$name, " \t\n\r\0\x0B.")),
            $ownerId,
            $ownerName,
            $domain['registered_at'] ?? null,
            $domain['next_billing_at'] ?? null,
            $domain['registrar'] ?? null,
            isset($domain['status']) ? (int)$domain['status'] : null,
            !empty($domain['is_disabled']) ? 1 : 0,
            $this->dateOnly($domain['suspend_on'] ?? $domain['lock_on'] ?? null),
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

    public function markDomainDeleted(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE domains SET is_deleted = 1, deleted_at = COALESCE(deleted_at, CURRENT_TIMESTAMP) WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function domainExistsOnAnotherServer(string $domain, int $serverId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM domains WHERE domain = ? AND server_id <> ? AND is_deleted = 0');
        $stmt->execute([$domain, $serverId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function deleteDomainsExcept(int $serverId, array $domainNames): void
    {
        $domainNames = array_values(array_filter($domainNames));
        $sql = 'SELECT id, domain FROM domains WHERE server_id = ? AND is_deleted = 0';
        $params = [$serverId];
        if ($domainNames !== []) {
            $placeholders = implode(',', array_fill(0, count($domainNames), '?'));
            $sql .= ' AND domain NOT IN (' . $placeholders . ')';
            $params = array_merge($params, $domainNames);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $domain) {
            if (!$this->domainExistsOnAnotherServer((string)$domain['domain'], $serverId)) {
                $this->markDomainDeleted((int)$domain['id']);
            }
        }
    }

    public function saveUser(int $serverId, array $user): void
    {
        $externalId = $this->userExternalId($user);
        if ($externalId === '') {
            return;
        }
        $stmt = $this->pdo->prepare('INSERT INTO keyhelp_users(server_id, external_id, username, email, raw_json, synced_at) VALUES(?, ?, ?, ?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE username = VALUES(username), email = VALUES(email), raw_json = VALUES(raw_json), synced_at = CURRENT_TIMESTAMP');
        $stmt->execute([
            $serverId,
            $externalId,
            user_display_name($user),
            user_email($user) ?: null,
            json_encode($user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function createLocalUser(array $data): int
    {
        $name = trim((string)($data['display_name'] ?? ''));
        if ($name === '') {
            $name = t('common.unknown');
        }
        $customerNumber = trim((string)($data['customer_number'] ?? ''));
        if ($customerNumber === '') {
            $customerNumber = $this->nextCustomerNumber();
        }
        $stmt = $this->pdo->prepare('
            INSERT INTO local_users(
                display_name, email, invoice_email, customer_number, company, first_name, last_name, phone,
                address, postcode, city, region, country, notes
            ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $name,
            trim((string)($data['email'] ?? '')) ?: null,
            trim((string)($data['invoice_email'] ?? '')) ?: null,
            $customerNumber,
            trim((string)($data['company'] ?? '')) ?: null,
            trim((string)($data['first_name'] ?? '')) ?: null,
            trim((string)($data['last_name'] ?? '')) ?: null,
            trim((string)($data['phone'] ?? '')) ?: null,
            trim((string)($data['address'] ?? '')) ?: null,
            trim((string)($data['postcode'] ?? '')) ?: null,
            trim((string)($data['city'] ?? '')) ?: null,
            trim((string)($data['region'] ?? '')) ?: null,
            trim((string)($data['country'] ?? '')) ?: null,
            trim((string)($data['notes'] ?? '')) ?: null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateLocalUser(array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['display_name'] ?? ''));
        if ($id <= 0 || $name === '') {
            throw new InvalidArgumentException(t('users.local_user_required'));
        }
        $customerNumber = trim((string)($data['customer_number'] ?? ''));
        if ($customerNumber === '') {
            $customerNumber = $this->nextCustomerNumber($id);
        }
        $stmt = $this->pdo->prepare('
            UPDATE local_users
            SET display_name = ?, email = ?, invoice_email = ?, customer_number = ?, company = ?, first_name = ?,
                last_name = ?, phone = ?, address = ?, postcode = ?, city = ?, region = ?,
                country = ?, notes = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $name,
            trim((string)($data['email'] ?? '')) ?: null,
            trim((string)($data['invoice_email'] ?? '')) ?: null,
            $customerNumber,
            trim((string)($data['company'] ?? '')) ?: null,
            trim((string)($data['first_name'] ?? '')) ?: null,
            trim((string)($data['last_name'] ?? '')) ?: null,
            trim((string)($data['phone'] ?? '')) ?: null,
            trim((string)($data['address'] ?? '')) ?: null,
            trim((string)($data['postcode'] ?? '')) ?: null,
            trim((string)($data['city'] ?? '')) ?: null,
            trim((string)($data['region'] ?? '')) ?: null,
            trim((string)($data['country'] ?? '')) ?: null,
            trim((string)($data['notes'] ?? '')) ?: null,
            $id,
        ]);
    }

    private function nextCustomerNumber(?int $excludeUserId = null): string
    {
        $sql = "SELECT MAX(CAST(customer_number AS UNSIGNED)) FROM local_users WHERE customer_number REGEXP '^[0-9]+$'";
        $params = [];
        if ($excludeUserId !== null && $excludeUserId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeUserId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (string)(((int)$stmt->fetchColumn()) + 1);
    }

    public function createLocalUserFromRemoteUser(int $remoteUserId): int
    {
        $remote = $this->user($remoteUserId);
        if (!$remote) {
            throw new RuntimeException(t('message.user_not_found'));
        }
        $raw = json_decode((string)($remote['raw_json'] ?? '{}'), true) ?: [];
        $contact = isset($raw['contact_data']) && is_array($raw['contact_data']) ? $raw['contact_data'] : [];
        $localUserId = $this->createLocalUser([
            'display_name' => (string)($remote['username'] ?? t('common.unknown')),
            'email' => (string)($contact['email'] ?? $remote['email'] ?? ''),
            'invoice_email' => (string)($contact['email'] ?? $remote['email'] ?? ''),
            'customer_number' => (string)($contact['client_id'] ?? ''),
            'company' => (string)($contact['company'] ?? ''),
            'first_name' => (string)($contact['first_name'] ?? ''),
            'last_name' => (string)($contact['last_name'] ?? ''),
            'phone' => (string)($contact['telephone'] ?? ''),
            'address' => (string)($contact['address'] ?? ''),
            'postcode' => (string)($contact['zip'] ?? ''),
            'city' => (string)($contact['city'] ?? ''),
            'region' => (string)($contact['state'] ?? ''),
            'country' => (string)($contact['country'] ?? ''),
            'notes' => (string)($raw['notes'] ?? ''),
        ]);
        $this->assignRemoteUserToLocalUser($remoteUserId, $localUserId);
        return $localUserId;
    }

    public function deleteLocalUser(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(t('users.local_user_required'));
        }
        $blockers = $this->localUserDeleteBlockers($id);
        if (in_array('remote_users', $blockers, true)) {
            throw new RuntimeException(t('users.local_user_delete_has_remote_users'));
        }
        if (in_array('domains', $blockers, true)) {
            throw new RuntimeException(t('users.local_user_delete_has_domains'));
        }
        if (in_array('pending_billing', $blockers, true)) {
            throw new RuntimeException(t('users.local_user_delete_has_open_billing'));
        }
        if (in_array('open_invoices', $blockers, true)) {
            throw new RuntimeException(t('users.local_user_delete_has_open_invoices'));
        }
        if (in_array('invoices', $blockers, true)) {
            throw new RuntimeException(t('users.local_user_delete_has_invoices'));
        }
        if (in_array('customer_account', $blockers, true)) {
            throw new RuntimeException(t('users.local_user_delete_has_customer_account'));
        }
        $stmt = $this->pdo->prepare('DELETE FROM local_users WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function localUserDeleteBlockersById(): array
    {
        $blockers = [];
        foreach ($this->usersFlat() as $user) {
            $blockers[(int)$user['id']] = $this->localUserDeleteBlockers((int)$user['id']);
        }
        return $blockers;
    }

    private function localUserDeleteBlockers(int $id): array
    {
        $blockers = [];
        if ($this->countByColumn('keyhelp_users', 'local_user_id', $id) > 0) {
            $blockers[] = 'remote_users';
        }
        if ($this->countByColumn('domains', 'local_user_id', $id) > 0) {
            $blockers[] = 'domains';
        }
        if ($this->countByColumn('billing_pending_items', 'user_id', $id) > 0) {
            $blockers[] = 'pending_billing';
        }
        if ($this->openInvoiceCountForUser($id) > 0) {
            $blockers[] = 'open_invoices';
        }
        if ($this->countByColumn('invoices', 'user_id', $id) > 0) {
            $blockers[] = 'invoices';
        }
        if ($this->countByColumn('billing_payments', 'user_id', $id) > 0) {
            $blockers[] = 'customer_account';
        }
        return $blockers;
    }

    private function countByColumn(string $table, string $column, int $value): int
    {
        $allowed = [
            'keyhelp_users' => ['local_user_id'],
            'domains' => ['local_user_id'],
            'billing_pending_items' => ['user_id'],
            'invoices' => ['user_id'],
            'billing_payments' => ['user_id'],
        ];
        if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
            throw new InvalidArgumentException('Invalid count target.');
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` = ?');
        $stmt->execute([$value]);
        return (int)$stmt->fetchColumn();
    }

    private function openInvoiceCountForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND status NOT IN ('sent', 'cancelled')");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public function assignRemoteUserToLocalUser(int $remoteUserId, int $localUserId): void
    {
        $remote = $this->user($remoteUserId);
        $stmt = $this->pdo->prepare('UPDATE keyhelp_users SET local_user_id = ? WHERE id = ?');
        $stmt->execute([$localUserId, $remoteUserId]);
        if ($remote) {
            $domainStmt = $this->pdo->prepare('UPDATE domains SET local_user_id = ? WHERE server_id = ? AND owner_external_id = ?');
            $domainStmt->execute([$localUserId, (int)$remote['server_id'], (string)$remote['external_id']]);
        }
    }

    public function billingUserIdForRemoteUser(int $remoteUserId): int
    {
        $stmt = $this->pdo->prepare('SELECT local_user_id FROM keyhelp_users WHERE id = ?');
        $stmt->execute([$remoteUserId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function localUserIdForRemoteOwner(int $serverId, string $ownerExternalId): ?int
    {
        if ($ownerExternalId === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT local_user_id FROM keyhelp_users WHERE server_id = ? AND external_id = ?');
        $stmt->execute([$serverId, $ownerExternalId]);
        $id = $stmt->fetchColumn();
        return $id === false || $id === null ? null : (int)$id;
    }

    public function user(int $id): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT u.*, s.name AS server_name
            FROM keyhelp_users u
            JOIN servers s ON s.id = u.server_id
            WHERE u.id = ?
        ');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function localUser(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT *, display_name AS username, "" AS server_name FROM local_users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function localUsersById(): array
    {
        $users = [];
        foreach ($this->pdo->query('SELECT *, display_name AS username, "" AS server_name FROM local_users ORDER BY display_name')->fetchAll() as $user) {
            $users[(int)$user['id']] = $user;
        }
        return $users;
    }

    public function unassignedRemoteUsers(): array
    {
        return $this->pdo->query('
            SELECT u.*, s.name AS server_name
            FROM keyhelp_users u
            JOIN servers s ON s.id = u.server_id
            WHERE u.local_user_id IS NULL
            ORDER BY s.name, u.username
        ')->fetchAll();
    }

    public function remoteUsersByLocalUserId(): array
    {
        $users = [];
        foreach ($this->pdo->query('
            SELECT u.*, s.name AS server_name
            FROM keyhelp_users u
            JOIN servers s ON s.id = u.server_id
            WHERE u.local_user_id IS NOT NULL
            ORDER BY s.name, u.username
        ')->fetchAll() as $user) {
            $users[(int)$user['local_user_id']][] = $user;
        }
        return $users;
    }

    public function domainsByLocalUserId(): array
    {
        $domains = [];
        foreach ($this->pdo->query('
            SELECT d.*, s.name AS server_name
            FROM domains d
            JOIN servers s ON s.id = d.server_id
            WHERE d.local_user_id IS NOT NULL
            ORDER BY d.domain, s.name
        ')->fetchAll() as $domain) {
            $domains[(int)$domain['local_user_id']][] = $domain;
        }
        return $domains;
    }

    public function userByExternalId(int $serverId, string|int $externalId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT u.*, s.name AS server_name
            FROM keyhelp_users u
            JOIN servers s ON s.id = u.server_id
            WHERE u.server_id = ? AND u.external_id = ?
        ');
        $stmt->execute([$serverId, (string)$externalId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function deleteUser(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM keyhelp_users WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteUsersExcept(int $serverId, array $externalIds): void
    {
        $externalIds = array_values(array_filter(array_map('strval', $externalIds)));
        if ($externalIds === []) {
            $stmt = $this->pdo->prepare('DELETE FROM keyhelp_users WHERE server_id = ?');
            $stmt->execute([$serverId]);
            return;
        }
        $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
        $stmt = $this->pdo->prepare('DELETE FROM keyhelp_users WHERE server_id = ? AND external_id NOT IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$serverId], $externalIds));
    }

    public function userExternalId(array $user): string
    {
        foreach (['id', 'id_user', 'id_client', 'client_id', 'external_id'] as $key) {
            if (isset($user[$key]) && trim((string)$user[$key]) !== '') {
                return trim((string)$user[$key]);
            }
        }
        return '';
    }

    public function usernameExists(int $serverId, string $username): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM keyhelp_users WHERE server_id = ? AND LOWER(username) = LOWER(?)');
        $stmt->execute([$serverId, trim($username)]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function suggestUsername(int $serverId, ?string $pattern = null): string
    {
        $server = $this->server($serverId);
        if (!$server) {
            throw new RuntimeException(t('message.server_not_found'));
        }
        $pattern = $pattern ?? $this->usernamePattern();
        for ($number = 1; $number < 100000; $number++) {
            $username = $this->renderUsernamePattern($pattern, $server, $number);
            if ($username !== '' && !$this->usernameExists($serverId, $username)) {
                return $username;
            }
        }
        throw new RuntimeException(t('message.username_suggestion_failed'));
    }

    private function renderUsernamePattern(string $pattern, array $server, int $number): string
    {
        $this->validateUsernamePattern($pattern);
        $serverName = $this->asciiToken((string)($server['name'] ?? 'server'));
        $username = str_replace(
            ['{{servername}}', '{{servername_short}}'],
            [$serverName, substr($serverName, 0, 6)],
            $pattern
        );
        $username = preg_replace_callback('/\{\{NUMBER(?::(\d+))?\}\}/', static function (array $match) use ($number): string {
            $width = isset($match[1]) ? max(1, (int)$match[1]) : 1;
            return str_pad((string)$number, $width, '0', STR_PAD_LEFT);
        }, $username) ?? $username;
        return $this->asciiToken($username);
    }

    private function asciiToken(string $value): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = strtolower($converted === false ? $value : $converted);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? '';
        return trim($value, '_');
    }

    public function updateDomainBilling(array $data): void
    {
        $frequency = (string)($data['billing_frequency'] ?? 'yearly');
        if (!in_array($frequency, self::billingFrequencyOptions(), true)) {
            $frequency = 'yearly';
        }
        $localUserId = (int)($data['local_user_id'] ?? 0);
        $stmt = $this->pdo->prepare('UPDATE domains SET local_user_id = ?, registered_at = ?, next_billing_at = ?, billing_frequency = ?, last_change_at = ?, registrar = ?, domain_owner_contact = ?, domain_admin_c = ?, domain_tech_c = ?, domain_zone_c = ? WHERE id = ?');
        $stmt->execute([
            $localUserId > 0 ? $localUserId : null,
            ($data['registered_at'] ?? '') ?: null,
            ($data['next_billing_at'] ?? '') ?: null,
            $frequency,
            ($data['last_change_at'] ?? '') ?: null,
            ($data['registrar'] ?? '') ?: null,
            trim((string)($data['domain_owner_contact'] ?? '')) ?: null,
            trim((string)($data['domain_admin_c'] ?? '')) ?: null,
            trim((string)($data['domain_tech_c'] ?? '')) ?: null,
            trim((string)($data['domain_zone_c'] ?? '')) ?: null,
            $data['id'],
        ]);
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
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            if (!$this->pdo->inTransaction()) {
                throw new RuntimeException('Die Datenbanktransaktion wurde unerwartet beendet.');
            }
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function acquireBillingLock(): bool
    {
        return (int)$this->pdo->query("SELECT GET_LOCK('keyhelp_msm_billing_run', 1)")->fetchColumn() === 1;
    }

    public function releaseBillingLock(): void
    {
        $this->pdo->query("SELECT RELEASE_LOCK('keyhelp_msm_billing_run')");
    }

    public function billingSetting(string $key, string $default = ''): string
    {
        return $this->setting('billing_' . $key, $default);
    }

    public function saveBillingSetting(string $key, string $value): void
    {
        $this->saveSetting('billing_' . $key, $value);
    }

    public function saveBillingSettings(array $data): void
    {
        $format = trim((string)($data['invoice_number_format'] ?? '{{YEAR}}{{MONTH}}{{DAY}}-{{SEQ}}'));
        if ($format === '') {
            $format = '{{YEAR}}{{MONTH}}{{DAY}}-{{SEQ}}';
        }
        $allowed = ['YEAR', 'MONTH', 'DAY', 'SEQ', 'USERID', 'USERNAME'];
        preg_match_all('/\{\{([A-Z_]+)(?::(\d+))?\}\}/', $format, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $variable = $match[1];
            if (!in_array($variable, $allowed, true)) {
                throw new RuntimeException(t('billing.invalid_invoice_format'));
            }
            if (isset($match[2]) && !in_array($variable, ['SEQ', 'USERID'], true)) {
                throw new RuntimeException(t('billing.invalid_invoice_format'));
            }
        }
        $withoutVariables = preg_replace('/\{\{[A-Z_]+(?::\d+)?\}\}/', '', $format) ?? '';
        if (
            preg_match('/\b(?:YEAR|MONTH|DAY|SEQ|USERID|USERNAME)\b/', $withoutVariables)
            || str_contains($withoutVariables, '{{')
            || str_contains($withoutVariables, '}}')
        ) {
            throw new RuntimeException(t('billing.invalid_invoice_format'));
        }
        $this->saveBillingSetting('invoice_sender', trim((string)($data['invoice_sender'] ?? '')));
        $this->saveBillingSetting('invoice_notification_recipients', trim((string)($data['invoice_notification_recipients'] ?? '')));
        $this->saveBillingSetting('payment_account_details', trim((string)($data['payment_account_details'] ?? '')));
        $this->saveBillingSetting('weekly_invoice_weekday', (string)max(1, min(7, (int)($data['weekly_invoice_weekday'] ?? 1))));
        $this->saveBillingSetting('monthly_invoice_day', (string)max(1, min(28, (int)($data['monthly_invoice_day'] ?? 1))));
        $this->saveBillingSetting('invoice_number_format', $format);
        $this->saveBillingSetting('invoice_mail_subject', trim((string)($data['invoice_mail_subject'] ?? '')));
        $this->saveBillingSetting('invoice_mail_body', (string)($data['invoice_mail_body'] ?? ''));
        $this->saveBillingSetting('dunning_mail_subject', trim((string)($data['dunning_mail_subject'] ?? '')));
        $this->saveBillingSetting('dunning_mail_body', (string)($data['dunning_mail_body'] ?? ''));
        $this->saveBillingSetting('invoice_template_html', (string)($data['invoice_template_html'] ?? InvoicePdfRenderer::defaultTemplate()));
        $this->saveBillingSetting('dunning_template_html', (string)($data['dunning_template_html'] ?? InvoicePdfRenderer::defaultDunningTemplate()));
        $this->audit('admin', 'billing_settings_saved', 'billing_settings');
    }

    public function billingOverview(): array
    {
        return [
            'settings' => [
                'invoice_sender' => $this->billingSetting('invoice_sender', ''),
                'invoice_notification_recipients' => $this->billingSetting('invoice_notification_recipients', ''),
                'payment_account_details' => $this->billingSetting('payment_account_details', ''),
                'weekly_invoice_weekday' => $this->billingSetting('weekly_invoice_weekday', '1'),
                'monthly_invoice_day' => $this->billingSetting('monthly_invoice_day', '1'),
                'invoice_number_format' => $this->billingSetting('invoice_number_format', '{{YEAR}}{{MONTH}}{{DAY}}-{{SEQ}}'),
                'invoice_mail_subject' => $this->billingSetting('invoice_mail_subject', 'Rechnung {{invoice.number}}'),
                'invoice_mail_body' => $this->billingSetting('invoice_mail_body', "Guten Tag {{customer.name}},\n\nIhre Rechnung {{invoice.number}} über {{invoice.total}} befindet sich im Anhang.\n\nMit freundlichen Grüßen\n{{sender.name}}"),
                'dunning_mail_subject' => $this->billingSetting('dunning_mail_subject', 'Mahnung zu Rechnung {{invoice.number}}'),
                'dunning_mail_body' => $this->billingSetting('dunning_mail_body', "Guten Tag {{customer.name}},\n\nzu Ihrer Rechnung {{invoice.number}} über {{invoice.total}} konnten wir noch keinen vollständigen Zahlungseingang feststellen.\n\nBitte prüfen Sie den offenen Betrag.\n\nMit freundlichen Grüßen\n{{sender.name}}"),
                'invoice_template_html' => $this->billingSetting('invoice_template_html', InvoicePdfRenderer::defaultTemplate()),
                'dunning_template_html' => $this->billingSetting('dunning_template_html', InvoicePdfRenderer::defaultDunningTemplate()),
                'last_run_at' => $this->billingLastRunAt(),
            ],
            'taxRates' => $this->billingTaxRates(),
            'tldPrices' => $this->billingTldPrices(),
            'domainOverrides' => $this->billingDomainOverrides(),
            'users' => $this->usersFlat(),
            'userSettings' => $this->billingUserSettingsByUserId(),
            'userItems' => $this->billingUserItems(false),
            'userItemsByUserId' => $this->billingUserItemsByUserId(false),
            'pendingItems' => $this->pendingBillingItems(),
            'danglingPendingItems' => $this->danglingPendingBillingItems(),
            'invoices' => $this->invoices(),
            'audit' => $this->billingAuditLog(),
        ];
    }

    public function billingTaxRates(): array
    {
        return $this->pdo->query('SELECT * FROM billing_tax_rates ORDER BY active DESC, is_default DESC, name')->fetchAll();
    }

    public function billingTaxRatesById(): array
    {
        $rates = [];
        foreach ($this->billingTaxRates() as $rate) {
            if ((int)($rate['active'] ?? 0) === 1) {
                $rates[(int)$rate['id']] = $rate;
            }
        }
        return $rates;
    }

    public function saveBillingTaxRate(array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException(t('billing.tax_name_required'));
        }
        $active = isset($data['active']) ? 1 : 0;
        $isDefault = isset($data['is_default']) ? 1 : 0;
        if ($isDefault) {
            $this->pdo->exec('UPDATE billing_tax_rates SET is_default = 0');
        }
        if ($id > 0) {
            $stmt = $this->pdo->prepare('UPDATE billing_tax_rates SET name = ?, rate_percent = ?, is_default = ?, active = ? WHERE id = ?');
            $stmt->execute([$name, $data['rate_percent'] ?? 0, $isDefault, $active, $id]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO billing_tax_rates(name, rate_percent, is_default, active) VALUES(?, ?, ?, ?)');
            $stmt->execute([$name, $data['rate_percent'] ?? 0, $isDefault, $active]);
        }
        $this->audit('admin', 'billing_tax_rate_saved', 'billing_tax_rate', $id ?: (int)$this->pdo->lastInsertId());
    }

    public function billingTldPrices(): array
    {
        return $this->pdo->query('SELECT p.*, t.name AS tax_name, t.rate_percent FROM billing_tld_prices p LEFT JOIN billing_tax_rates t ON t.id = p.tax_rate_id ORDER BY p.active DESC, p.tld')->fetchAll();
    }

    public function billingTldPricesByTld(): array
    {
        $prices = [];
        foreach ($this->billingTldPrices() as $price) {
            $prices[strtolower((string)$price['tld'])] = $price;
        }
        return $prices;
    }

    public function saveBillingTldPrice(array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        $tld = strtolower(trim((string)($data['tld'] ?? ''), " .\t\n\r
}
\x0B"));
        if ($tld === '') {
            throw new RuntimeException(t('billing.tld_required'));
        }
        $values = [$tld, $data['registration_price'] ?? 0, $data['yearly_price'] ?? 0, $data['change_price'] ?? 0, ($data['tax_rate_id'] ?? '') !== '' ? (int)$data['tax_rate_id'] : null, isset($data['active']) ? 1 : 0];
        if ($id > 0) {
            $stmt = $this->pdo->prepare('UPDATE billing_tld_prices SET tld = ?, registration_price = ?, yearly_price = ?, change_price = ?, tax_rate_id = ?, active = ? WHERE id = ?');
            $stmt->execute([...$values, $id]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO billing_tld_prices(tld, registration_price, yearly_price, change_price, tax_rate_id, active) VALUES(?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE registration_price = VALUES(registration_price), yearly_price = VALUES(yearly_price), change_price = VALUES(change_price), tax_rate_id = VALUES(tax_rate_id), active = VALUES(active)');
            $stmt->execute($values);
        }
        $this->audit('admin', 'billing_tld_price_saved', 'billing_tld_price', $id ?: null, ['tld' => $tld]);
    }

    public function billingDomainOverrides(): array
    {
        return $this->pdo->query('SELECT o.*, d.domain, d.server_id, s.name AS server_name, t.name AS tax_name FROM billing_domain_overrides o JOIN domains d ON d.id = o.domain_id JOIN servers s ON s.id = d.server_id LEFT JOIN billing_tax_rates t ON t.id = o.tax_rate_id ORDER BY d.domain')->fetchAll();
    }

    public function billingDomainOverridesByDomainId(): array
    {
        $rows = [];
        foreach ($this->billingDomainOverrides() as $row) {
            if ((int)($row['active'] ?? 0) === 1) {
                $rows[(int)$row['domain_id']] = $row;
            }
        }
        return $rows;
    }

    public function saveBillingDomainOverride(array $data): void
    {
        $domainId = (int)($data['domain_id'] ?? 0);
        if ($domainId <= 0) {
            throw new RuntimeException(t('billing.domain_required'));
        }
        $yearly = trim((string)($data['yearly_price'] ?? ''));
        $discount = trim((string)($data['discount_percent'] ?? ''));
        if ($yearly !== '' && $discount !== '') {
            throw new RuntimeException(t('billing.fixed_or_discount'));
        }
        $stmt = $this->pdo->prepare('INSERT INTO billing_domain_overrides(domain_id, registration_price, yearly_price, change_price, discount_percent, tax_rate_id, active) VALUES(?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE registration_price = VALUES(registration_price), yearly_price = VALUES(yearly_price), change_price = VALUES(change_price), discount_percent = VALUES(discount_percent), tax_rate_id = VALUES(tax_rate_id), active = VALUES(active)');
        $stmt->execute([
            $domainId,
            $this->nullableDecimal($data['registration_price'] ?? null),
            $this->nullableDecimal($yearly),
            $this->nullableDecimal($data['change_price'] ?? null),
            $this->nullableDecimal($discount),
            ($data['tax_rate_id'] ?? '') !== '' ? (int)$data['tax_rate_id'] : null,
            isset($data['active']) ? 1 : 0,
        ]);
        $this->audit('admin', 'billing_domain_override_saved', 'domain', $domainId);
    }

    private function nullableDecimal(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : str_replace(',', '.', $value);
    }

    public function billingUserSettingsByUserId(): array
    {
        $settings = [];
        foreach ($this->pdo->query('SELECT * FROM billing_user_settings')->fetchAll() as $row) {
            $settings[(int)$row['user_id']] = $row;
        }
        return $settings;
    }

    public function billingUserSetting(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM billing_user_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row && (string)($row['invoice_frequency'] ?? '') === 'immediate') {
            $row['invoice_frequency'] = 'daily';
        }
        return $row ?: ['user_id' => $userId, 'discount_percent' => 0, 'invoice_frequency' => 'monthly', 'last_invoice_at' => null, 'next_invoice_at' => null];
    }

    public function saveBillingUserSettings(array $data): void
    {
        $userId = (int)($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException(t('billing.user_required'));
        }
        $frequency = (string)($data['invoice_frequency'] ?? 'monthly');
        if ($frequency === 'immediate') {
            $frequency = 'daily';
        }
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            $frequency = 'monthly';
        }
        $stmt = $this->pdo->prepare('INSERT INTO billing_user_settings(user_id, discount_percent, invoice_frequency) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE discount_percent = VALUES(discount_percent), invoice_frequency = VALUES(invoice_frequency)');
        $stmt->execute([$userId, $data['discount_percent'] ?? 0, $frequency]);
        $this->audit('admin', 'billing_user_settings_saved', 'user', $userId);
    }

    public function updateUserInvoiceSchedule(int $userId, string $frequency, DateTimeImmutable $now): void
    {
        if ($frequency === 'immediate') {
            $frequency = 'daily';
        }
        $next = match ($frequency) {
            'daily' => $now->modify('+1 day')->format('Y-m-d'),
            'weekly' => $now->modify('+1 week')->format('Y-m-d'),
            'monthly' => $now->modify('+1 month')->format('Y-m-d'),
            default => $now->format('Y-m-d'),
        };
        $stmt = $this->pdo->prepare('INSERT INTO billing_user_settings(user_id, last_invoice_at, next_invoice_at, invoice_frequency) VALUES(?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_invoice_at = VALUES(last_invoice_at), next_invoice_at = VALUES(next_invoice_at)');
        $stmt->execute([$userId, $now->format('Y-m-d'), $next, $frequency]);
    }

    public function markUserInvoiceDue(int $userId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO billing_user_settings(user_id, next_invoice_at) VALUES(?, CURDATE()) ON DUPLICATE KEY UPDATE next_invoice_at = CURDATE()');
        $stmt->execute([$userId]);
    }

    public function usersFlat(): array
    {
        return $this->pdo->query('
            SELECT
                id,
                display_name AS username,
                display_name,
                email,
                invoice_email,
                customer_number,
                company,
                first_name,
                last_name,
                phone,
                address,
                postcode,
                city,
                region,
                country,
                notes
            FROM local_users
            ORDER BY display_name
        ')->fetchAll();
    }

    public function usersFlatByServerExternalId(): array
    {
        $users = [];
        foreach ($this->pdo->query('
            SELECT u.server_id, u.external_id, lu.id, lu.display_name AS username, lu.email, s.name AS server_name
            FROM keyhelp_users u
            JOIN local_users lu ON lu.id = u.local_user_id
            JOIN servers s ON s.id = u.server_id
        ')->fetchAll() as $user) {
            $users[(int)$user['server_id'] . ':' . (string)$user['external_id']] = $user;
        }
        return $users;
    }

    public function billingUserItems(bool $activeOnly): array
    {
        $sql = "
            SELECT
                i.*,
                u.display_name AS username,
                u.email,
                '' AS server_name,
                t.name AS tax_name,
                t.rate_percent,
                invref.invoice_id AS related_invoice_id,
                invref.invoice_number AS related_invoice_number,
                CASE WHEN invref.invoice_id IS NULL THEN 0 ELSE 1 END AS has_invoice
            FROM billing_user_items i
            JOIN local_users u ON u.id = i.user_id
            LEFT JOIN billing_tax_rates t ON t.id = i.tax_rate_id
            LEFT JOIN (
                SELECT
                    ii.source_id,
                    MIN(inv.id) AS invoice_id,
                    MIN(inv.invoice_number) AS invoice_number
                FROM invoice_items ii
                JOIN invoices inv
                    ON inv.id = ii.invoice_id
                    AND inv.status <> 'cancelled'
                WHERE ii.source_type = 'user_item'
                GROUP BY ii.source_id
            ) invref
                ON invref.source_id = i.id
        ";
        if ($activeOnly) {
            $sql .= ' WHERE i.active = 1';
        }
        $sql .= ' ORDER BY u.display_name, i.description';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function billingUserItemsByUserId(bool $activeOnly = false): array
    {
        $items = [];
        foreach ($this->billingUserItems($activeOnly) as $item) {
            $items[(int)$item['user_id']][] = $item;
        }
        return $items;
    }

    public function saveBillingUserItem(array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        $userId = (int)($data['user_id'] ?? 0);
        $description = trim((string)($data['description'] ?? ''));
        if ($userId <= 0 || $description === '') {
            throw new InvalidArgumentException(t('billing.item_required'));
        }
        $bookingDate = (string)($data['booking_date'] ?? '');
        if ($bookingDate === '') {
            throw new InvalidArgumentException(t('billing.booking_date_invalid'));
        }
        $allowPastBookingDate = !empty($data['allow_past_booking_date']);
        try {
            $bookingDay = new DateTimeImmutable($bookingDate);
        } catch (Throwable) {
            throw new InvalidArgumentException(t('billing.booking_date_invalid'));
        }
        if (!$allowPastBookingDate && $bookingDay < new DateTimeImmutable('today')) {
            throw new InvalidArgumentException(t('billing.booking_date_invalid'));
        }
        $frequency = (string)($data['frequency'] ?? 'monthly');
        if (!in_array($frequency, ['once', 'monthly', 'bimonthly', 'quarterly', 'halfyearly', 'yearly'], true)) {
            $frequency = 'monthly';
        }
        $values = [$userId, $description, trim((string)($data['description_text'] ?? '')), $data['amount'] ?? 0, ($data['tax_rate_id'] ?? '') !== '' ? (int)$data['tax_rate_id'] : null, $frequency, $bookingDate, $data['next_billing_at'] ?: $bookingDate, isset($data['active']) ? 1 : 0];
        if ($id > 0) {
            $stmt = $this->pdo->prepare('UPDATE billing_user_items SET user_id = ?, description = ?, description_text = ?, amount = ?, tax_rate_id = ?, frequency = ?, booking_date = ?, next_billing_at = ?, active = ? WHERE id = ?');
            $stmt->execute([...$values, $id]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO billing_user_items(user_id, description, description_text, amount, tax_rate_id, frequency, booking_date, next_billing_at, active) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute($values);
        }
        $this->audit('admin', 'billing_user_item_saved', 'billing_user_item', $id ?: (int)$this->pdo->lastInsertId());
    }

    public function updateUserItemBilling(int $id, string $lastBilledAt, string $nextBillingAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE billing_user_items SET last_billed_at = ?, next_billing_at = ? WHERE id = ?');
        $stmt->execute([$lastBilledAt, $nextBillingAt, $id]);
    }

    public function deactivateUserItem(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE billing_user_items SET active = 0 WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function restoreUserItemBillingFromInvoice(int $id, string $serviceDate): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE billing_user_items
            SET active = 1,
                last_billed_at = NULL,
                next_billing_at = ?
            WHERE id = ?
        ');
        $stmt->execute([$serviceDate, $id]);
    }

    public function deleteBillingUserItem(int $id): void
    {
        $invoiceNumber = $this->billingUserItemInvoiceNumber($id);
        if ($invoiceNumber !== null) {
            throw new RuntimeException(t('billing.user_item_has_invoice', ['invoice' => $invoiceNumber]));
        }
        $this->deleteUnprotectedPendingBillingItemsBySource('user_item', $id);
        $stmt = $this->pdo->prepare('DELETE FROM billing_user_items WHERE id = ?');
        $stmt->execute([$id]);
        $this->audit('admin', 'billing_user_item_deleted', 'billing_user_item', $id);
    }

    private function billingUserItemInvoiceNumber(int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('
            SELECT inv.invoice_number
            FROM invoice_items ii
            JOIN invoices inv
                ON inv.id = ii.invoice_id
                AND inv.status <> ?
            WHERE ii.source_type = ?
                AND ii.source_id = ?
            ORDER BY inv.created_at DESC, inv.id DESC
            LIMIT 1
        ');
        $stmt->execute(['cancelled', 'user_item', $id]);
        $invoiceNumber = $stmt->fetchColumn();
        return $invoiceNumber === false ? null : (string)$invoiceNumber;
    }

    public function addPendingBillingItem(array $item): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO billing_pending_items(
                user_id,
                source_type,
                source_id,
                description,
                quantity,
                unit_price,
                discount_percent,
                tax_rate_id,
                tax_rate_percent,
                net_total,
                tax_total,
                gross_total,
                service_date,
                billing_reference
            ) VALUES(?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                source_type = VALUES(source_type),
                source_id = VALUES(source_id),
                description = VALUES(description),
                unit_price = VALUES(unit_price),
                discount_percent = VALUES(discount_percent),
                tax_rate_id = VALUES(tax_rate_id),
                tax_rate_percent = VALUES(tax_rate_percent),
                net_total = VALUES(net_total),
                tax_total = VALUES(tax_total),
                gross_total = VALUES(gross_total),
                service_date = VALUES(service_date)
        ');
        $stmt->execute([
            $item['user_id'], $item['source_type'], $item['source_id'], $item['description'], $item['unit_price'], $item['discount_percent'], $item['tax_rate_id'], $item['tax_rate_percent'], $item['net_total'], $item['tax_total'], $item['gross_total'], $item['service_date'], $item['billing_reference'],
        ]);
        return min(1, $stmt->rowCount());
    }

    public function invoiceItemReferenceStatuses(array $references, ?int $excludeInvoiceId = null): array
    {
        $references = array_values(array_unique(array_filter(array_map('strval', $references))));
        if ($references === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($references), '?'));
        $params = $references;
        $excludeClause = '';
        if ($excludeInvoiceId !== null) {
            $excludeClause = ' AND i.id <> ?';
            $params[] = $excludeInvoiceId;
        }
        $stmt = $this->pdo->prepare('
            SELECT ii.billing_reference, i.status, i.invoice_number
            FROM invoice_items ii
            JOIN invoices i ON i.id = ii.invoice_id
            WHERE ii.billing_reference IN (' . $placeholders . ')' . $excludeClause . '
            ORDER BY
                ii.billing_reference,
                CASE WHEN i.status = \'cancelled\' THEN 1 ELSE 0 END,
                i.id DESC
        ');
        $stmt->execute($params);
        $statuses = [];
        foreach ($stmt->fetchAll() as $row) {
            $reference = (string)$row['billing_reference'];
            if (isset($statuses[$reference])) {
                continue;
            }
            $statuses[$reference] = [
                'status' => (string)$row['status'],
                'invoice_number' => (string)$row['invoice_number'],
            ];
        }
        return $statuses;
    }

    public function invoiceItemSourceStatus(string $sourceType, int $sourceId, ?string $serviceDate, ?int $excludeInvoiceId = null): ?array
    {
        if ($sourceType === '' || $sourceId <= 0) {
            return null;
        }
        $params = [$sourceType, $sourceId];
        $dateClause = ' AND ii.service_date IS NULL';
        if ($serviceDate !== null && $serviceDate !== '') {
            $dateClause = ' AND ii.service_date = ?';
            $params[] = $serviceDate;
        }
        $excludeClause = '';
        if ($excludeInvoiceId !== null) {
            $excludeClause = ' AND i.id <> ?';
            $params[] = $excludeInvoiceId;
        }
        $stmt = $this->pdo->prepare('
            SELECT ii.billing_reference, i.status, i.invoice_number
            FROM invoice_items ii
            JOIN invoices i ON i.id = ii.invoice_id
            WHERE ii.source_type = ?
                AND ii.source_id = ?
                ' . $dateClause . $excludeClause . '
            ORDER BY
                CASE WHEN i.status = \'cancelled\' THEN 1 ELSE 0 END,
                i.id DESC
            LIMIT 1
        ');
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'status' => (string)$row['status'],
            'invoice_number' => (string)$row['invoice_number'],
            'billing_reference' => (string)$row['billing_reference'],
        ];
    }

    public function pendingBillingItems(): array
    {
        return $this->pdo->query("
            SELECT
                p.*,
                u.display_name AS username,
                '' AS server_name,
                COALESCE(invref.invoice_id, invsrc.invoice_id) AS related_invoice_id,
                COALESCE(invref.invoice_number, invsrc.invoice_number) AS related_invoice_number,
                COALESCE(invref.status, invsrc.status) AS related_invoice_status
            FROM billing_pending_items p
            JOIN local_users u ON u.id = p.user_id
            LEFT JOIN (
                SELECT
                    ii.billing_reference,
                    MIN(inv.id) AS invoice_id,
                    MIN(inv.invoice_number) AS invoice_number,
                    MIN(inv.status) AS status
                FROM invoice_items ii
                JOIN invoices inv
                    ON inv.id = ii.invoice_id
                    AND inv.status <> 'cancelled'
                GROUP BY ii.billing_reference
            ) invref
                ON invref.billing_reference = p.billing_reference
            LEFT JOIN (
                SELECT
                    ii.source_type,
                    ii.source_id,
                    ii.service_date,
                    MIN(inv.id) AS invoice_id,
                    MIN(inv.invoice_number) AS invoice_number,
                    MIN(inv.status) AS status
                FROM invoice_items ii
                JOIN invoices inv
                    ON inv.id = ii.invoice_id
                    AND inv.status <> 'cancelled'
                GROUP BY ii.source_type, ii.source_id, ii.service_date
            ) invsrc
                ON invsrc.source_type = p.source_type
                AND invsrc.source_id = p.source_id
                AND invsrc.service_date <=> p.service_date
            ORDER BY p.created_at DESC
        ")->fetchAll();
    }

    public function danglingPendingBillingItems(): array
    {
        return $this->pdo->query("
            SELECT
                p.*,
                u.display_name AS username,
                '' AS server_name,
                CASE
                    WHEN p.source_type = 'user_item' AND bui.id IS NULL THEN 'missing_user_item'
                    WHEN p.source_type = 'user_item' AND bui.active = 0 THEN 'inactive_user_item'
                    WHEN p.source_type LIKE 'domain_%' AND d.id IS NULL THEN 'missing_domain'
                    WHEN inv.id IS NOT NULL THEN 'active_invoice'
                    ELSE 'unknown'
                END AS dangling_reason,
                inv.id AS related_invoice_id,
                inv.invoice_number AS related_invoice_number,
                inv.status AS related_invoice_status,
                CASE
                    WHEN inv.id IS NOT NULL THEN 0
                    ELSE 1
                END AS can_delete
            FROM billing_pending_items p
            JOIN local_users u ON u.id = p.user_id
            LEFT JOIN billing_user_items bui
                ON p.source_type = 'user_item'
                AND bui.id = p.source_id
            LEFT JOIN domains d
                ON p.source_type LIKE 'domain_%'
                AND d.id = p.source_id
            LEFT JOIN (
                SELECT
                    ii.billing_reference,
                    MIN(inv.id) AS invoice_id
                FROM invoice_items ii
                JOIN invoices inv
                    ON inv.id = ii.invoice_id
                    AND inv.status <> 'cancelled'
                GROUP BY ii.billing_reference
            ) invref
                ON invref.billing_reference = p.billing_reference
            LEFT JOIN invoices inv
                ON inv.id = invref.invoice_id
            WHERE
                (p.source_type = 'user_item' AND (bui.id IS NULL OR bui.active = 0))
                OR (p.source_type LIKE 'domain_%' AND d.id IS NULL)
                OR inv.id IS NOT NULL
            ORDER BY p.created_at DESC, p.id DESC
        ")->fetchAll();
    }

    public function billingUsersWithPendingItems(): array
    {
        return $this->pdo->query('SELECT DISTINCT u.*, u.display_name AS username, "" AS server_name FROM billing_pending_items p JOIN local_users u ON u.id = p.user_id ORDER BY u.display_name')->fetchAll();
    }

    public function pendingBillingItemsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM billing_pending_items WHERE user_id = ? ORDER BY service_date, id');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function deletePendingBillingItems(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('DELETE FROM billing_pending_items WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
    }

    public function deletePendingBillingItem(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(t('billing.pending_item_required'));
        }
        $stmt = $this->pdo->prepare('
            SELECT inv.invoice_number
            FROM billing_pending_items p
            JOIN invoice_items ii ON ii.billing_reference = p.billing_reference
            JOIN invoices inv ON inv.id = ii.invoice_id
            WHERE p.id = ? AND inv.status <> ?
            LIMIT 1
        ');
        $stmt->execute([$id, 'cancelled']);
        $invoiceNumber = $stmt->fetchColumn();
        if ($invoiceNumber) {
            throw new RuntimeException(t('billing.pending_item_has_invoice', ['invoice' => (string)$invoiceNumber]));
        }
        $this->deletePendingBillingItems([$id]);
        $this->audit('admin', 'billing_pending_item_deleted', 'billing_pending_item', $id);
    }

    private function deleteUnprotectedPendingBillingItemsBySource(string $sourceType, int $sourceId): void
    {
        if ($sourceType === '' || $sourceId <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare('
            DELETE p
            FROM billing_pending_items p
            WHERE p.source_type = ?
                AND p.source_id = ?
                AND NOT EXISTS (
                    SELECT 1
                    FROM invoice_items ii
                    JOIN invoices inv ON inv.id = ii.invoice_id
                    WHERE ii.billing_reference = p.billing_reference
                        AND inv.status <> ?
                )
        ');
        $stmt->execute([$sourceType, $sourceId, 'cancelled']);
    }

    public function deletePendingDomainBillingItems(int $domainId, array $sourceTypes): void
    {
        $sourceTypes = array_values(array_unique(array_filter(array_map('strval', $sourceTypes))));
        if ($domainId <= 0 || $sourceTypes === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($sourceTypes), '?'));
        $stmt = $this->pdo->prepare(
            'DELETE p
            FROM billing_pending_items p
            WHERE p.source_id = ?
                AND p.source_type IN (' . $placeholders . ')
                AND NOT EXISTS (
                    SELECT 1
                    FROM invoice_items ii
                    JOIN invoices inv ON inv.id = ii.invoice_id
                    WHERE ii.billing_reference = p.billing_reference
                        AND inv.status <> ?
                )'
        );
        $stmt->execute([$domainId, ...$sourceTypes, 'cancelled']);
    }

    public function billingLastRunAt(): string
    {
        return $this->billingSetting('last_run_at', '1970-01-01 00:00:00');
    }

    public function saveBillingLastRunAt(DateTimeImmutable $date): void
    {
        $this->saveBillingSetting('last_run_at', $date->format('Y-m-d H:i:s'));
    }

    public function createBillingRun(DateTimeImmutable $lastRun, DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO billing_runs(status, last_run_at, current_until, message) VALUES(?, ?, ?, ?)');
        $stmt->execute(['running', $lastRun->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), '']);
        return (int)$this->pdo->lastInsertId();
    }

    public function finishBillingRun(int $id, string $status, string $message): void
    {
        $stmt = $this->pdo->prepare('UPDATE billing_runs SET status = ?, message = ?, finished_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$status, $message, $id]);
    }

    public function updateDomainNextBilling(int $domainId, string $date): void
    {
        $stmt = $this->pdo->prepare('UPDATE domains SET next_billing_at = ? WHERE id = ?');
        $stmt->execute([$date, $domainId]);
    }

    public function nextInvoiceSequence(string $date): int
    {
        $prefix = str_replace('-', '', $date);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM invoices WHERE invoice_number LIKE ?');
        $stmt->execute([$prefix . '%']);
        return (int)$stmt->fetchColumn() + 1;
    }

    public function invoiceNumberExists(string $number): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM invoices WHERE invoice_number = ?');
        $stmt->execute([$number]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function createInvoice(int $userId, string $number, array $items, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd, array $recipientSnapshot, string $senderSnapshot): int
    {
        $subtotal = $tax = $total = 0;
        $userDiscount = min(100.0, max(0.0, (float)($recipientSnapshot['billing_discount_percent'] ?? 0)));
        $discountFactor = (100.0 - $userDiscount) / 100.0;
        foreach ($items as $item) {
            $itemNet = $this->decimalToCents($item['net_total']);
            $discountedNet = $itemNet > 0
                ? (int)round($itemNet * $discountFactor, 0, PHP_ROUND_HALF_UP)
                : $itemNet;
            $itemTax = (int)round($discountedNet * max(0.0, (float)($item['tax_rate_percent'] ?? 0)) / 100.0, 0, PHP_ROUND_HALF_UP);
            $subtotal += $itemNet;
            $tax += $itemTax;
            $total += $discountedNet + $itemTax;
        }
        $stmt = $this->pdo->prepare('INSERT INTO invoices(user_id, invoice_number, status, subtotal, tax_total, total, period_start, period_end, recipient_snapshot, sender_snapshot) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $number, 'pending_approval', $this->centsToDecimal($subtotal), $this->centsToDecimal($tax), $this->centsToDecimal($total), $periodStart->format('Y-m-d H:i:s'), $periodEnd->format('Y-m-d H:i:s'), json_encode($recipientSnapshot, JSON_UNESCAPED_UNICODE), $senderSnapshot]);
        $invoiceId = (int)$this->pdo->lastInsertId();
        $itemStmt = $this->pdo->prepare('INSERT INTO invoice_items(invoice_id, source_type, source_id, description, quantity, unit_price, discount_percent, tax_rate_id, tax_rate_percent, net_total, tax_total, gross_total, service_date, billing_reference) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($items as $item) {
            $itemStmt->execute([$invoiceId, $item['source_type'], $item['source_id'], $item['description'], $item['quantity'], $item['unit_price'], $item['discount_percent'], $item['tax_rate_id'], $item['tax_rate_percent'], $item['net_total'], $item['tax_total'], $item['gross_total'], $item['service_date'], $item['billing_reference']]);
        }
        return $invoiceId;
    }

    public function setInvoicePdf(int $invoiceId, string $pdfPath): void
    {
        $stmt = $this->pdo->prepare('UPDATE invoices SET pdf_path = ? WHERE id = ?');
        $stmt->execute([$pdfPath, $invoiceId]);
    }

    public function updateInvoiceRecipientSnapshot(int $invoiceId, array $snapshot): void
    {
        $stmt = $this->pdo->prepare('UPDATE invoices SET recipient_snapshot = ?, pdf_path = NULL WHERE id = ? AND immutable_at IS NULL');
        $stmt->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $invoiceId]);
    }

    public function invoices(): array
    {
        return $this->pdo->query('
            SELECT
                i.*,
                u.display_name AS username,
                u.email,
                u.invoice_email,
                "" AS server_name,
                COALESCE(payments.paid_total, 0) AS paid_total,
                GREATEST(i.total - COALESCE(payments.paid_total, 0), 0) AS open_total
            FROM invoices i
            JOIN local_users u ON u.id = i.user_id
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS paid_total
                FROM billing_payment_allocations
                GROUP BY invoice_id
            ) payments ON payments.invoice_id = i.id
            ORDER BY i.created_at DESC, i.id DESC
        ')->fetchAll();
    }

    public function queuedInvoices(): array
    {
        return $this->pdo->query("SELECT i.*, u.display_name AS username, u.email, u.invoice_email FROM invoices i JOIN local_users u ON u.id = i.user_id WHERE i.status = 'queued' ORDER BY i.created_at, i.id")->fetchAll();
    }

    public function invoice(int $id): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                i.*,
                u.display_name AS username,
                u.email,
                u.invoice_email,
                "" AS server_name,
                COALESCE(payments.paid_total, 0) AS paid_total,
                GREATEST(i.total - COALESCE(payments.paid_total, 0), 0) AS open_total
            FROM invoices i
            JOIN local_users u ON u.id = i.user_id
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS paid_total
                FROM billing_payment_allocations
                GROUP BY invoice_id
            ) payments ON payments.invoice_id = i.id
            WHERE i.id = ?
        ');
        $stmt->execute([$id]);
        $invoice = $stmt->fetch();
        return $invoice ?: null;
    }

    public function invoiceItems(int $invoiceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id');
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    public function deleteInvoice(int $invoiceId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
    }

    public function setInvoiceStatus(int $invoiceId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE invoices SET status = ?, approved_at = IF(? IN (\'approved\', \'queued\'), CURRENT_TIMESTAMP, approved_at) WHERE id = ? AND immutable_at IS NULL');
        $stmt->execute([$status, $status, $invoiceId]);
    }

    public function markInvoiceSent(int $invoiceId, string $pdfPath): void
    {
        $stmt = $this->pdo->prepare("UPDATE invoices SET status = 'sent', pdf_path = ?, sent_at = CURRENT_TIMESTAMP, immutable_at = CURRENT_TIMESTAMP, send_error = NULL WHERE id = ?");
        $stmt->execute([$pdfPath, $invoiceId]);
    }

    public function markInvoiceFailed(int $invoiceId, string $error): void
    {
        $stmt = $this->pdo->prepare("UPDATE invoices SET status = 'failed', send_error = ? WHERE id = ? AND immutable_at IS NULL");
        $stmt->execute([$error, $invoiceId]);
    }

    public function markInvoicePaid(int $invoiceId, string $paidAt, string $reference, string $note): void
    {
        $stmt = $this->pdo->prepare('UPDATE invoices SET paid_at = ?, payment_reference = ?, payment_note = ? WHERE id = ?');
        $stmt->execute([
            $paidAt,
            $reference !== '' ? $reference : null,
            $note !== '' ? $note : null,
            $invoiceId,
        ]);
    }

    public function recordCustomerPayment(int $userId, string $paidAt, mixed $amount, string $reference, string $note): array
    {
        $amountCents = $this->decimalToCents($amount);
        if ($userId <= 0 || $amountCents === 0) {
            throw new InvalidArgumentException(t('billing.payment_amount_required'));
        }
        return $this->transaction(function () use ($userId, $paidAt, $amountCents, $reference, $note): array {
            $stmt = $this->pdo->prepare('INSERT INTO billing_payments(user_id, paid_at, amount, reference, note) VALUES(?, ?, ?, ?, ?)');
            $stmt->execute([
                $userId,
                $paidAt,
                $this->centsToDecimal($amountCents),
                $reference !== '' ? $reference : null,
                $note !== '' ? $note : null,
            ]);
            $paymentId = (int)$this->pdo->lastInsertId();
            if ($amountCents < 0) {
                return [
                    'payment_id' => $paymentId,
                    'allocated' => '0.00',
                    'credit' => $this->centsToDecimal($amountCents),
                    'paid_invoices' => 0,
                    'partial_invoices' => 0,
                ];
            }
            $remaining = $amountCents;
            $allocated = 0;
            $paidInvoices = 0;
            $partialInvoices = 0;
            $invoiceStmt = $this->pdo->prepare("
                SELECT
                    i.id,
                    i.invoice_number,
                    i.total,
                    COALESCE(SUM(a.amount), 0) AS paid_total
                FROM invoices i
                LEFT JOIN billing_payment_allocations a ON a.invoice_id = i.id
                WHERE i.user_id = ? AND i.status IN ('approved', 'queued', 'sent')
                GROUP BY i.id
                HAVING i.total > paid_total
                ORDER BY COALESCE(i.period_end, i.created_at), i.created_at, i.id
            ");
            $invoiceStmt->execute([$userId]);
            $allocationStmt = $this->pdo->prepare('INSERT INTO billing_payment_allocations(payment_id, invoice_id, amount) VALUES(?, ?, ?)');
            foreach ($invoiceStmt->fetchAll() as $invoice) {
                if ($remaining <= 0) {
                    break;
                }
                $open = max(0, $this->decimalToCents($invoice['total']) - $this->decimalToCents($invoice['paid_total']));
                if ($open <= 0) {
                    continue;
                }
                $share = min($remaining, $open);
                $allocationStmt->execute([$paymentId, (int)$invoice['id'], $this->centsToDecimal($share)]);
                $remaining -= $share;
                $allocated += $share;
                if ($share >= $open) {
                    $paidInvoices++;
                    $this->markInvoicePaid((int)$invoice['id'], $paidAt, $reference, $note);
                } else {
                    $partialInvoices++;
                }
            }
            return [
                'payment_id' => $paymentId,
                'allocated' => $this->centsToDecimal($allocated),
                'credit' => $this->centsToDecimal($remaining),
                'paid_invoices' => $paidInvoices,
                'partial_invoices' => $partialInvoices,
            ];
        });
    }

    public function customerAccountBalancesByUserId(): array
    {
        $balances = [];
        foreach ($this->pdo->query('
            SELECT
                users.user_id,
                COALESCE(payments.total_paid, 0) - COALESCE(invoices.total_invoiced, 0) AS balance
            FROM (
                SELECT user_id FROM billing_payments
                UNION
                SELECT user_id FROM invoices WHERE status IN ("approved", "queued", "sent")
            ) users
            LEFT JOIN (
                SELECT user_id, SUM(amount) AS total_paid
                FROM billing_payments
                GROUP BY user_id
            ) payments ON payments.user_id = users.user_id
            LEFT JOIN (
                SELECT user_id, SUM(total) AS total_invoiced
                FROM invoices
                WHERE status IN ("approved", "queued", "sent")
                GROUP BY user_id
            ) invoices ON invoices.user_id = users.user_id
        ')->fetchAll() as $row) {
            $balances[(int)$row['user_id']] = $row['balance'];
        }
        return $balances;
    }

    public function customerAccountPendingTotalsByUserId(): array
    {
        $totals = [];
        foreach ($this->pdo->query('
            SELECT
                i.user_id,
                SUM(ABS(i.total)) AS pending_total
            FROM invoices i
            WHERE i.status IN ("draft", "pending_approval", "failed")
            GROUP BY i.user_id
        ')->fetchAll() as $row) {
            $totals[(int)$row['user_id']] = $row['pending_total'];
        }
        return $totals;
    }

    public function customerAccountEntriesByUserId(): array
    {
        $entries = [];
        foreach ($this->pdo->query('
            SELECT
                p.user_id,
                p.paid_at AS entry_date,
                p.created_at AS sort_date,
                CONCAT("payment:", p.id) AS entry_key,
                "payment" AS entry_type,
                p.reference,
                p.note,
                NULL AS invoice_number,
                NULL AS invoice_id,
                NULL AS status,
                p.amount AS amount,
                NULL AS open_total
            FROM billing_payments p
            UNION ALL
            SELECT
                i.user_id,
                DATE(COALESCE(i.period_end, i.created_at)) AS entry_date,
                i.created_at AS sort_date,
                CONCAT("invoice:", i.id) AS entry_key,
                "invoice" AS entry_type,
                NULL AS reference,
                NULL AS note,
                i.invoice_number,
                i.id AS invoice_id,
                i.status,
                -i.total AS amount,
                GREATEST(i.total - COALESCE(payments.paid_total, 0), 0) AS open_total
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS paid_total
                FROM billing_payment_allocations
                GROUP BY invoice_id
            ) payments ON payments.invoice_id = i.id
            WHERE i.status <> "cancelled"
            ORDER BY entry_date DESC, sort_date DESC, entry_key DESC
        ')->fetchAll() as $entry) {
            $entries[(int)$entry['user_id']][] = $entry;
        }
        return $entries;
    }

    public function customerAccountForUser(int $userId): array
    {
        return [
            'balance' => $this->customerAccountBalancesByUserId()[$userId] ?? '0.00',
            'pending_total' => $this->customerAccountPendingTotalsByUserId()[$userId] ?? '0.00',
            'entries' => $this->customerAccountEntriesByUserId()[$userId] ?? [],
        ];
    }

    private function decimalToCents(mixed $value): int
    {
        $value = trim(str_replace(',', '.', (string)($value ?? '0')));
        if ($value === '') {
            return 0;
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$euros, $cents] = array_pad(explode('.', $value, 2), 2, '0');
        $amount = ((int)$euros * 100) + (int)substr(str_pad($cents, 2, '0'), 0, 2);
        return $negative ? -$amount : $amount;
    }

    private function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function billingAuditLog(): array
    {
        return $this->pdo->query('SELECT * FROM billing_audit_log ORDER BY created_at DESC, id DESC LIMIT 50')->fetchAll();
    }

    public function audit(string $actor, string $action, ?string $entityType = null, ?int $entityId = null, array $details = []): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO billing_audit_log(actor, action, entity_type, entity_id, details_json) VALUES(?, ?, ?, ?, ?)');
        $stmt->execute([$actor, $action, $entityType, $entityId, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
