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
        return $this->setting('username_pattern', '{{servername_short}}_user_{{NUMMER:2}}');
    }

    public function updateUsernamePattern(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            $pattern = '{{servername_short}}_user_{{NUMMER:2}}';
        }
        $this->saveSetting('username_pattern', $pattern);
        return $pattern;
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
            SELECT u.*, s.name AS server_name
            FROM keyhelp_users u
            JOIN servers s ON s.id = u.server_id
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
        $stmt = $this->pdo->prepare('INSERT INTO domains(server_id, external_id, domain, owner_external_id, owner_name, registered_at, next_billing_at, registrar, domain_status, is_disabled, suspend_on, delete_on, synced_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE external_id = VALUES(external_id), owner_external_id = VALUES(owner_external_id), owner_name = VALUES(owner_name), domain_status = VALUES(domain_status), is_disabled = VALUES(is_disabled), suspend_on = VALUES(suspend_on), delete_on = VALUES(delete_on), synced_at = CURRENT_TIMESTAMP');
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
        $serverName = $this->asciiToken((string)($server['name'] ?? 'server'));
        $username = str_replace(
            ['{{servername}}', '{{servername_short}}'],
            [$serverName, substr($serverName, 0, 6)],
            $pattern
        );
        $username = preg_replace_callback('/\{\{NUMMER(?::(\d+))?\}\}/', static function (array $match) use ($number): string {
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
        $stmt = $this->pdo->prepare('UPDATE domains SET registered_at = ?, next_billing_at = ?, billing_frequency = ?, last_change_at = ?, registrar = ?, domain_owner_contact = ?, domain_admin_c = ?, domain_tech_c = ?, domain_zone_c = ? WHERE id = ?');
        $stmt->execute([
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
        $format = trim((string)($data['invoice_number_format'] ?? '{{JAHR}}{{MONAT}}{{TAG}}-{{LFNR}}'));
        if ($format === '') {
            $format = '{{JAHR}}{{MONAT}}{{TAG}}-{{LFNR}}';
        }
        $allowed = ['JAHR', 'MONAT', 'TAG', 'LFNR', 'USERID', 'USERNAME'];
        preg_match_all('/\{\{([A-Z_]+)(?::(\d+))?\}\}/', $format, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $variable = $match[1];
            if (!in_array($variable, $allowed, true)) {
                throw new RuntimeException(t('billing.invalid_invoice_format'));
            }
            if (isset($match[2]) && !in_array($variable, ['LFNR', 'USERID'], true)) {
                throw new RuntimeException(t('billing.invalid_invoice_format'));
            }
        }
        $withoutVariables = preg_replace('/\{\{[A-Z_]+(?::\d+)?\}\}/', '', $format) ?? '';
        if (preg_match('/\b(?:JAHR|MONAT|TAG|LFNR|USERID|USERNAME)\b/', $withoutVariables)) {
            throw new RuntimeException(t('billing.invalid_invoice_format'));
        }
        $this->saveBillingSetting('invoice_sender', trim((string)($data['invoice_sender'] ?? '')));
        $this->saveBillingSetting('invoice_notification_recipients', trim((string)($data['invoice_notification_recipients'] ?? '')));
        $this->saveBillingSetting('payment_account_details', trim((string)($data['payment_account_details'] ?? '')));
        $this->saveBillingSetting('invoice_number_format', $format);
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
                'invoice_number_format' => $this->billingSetting('invoice_number_format', '{{JAHR}}{{MONAT}}{{TAG}}-{{LFNR}}'),
                'invoice_template_html' => $this->billingSetting('invoice_template_html', InvoicePdfRenderer::defaultTemplate()),
                'dunning_template_html' => $this->billingSetting('dunning_template_html', InvoicePdfRenderer::defaultDunningTemplate()),
                'last_run_at' => $this->billingLastRunAt(),
            ],
            'taxRates' => $this->billingTaxRates(),
            'tldPrices' => $this->billingTldPrices(),
            'domainOverrides' => $this->billingDomainOverrides(),
            'userSettings' => $this->billingUserSettingsByUserId(),
            'userItems' => $this->billingUserItems(false),
            'userItemsByUserId' => $this->billingUserItemsByUserId(false),
            'pendingItems' => $this->pendingBillingItems(),
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
        return $row ?: ['user_id' => $userId, 'discount_percent' => 0, 'invoice_frequency' => 'monthly', 'last_invoice_at' => null, 'next_invoice_at' => null];
    }

    public function saveBillingUserSettings(array $data): void
    {
        $userId = (int)($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException(t('billing.user_required'));
        }
        $frequency = (string)($data['invoice_frequency'] ?? 'monthly');
        if (!in_array($frequency, ['immediate', 'weekly', 'monthly'], true)) {
            $frequency = 'monthly';
        }
        $stmt = $this->pdo->prepare('INSERT INTO billing_user_settings(user_id, discount_percent, invoice_frequency) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE discount_percent = VALUES(discount_percent), invoice_frequency = VALUES(invoice_frequency)');
        $stmt->execute([$userId, $data['discount_percent'] ?? 0, $frequency]);
        $this->audit('admin', 'billing_user_settings_saved', 'user', $userId);
    }

    public function updateUserInvoiceSchedule(int $userId, string $frequency, DateTimeImmutable $now): void
    {
        $next = match ($frequency) {
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
        return $this->pdo->query('SELECT u.*, s.name AS server_name FROM keyhelp_users u JOIN servers s ON s.id = u.server_id ORDER BY s.name, u.username')->fetchAll();
    }

    public function usersFlatByServerExternalId(): array
    {
        $users = [];
        foreach ($this->usersFlat() as $user) {
            $users[(int)$user['server_id'] . ':' . (string)$user['external_id']] = $user;
        }
        return $users;
    }

    public function billingUserItems(bool $activeOnly): array
    {
        $sql = 'SELECT i.*, u.username, u.email, s.name AS server_name, t.name AS tax_name, t.rate_percent FROM billing_user_items i JOIN keyhelp_users u ON u.id = i.user_id JOIN servers s ON s.id = u.server_id LEFT JOIN billing_tax_rates t ON t.id = i.tax_rate_id';
        if ($activeOnly) {
            $sql .= ' WHERE i.active = 1';
        }
        $sql .= ' ORDER BY s.name, u.username, i.description';
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

    public function deleteBillingUserItem(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM billing_user_items WHERE id = ?');
        $stmt->execute([$id]);
        $this->audit('admin', 'billing_user_item_deleted', 'billing_user_item', $id);
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

    public function invoiceItemReferenceStatuses(array $references): array
    {
        $references = array_values(array_unique(array_filter(array_map('strval', $references))));
        if ($references === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($references), '?'));
        $stmt = $this->pdo->prepare('
            SELECT ii.billing_reference, i.status, i.invoice_number
            FROM invoice_items ii
            JOIN invoices i ON i.id = ii.invoice_id
            WHERE ii.billing_reference IN (' . $placeholders . ')
        ');
        $stmt->execute($references);
        $statuses = [];
        foreach ($stmt->fetchAll() as $row) {
            $statuses[(string)$row['billing_reference']] = [
                'status' => (string)$row['status'],
                'invoice_number' => (string)$row['invoice_number'],
            ];
        }
        return $statuses;
    }

    public function pendingBillingItems(): array
    {
        return $this->pdo->query('SELECT p.*, u.username, s.name AS server_name FROM billing_pending_items p JOIN keyhelp_users u ON u.id = p.user_id JOIN servers s ON s.id = u.server_id ORDER BY p.created_at DESC')->fetchAll();
    }

    public function billingUsersWithPendingItems(): array
    {
        return $this->pdo->query('SELECT DISTINCT u.*, s.name AS server_name FROM billing_pending_items p JOIN keyhelp_users u ON u.id = p.user_id JOIN servers s ON s.id = u.server_id ORDER BY s.name, u.username')->fetchAll();
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
        return $this->pdo->query('SELECT i.*, u.username, u.email, s.name AS server_name FROM invoices i JOIN keyhelp_users u ON u.id = i.user_id JOIN servers s ON s.id = u.server_id ORDER BY i.created_at DESC, i.id DESC')->fetchAll();
    }

    public function queuedInvoices(): array
    {
        return $this->pdo->query("SELECT i.*, u.username, u.email FROM invoices i JOIN keyhelp_users u ON u.id = i.user_id WHERE i.status = 'queued' ORDER BY i.created_at, i.id")->fetchAll();
    }

    public function invoice(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT i.*, u.username, u.email, s.name AS server_name FROM invoices i JOIN keyhelp_users u ON u.id = i.user_id JOIN servers s ON s.id = u.server_id WHERE i.id = ?');
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
