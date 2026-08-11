<?php
final class SyncService
{
    private array $clientsByServer = [];

    public function __construct(private array $config, private Repository $repo) {}

    public function dashboardServers(): array
    {
        return array_map(fn(array $server): array => $this->dashboardServerEntry($server), $this->repo->servers(true));
    }

    public function dashboardServer(int $serverId): array
    {
        $server = $this->repo->server($serverId);
        if (!$server) {
            throw new RuntimeException(t('message.server_not_found'));
        }
        return $this->dashboardServerEntry($server);
    }
    public function rebootServer(int $serverId): string
    {
        $server = $this->repo->server($serverId);
        if (!$server) {
            throw new RuntimeException(t('message.server_not_found'));
        }
        (new KeyHelpClient($this->config, $server))->rebootServer();
        return t('message.server_reboot_started', ['name' => ($server['name'] ?? t('common.unknown'))]);
    }

    public function userOverview(): array
    {
        return $this->repo->usersByServer();
    }

    public function deleteServer(int $serverId): string
    {
        $server = $this->repo->server($serverId);
        if (!$server) {
            throw new RuntimeException(t('message.server_not_found'));
        }
        $name = (string)($server['name'] ?? t('common.unknown'));
        $this->repo->deleteServer($serverId);
        return t('message.server_updated', ['name' => $name]);
    }

    public function deleteHostingPackage(int $packageId): string
    {
        $package = $this->repo->package($packageId);
        if (!$package) {
            throw new RuntimeException('Hostingpaket nicht gefunden.');
        }
        $name = (string)($package['name'] ?? t('common.unknown'));
        $marker = $this->hostingPackageMarker($package, $packageId);
        foreach ($this->repo->packages() as $candidate) {
            if ($this->hostingPackageMarker($candidate, (int)$candidate['id']) !== $marker) {
                continue;
            }
            $externalId = (string)($candidate['external_id'] ?? '');
            $serverId = (int)($candidate['server_id'] ?? 0);
            if ($externalId !== '' && $serverId > 0) {
                $server = $this->repo->server($serverId);
                if (!$server) {
                    throw new RuntimeException(t('message.server_not_found'));
                }
                (new KeyHelpClient($this->config, $server))->deleteHostingPackage($externalId);
            }
            $this->repo->deletePackage((int)$candidate['id']);
        }
        return t('message.hosting_created', ['name' => $name]);
    }
    public function importUsers(?int $serverId = null): string
    {
        $count = 0;
        foreach ($this->targetServers($serverId) as $server) {
            $client = new KeyHelpClient($this->config, $server);
            $users = array_values(array_filter($this->normalizeList($client->listUsers()), 'is_array'));
            $externalIds = [];
            foreach ($users as $user) {
                $externalId = $this->repo->userExternalId($user);
                if ($externalId === '') {
                    continue;
                }
                try {
                    $detail = $this->normalizeItem($client->getClient($externalId));
                    if ($detail !== []) {
                        $user = array_replace_recursive($user, $detail);
                    }
                } catch (Throwable) {
                    // Keep the list representation if the detail endpoint is unavailable.
                }
                $externalIds[] = $externalId;
                $this->repo->saveUser((int)$server['id'], $user);
                $count++;
            }
            $this->repo->deleteUsersExcept((int)$server['id'], $externalIds);
        }
        return t('message.users_imported', ['count' => $count]);
    }

    public function createUser(int $serverId, array $data): string
    {
        $server = $this->repo->server($serverId);
        if (!$server) {
            throw new RuntimeException(t('message.server_not_found'));
        }
        $payload = $this->createUserPayload($data);
        $client = new KeyHelpClient($this->config, $server);
        $response = $client->createUser($payload);
        $externalId = $this->createdUserId($response);
        if ($externalId === '') {
            $externalId = $this->createdUserIdFromList($client, (string)($data['username'] ?? ''));
        }
        if ($externalId === '') {
            throw new RuntimeException(t('message.user_created', ['name' => ($data['username'] ?? t('common.unknown'))]) . ' Die neue KeyHelp-Benutzer-ID konnte nicht ermittelt werden.');
        }
        $this->applyTemporaryHostingPlan($client, $externalId, $data, (string)($data['username'] ?? ''));
        $this->importUsers($serverId);
        $localUserId = (int)($data['local_user_id'] ?? 0);
        $remoteUser = $this->repo->userByExternalId($serverId, $externalId);
        if ($localUserId > 0 && $remoteUser) {
            $this->repo->assignRemoteUserToLocalUser((int)$remoteUser['id'], $localUserId);
        }
        return t('message.user_created', ['name' => ($data['username'] ?? t('common.unknown'))]);
    }

    public function updateUser(int $localUserId, array $data): string
    {
        $user = $this->repo->user($localUserId);
        if (!$user) {
            throw new RuntimeException(t('message.user_not_found'));
        }
        $server = $this->repo->server((int)$user['server_id']);
        if (!$server) {
            throw new RuntimeException(t('message.server_not_found'));
        }
        $payload = $this->createUserPayload($data);
        $payload['username'] = (string)$user['username'];
        if (trim((string)($data['password'] ?? '')) === '') {
            unset($payload['password']);
        }
        $client = new KeyHelpClient($this->config, $server);
        $client->updateUser((string)$user['external_id'], $payload);
        $this->applyTemporaryHostingPlan($client, (string)$user['external_id'], $data, (string)($user['username'] ?? ''));
        $this->importUsers((int)$user['server_id']);
        return t('message.user_updated', ['name' => ($user['username'] ?? t('common.unknown'))]);
    }

    public function syncLocalContactToRemoteUsers(int $localUserId): int
    {
        $localUser = $this->repo->localUser($localUserId);
        if (!$localUser) {
            throw new RuntimeException(t('message.user_not_found'));
        }
        $count = 0;
        foreach (($this->repo->remoteUsersByLocalUserId()[$localUserId] ?? []) as $remoteUser) {
            $server = $this->repo->server((int)$remoteUser['server_id']);
            if (!$server) {
                throw new RuntimeException(t('message.server_not_found'));
            }
            $raw = json_decode((string)($remoteUser['raw_json'] ?? '{}'), true) ?: [];
            $payload = [
                'username' => (string)$remoteUser['username'],
                'language' => (string)($raw['language'] ?? 'de'),
                'email' => (string)($localUser['email'] ?? $remoteUser['email'] ?? ''),
                'notes' => (string)($localUser['notes'] ?? ''),
                'contact_data' => [
                    'first_name' => (string)($localUser['first_name'] ?? ''),
                    'last_name' => (string)($localUser['last_name'] ?? ''),
                    'company' => (string)($localUser['company'] ?? ''),
                    'telephone' => (string)($localUser['phone'] ?? ''),
                    'address' => (string)($localUser['address'] ?? ''),
                    'city' => (string)($localUser['city'] ?? ''),
                    'zip' => (string)($localUser['postcode'] ?? ''),
                    'state' => (string)($localUser['region'] ?? ''),
                    'country' => (string)($localUser['country'] ?? ''),
                    'client_id' => (string)($localUser['customer_number'] ?? ''),
                ],
            ];
            (new KeyHelpClient($this->config, $server))->updateUser((string)$remoteUser['external_id'], $payload);
            $count++;
        }
        return $count;
    }

    public function createHostingPackage(array $data): string
    {
        $payload = $this->hostingPackagePayload($data);
        $marker = $this->newHostingPackageMarker();
        $payload = $this->withHostingPackageMarker($payload, $marker);
        foreach ($this->selectedHostingPackageServers($data) as $server) {
            $client = new KeyHelpClient($this->config, $server);
            $externalId = $this->saveMarkedHostingPackageOnServer($client, $payload, $marker);
            $this->repo->saveHostingPlan((int)$server['id'], ['id' => $externalId] + $payload + ['description' => (string)($data['description'] ?? '')]);
        }
        return t('message.hosting_created', ['name' => ($payload['name'] ?: t('common.unknown'))]);
    }

    public function updateHostingPackage(array $data): string
    {
        $localId = (int)($data['id'] ?? 0);
        if ($localId <= 0) {
            throw new RuntimeException('Hostingpaket nicht gefunden.');
        }
        $current = $this->repo->package($localId);
        if (!$current) {
            throw new RuntimeException('Hostingpaket nicht gefunden.');
        }

        $payload = $this->hostingPackagePayload($data);
        $marker = $this->hostingPackageMarker($current, $localId);
        $payload = $this->withHostingPackageMarker($payload, $marker);
        $externalId = (string)($current['external_id'] ?? '');
        $currentServerId = (int)($current['server_id'] ?? 0);
        $targets = $this->selectedHostingPackageServers($data);
        $targetIds = array_map(static fn(array $server): int => (int)$server['id'], $targets);

        foreach ($targets as $server) {
            $serverId = (int)$server['id'];
            $client = new KeyHelpClient($this->config, $server);
            $savedExternalId = $this->saveMarkedHostingPackageOnServer($client, $payload, $marker, $currentServerId === $serverId ? $externalId : '');
            $this->repo->saveHostingPlan($serverId, ['id' => $savedExternalId] + $payload + ['description' => (string)($data['description'] ?? '')]);
        }

        $this->deleteMarkedHostingPackageFromUnselectedServers($marker, $targetIds, $currentServerId, $externalId);
        if ($currentServerId <= 0 || !in_array($currentServerId, $targetIds, true)) {
            $this->repo->deletePackage($localId);
        }
        return t('message.hosting_created', ['name' => ($payload['name'] ?: t('common.unknown'))]);
    }
    public function deleteUser(int $localUserId): string
    {
        $user = $this->repo->user($localUserId);
        if (!$user) {
            throw new RuntimeException(t('message.user_not_found'));
        }
        $server = $this->repo->server((int)$user['server_id']);
        if (!$server) {
            throw new RuntimeException(t('message.server_not_found'));
        }
        (new KeyHelpClient($this->config, $server))->deleteUser((string)$user['external_id']);
        $this->repo->deleteUser($localUserId);
        return t('message.user_deleted', ['name' => ($user['username'] ?? t('common.unknown'))]);
    }

    public function userLoginUrl(int $localUserId): string
    {
        $user = $this->repo->user($localUserId);
        if (!$user) {
            throw new RuntimeException(t('message.user_not_found'));
        }
        $server = $this->repo->server((int)$user['server_id']);
        if (!$server) {
            throw new RuntimeException(t('message.server_not_found'));
        }
        $response = (new KeyHelpClient($this->config, $server))->userLoginUrl((string)$user['external_id']);
        $url = (string)($response['url'] ?? '');
        if ($url === '') {
            throw new RuntimeException(t('message.user_login_failed'));
        }
        return $url;
    }

    private function createUserPayload(array $data): array
    {
        $payload = [
            'username' => trim((string)($data['username'] ?? '')),
            'language' => (string)($data['language'] ?? 'de'),
            'email' => trim((string)($data['email'] ?? '')),
            'password' => (string)($data['password'] ?? ''),
            'notes' => (string)($data['notes'] ?? ''),
            'contact_data' => [
                'first_name' => (string)($data['first_name'] ?? ''),
                'last_name' => (string)($data['last_name'] ?? ''),
                'company' => (string)($data['company'] ?? ''),
                'telephone' => (string)($data['phone'] ?? ''),
                'address' => (string)($data['address'] ?? ''),
                'city' => (string)($data['city'] ?? ''),
                'zip' => (string)($data['postcode'] ?? ''),
                'state' => (string)($data['region'] ?? ''),
                'country' => (string)($data['country'] ?? ''),
                'client_id' => (string)($data['customer_number'] ?? ''),
            ],
            'is_suspended' => !empty($data['account_locked']),
        ];
        $hostingPlanId = (int)($data['hosting_plan_id'] ?? 0);
        if ($hostingPlanId > 0) {
            $payload['id_hosting_plan'] = $hostingPlanId;
        }

        $lockOn = $this->keyHelpDateExpression($data['lock_on'] ?? null);
        if ($lockOn !== null) {
            if ($this->dateIsTodayOrPast($lockOn)) {
                $payload['is_suspended'] = true;
            } else {
                $payload['suspend_on'] = $lockOn;
            }
        }
        $deleteOn = $this->keyHelpDateExpression($data['delete_on'] ?? null);
        if ($deleteOn !== null) {
            $payload['delete_on'] = $deleteOn;
        }

        return $payload;
    }

    private function keyHelpDateExpression(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        try {
            $timezone = new DateTimeZone((string)($this->config['app']['timezone'] ?? 'Europe/Berlin'));
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
            return (new DateTimeImmutable($value, $timezone))->format('Y-m-d');
        } catch (Throwable) {
            return $value;
        }
    }

    private function dateIsTodayOrPast(string $value): bool
    {
        try {
            $timezone = new DateTimeZone((string)($this->config['app']['timezone'] ?? 'Europe/Berlin'));
            $date = (new DateTimeImmutable($value, $timezone))->setTime(0, 0);
            $today = (new DateTimeImmutable('today', $timezone))->setTime(0, 0);
            return $date <= $today;
        } catch (Throwable) {
            return false;
        }
    }

    private function phpOnlyPayload(array $data): array
    {
        return [
        ];
    }

    private function createdUserId(array $response): string
    {
        foreach (['id', 'client_id', 'user_id'] as $key) {
            if (isset($response[$key]) && trim((string)$response[$key]) !== '') {
                return trim((string)$response[$key]);
            }
        }
        foreach (['data', 'item', 'client', 'user'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $id = $this->createdUserId($response[$key]);
                if ($id !== '') {
                    return $id;
                }
            }
        }
        return '';
    }

    private function createdUserIdFromList(KeyHelpClient $client, string $username): string
    {
        $username = strtolower(trim($username));
        if ($username === '') {
            return '';
        }
        foreach ($this->normalizeList($client->listUsers()) as $user) {
            if (!is_array($user) || strtolower((string)($user['username'] ?? '')) !== $username) {
                continue;
            }
            return $this->repo->userExternalId($user);
        }
        return '';
    }

    private function resourceLimitsPayload(array $data): array
    {
        $limits = [];
        foreach (['disk_space', 'traffic'] as $field) {
            $limits[$field] = !empty($data[$field . '_unlimited'])
                ? -1
                : $this->byteLimit((string)($data[$field] ?? '0'), (string)($data[$field . '_unit'] ?? 'MiB'));
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
            if (!$this->resourcePermissionAllows($formField, $data)) {
                $limits[$apiField] = 0;
                continue;
            }
            $value = (int)($data[$formField] ?? 0);
            $limits[$apiField] = !empty($data[$formField . '_unlimited']) || $value < 0 ? -1 : $value;
        }

        return $limits;
    }

    private function resourcePermissionAllows(string $formField, array $data): bool
    {
        return match ($formField) {
            'ftp_users' => !empty($data['permission_ftp']),
            default => true,
        };
    }

    private function byteLimit(string $value, string $unit): int
    {
        $number = max(0, (int)$value);
        return match ($unit) {
            'GiB' => $number * 1024 * 1024 * 1024,
            default => $number * 1024 * 1024,
        };
    }

    private function permissionsPayload(array $data): array
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


    private function phpPayload(array $data): array
    {
        $payload = [
            'memory_limit' => (string)($data['php_memory_limit'] ?? '128M'),
            'max_execution_time' => (int)($data['php_max_execution_time'] ?? 60),
            'post_max_size' => (string)($data['php_post_max_size'] ?? '72M'),
            'upload_max_filesize' => (string)($data['php_upload_max_filesize'] ?? '64M'),
            'open_basedir' => (string)($data['php_open_basedir'] ?? ''),
            'disable_functions' => (string)($data['php_disable_functions'] ?? ''),
            'env_variables' => (string)($data['php_environment_variables'] ?? ''),
            'extra_directives_immutable' => (string)($data['php_extra_directives_immutable'] ?? ''),
            'extra_directives_mutable' => (string)($data['php_extra_directives_mutable'] ?? ''),
        ];
        $sendmailFrom = trim((string)($data['php_sendmail_from'] ?? ''));
        if ($sendmailFrom !== '') {
            $payload['sendmail_from'] = $sendmailFrom;
        }
        return $payload;
    }

    private function phpFpmPayload(array $data): array
    {
        return [
            'pm' => (string)($data['php_fpm_pm'] ?? 'ondemand'),
            'max_children' => max(1, (int)($data['php_fpm_max_children'] ?? 3)),
            'max_requests' => max(0, (int)($data['php_fpm_max_requests'] ?? 0)),
            'status' => !empty($data['php_fpm_status_enabled']),
            'status_ip_restriction' => trim((string)($data['php_fpm_status_ips'] ?? '')) ?: null,
        ];
    }

    private function hostingPackagePayload(array $data): array
    {
        return [
            'name' => trim((string)($data['name'] ?? '')),
            'resources' => $this->resourceLimitsPayload($data),
            'permissions' => $this->permissionsPayload($data),
            'php' => $this->phpPayload($data),
            'php_fpm' => $this->phpFpmPayload($data),
        ];
    }
    private function temporaryHostingPlanPayload(array $data, string $username): array
    {
        $safeUser = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $username) ?: 'user';
        return [
            'name' => sprintf('khmsm_tmp_%s_%s_%s', $safeUser, gmdate('YmdHis'), bin2hex(random_bytes(3))),
            'resources' => $this->resourceLimitsPayload($data),
            'permissions' => $this->permissionsPayload($data),
            'php' => $this->phpPayload($data),
            'php_fpm' => $this->phpFpmPayload($data),
        ];
    }

    private function applyTemporaryHostingPlan(KeyHelpClient $client, string|int $externalUserId, array $data, string $username): void
    {
        if ((int)($data['hosting_plan_id'] ?? 0) > 0) {
            return;
        }

        $planPayload = $this->temporaryHostingPlanPayload($data, $username);
        $planResponse = $client->createHostingPackage($planPayload);
        $planId = $this->createdHostingPlanId($planResponse);
        if ($planId === '') {
            $planId = $this->createdHostingPlanIdFromList($client, (string)$planPayload['name']);
        }
        if ($planId === '') {
            throw new RuntimeException('Das temporaere Hostingpaket wurde angelegt, aber die KeyHelp-Paket-ID konnte nicht ermittelt werden.');
        }

        $assignmentPayload = $this->createUserPayload($data);
        $assignmentPayload['username'] = $username;
        $assignmentPayload['id_hosting_plan'] = (int)$planId;
        if (trim((string)($data['password'] ?? '')) === '') {
            unset($assignmentPayload['password']);
        }

        try {
            $client->updateUser($externalUserId, $assignmentPayload);
        } catch (Throwable $e) {
            $this->deleteTemporaryHostingPlan($client, $planId);
            throw $e;
        }

        $this->deleteTemporaryHostingPlan($client, $planId);
    }

    private function deleteTemporaryHostingPlan(KeyHelpClient $client, string|int $planId): void
    {
        try {
            $client->deleteHostingPackage($planId);
        } catch (Throwable $e) {
            if (function_exists('log_exception')) {
                log_exception($this->config, $e, 'Temporaeres Hostingpaket konnte nicht geloescht werden.', ['hosting_plan_id' => $planId]);
            }
        }
    }

    private function createdHostingPlanId(array $response): string
    {
        foreach (['id', 'hosting_plan_id', 'plan_id'] as $key) {
            if (isset($response[$key]) && trim((string)$response[$key]) !== '') {
                return trim((string)$response[$key]);
            }
        }
        foreach (['data', 'item', 'hosting_plan', 'plan'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $id = $this->createdHostingPlanId($response[$key]);
                if ($id !== '') {
                    return $id;
                }
            }
        }
        return '';
    }

    private function createdHostingPlanIdFromList(KeyHelpClient $client, string $name): string
    {
        foreach ($this->normalizeList($client->listHostingPlans()) as $plan) {
            if (!is_array($plan) || (string)($plan['name'] ?? '') !== $name) {
                continue;
            }
            return trim((string)($plan['id'] ?? $plan['external_id'] ?? ''));
        }
        return '';
    }

    private function saveMarkedHostingPackageOnServer(KeyHelpClient $client, array $payload, string $marker, string $fallbackExternalId = ''): string
    {
        $externalId = $this->hostingPlanIdByMarker($client, $marker);
        if ($externalId !== '') {
            $client->updateHostingPackage($externalId, $payload);
            return $externalId;
        }

        if ($fallbackExternalId !== '' && $this->hostingPlanIdExists($client, $fallbackExternalId)) {
            $client->updateHostingPackage($fallbackExternalId, $payload);
            return $fallbackExternalId;
        }

        $response = $client->createHostingPackage($payload);
        $externalId = $this->createdHostingPlanId($response);
        if ($externalId === '') {
            $externalId = $this->createdHostingPlanIdFromList($client, (string)$payload['name']);
        }
        if ($externalId === '') {
            throw new RuntimeException('Das Hostingpaket wurde angelegt, aber die KeyHelp-Paket-ID konnte nicht ermittelt werden.');
        }
        return $externalId;
    }

    private function deleteMarkedHostingPackageFromUnselectedServers(string $marker, array $selectedServerIds, int $currentServerId = 0, string $currentExternalId = ''): void
    {
        $selectedServerIds = array_map('intval', $selectedServerIds);
        foreach ($this->repo->servers(true) as $server) {
            $serverId = (int)$server['id'];
            if (in_array($serverId, $selectedServerIds, true)) {
                continue;
            }
            $client = new KeyHelpClient($this->config, $server);
            $externalId = $this->hostingPlanIdByMarker($client, $marker);
            if ($externalId === '' && $serverId === $currentServerId && $currentExternalId !== '') {
                $externalId = $currentExternalId;
            }
            if ($externalId !== '') {
                $client->deleteHostingPackage($externalId);
            }
        }

        foreach ($this->repo->packages() as $package) {
            $serverId = (int)($package['server_id'] ?? 0);
            if ($serverId <= 0 || in_array($serverId, $selectedServerIds, true)) {
                continue;
            }
            if ($this->hostingPackageMarker($package, (int)$package['id']) === $marker) {
                $this->repo->deletePackage((int)$package['id']);
            }
        }
    }

    private function selectedHostingPackageServers(array $data): array
    {
        $selected = $data['server_ids'] ?? [];
        if (!is_array($selected)) {
            $selected = [$selected];
        }
        $selected = array_values(array_filter(array_map('strval', $selected), static fn(string $value): bool => $value !== ''));
        if ($selected === [] && (string)($data['server_id'] ?? '') !== '') {
            $selected = [(string)$data['server_id']];
        }
        if ($selected === [] && !empty($data['server_selection_present'])) {
            throw new RuntimeException('Bitte mindestens einen Server auswaehlen.');
        }
        if ($selected === [] || in_array('__all', $selected, true)) {
            return $this->targetServers(null);
        }
        $ids = array_map('intval', $selected);
        return array_values(array_filter($this->targetServers(null), static fn(array $server): bool => in_array((int)$server['id'], $ids, true)));
    }

    private function hostingPlanIdByMarker(KeyHelpClient $client, string $marker): string
    {
        foreach ($this->normalizeList($client->listHostingPlans()) as $plan) {
            if (!is_array($plan) || $this->extractHostingPackageMarker((string)($plan['name'] ?? '')) !== $marker) {
                continue;
            }
            return trim((string)($plan['id'] ?? $plan['external_id'] ?? ''));
        }
        return '';
    }

    private function hostingPlanIdExists(KeyHelpClient $client, string $externalId): bool
    {
        foreach ($this->normalizeList($client->listHostingPlans()) as $plan) {
            if (!is_array($plan)) {
                continue;
            }
            if (trim((string)($plan['id'] ?? $plan['external_id'] ?? '')) === $externalId) {
                return true;
            }
        }
        return false;
    }

    private function hostingPackageMarker(array $package, int $localId): string
    {
        $marker = $this->extractHostingPackageMarker((string)($package['name'] ?? ''));
        if ($marker !== '') {
            return $marker;
        }
        $limits = json_decode((string)($package['limits_json'] ?? ''), true);
        if (is_array($limits)) {
            $marker = $this->extractHostingPackageMarker((string)($limits['name'] ?? ''));
            if ($marker !== '') {
                return $marker;
            }
        }
        return 'pkg-' . max(1, $localId);
    }

    private function newHostingPackageMarker(): string
    {
        return 'pkg-' . bin2hex(random_bytes(6));
    }

    private function withHostingPackageMarker(array $payload, string $marker): array
    {
        $name = $this->removeHostingPackageMarker(trim((string)($payload['name'] ?? '')));
        $payload['name'] = trim($name . ' [MSM:' . $marker . ']');
        return $payload;
    }

    private function extractHostingPackageMarker(string $name): string
    {
        if (preg_match('/\[MSM:([a-zA-Z0-9_-]+)\]/', $name, $matches) === 1) {
            return $matches[1];
        }
        return '';
    }

    private function removeHostingPackageMarker(string $name): string
    {
        return trim((string)preg_replace('/\s*\[MSM:[a-zA-Z0-9_-]+\]\s*/', ' ', $name));
    }

    public function importDomains(): string
    {
        $count = 0;
        foreach ($this->repo->servers(true) as $server) {
            $client = new KeyHelpClient($this->config, $server);
            $domains = $this->normalizeList($client->listDomains());
            $usersById = $this->usersById($client);
            $mainDomains = $this->mainDomains($domains, $server);
            $this->repo->deleteDomainsExcept((int)$server['id'], array_map(fn($domain) => $this->domainName($domain), $mainDomains));
            foreach ($mainDomains as $domain) {
                $this->repo->saveDomain((int)$server['id'], $this->withOwnerDetails($client, $domain, $usersById), $usersById);
                $count++;
            }
        }
        return t('message.domains_imported', ['count' => $count]);
    }

    public function importHostingPlans(): string
    {
        $count = 0;
        foreach ($this->repo->servers(true) as $server) {
            $client = new KeyHelpClient($this->config, $server);
            $plans = array_values(array_filter($this->normalizeList($client->listHostingPlans()), 'is_array'));
            $externalIds = [];
            foreach ($plans as $plan) {
                $externalId = (string)($plan['id'] ?? $plan['external_id'] ?? '');
                if ($externalId === '') {
                    continue;
                }
                $externalIds[] = $externalId;
                $this->repo->saveHostingPlan((int)$server['id'], $plan);
                $count++;
            }
            $this->repo->deleteHostingPlansExcept((int)$server['id'], $externalIds);
        }
        return t('message.hosting_imported', ['count' => $count]);
    }

    public function subdomainsFor(int $serverId, string $domainName): array
    {
        $server = $this->repo->server($serverId);
        if (!$server) {
            throw new RuntimeException(t('message.server_not_found'));
        }
        $client = new KeyHelpClient($this->config, $server);
        $usersById = $this->usersById($client);
        $domains = $this->withoutSystemDomains($this->normalizeList($client->listDomains()), $server);
        $result = [];
        foreach ($domains as $domain) {
            $name = $this->domainName($domain);
            if ($name !== null && $this->isSubdomainOf($name, $domainName)) {
                $domain = $this->withOwnerDetails($client, $domain, $usersById);
                $result[] = [
                    'domain' => $name,
                    'owner' => DomainOwner::name($domain, $usersById),
                ];
            }
        }
        usort($result, fn($a, $b) => strcmp($a['domain'], $b['domain']));
        return $result;
    }

    public function refreshDomain(int $domainId): array
    {
        $localDomain = $this->repo->domain($domainId);
        if (!$localDomain) {
            throw new RuntimeException(t('message.domain_not_found'));
        }

        $server = $this->repo->server((int)$localDomain['server_id']);
        if (!$server) {
            throw new RuntimeException(t('message.server_not_found'));
        }

        $client = new KeyHelpClient($this->config, $server);
        $domains = $this->normalizeList($client->listDomains());
        $mainDomains = $this->mainDomains($domains, $server);
        $targetName = $this->domainName($localDomain);
        $freshDomain = null;

        foreach ($mainDomains as $domain) {
            if ($this->domainName($domain) === $targetName) {
                $freshDomain = $domain;
                break;
            }
        }

        if ($freshDomain === null) {
            $this->repo->markDomainDeleted($domainId);
            $deletedDomain = $this->repo->domain($domainId);
            return [
                'status' => 'deleted',
                'message' => t('message.domain_deleted', ['name' => ($targetName ?: t('common.unknown'))]),
                'domain' => $deletedDomain,
            ];
        }

        $usersById = $this->usersById($client);
        $this->repo->saveDomain((int)$server['id'], $this->withOwnerDetails($client, $freshDomain, $usersById), $usersById);
        $updatedDomain = $this->repo->domain($domainId);
        if (!$updatedDomain) {
            throw new RuntimeException(t('message.domain_reload_failed'));
        }

        return [
            'status' => 'updated',
            'message' => t('message.domain_refreshed', ['name' => ($updatedDomain['domain'] ?? $targetName ?? t('common.unknown'))]),
            'domain' => $updatedDomain,
        ];
    }

    public function runQueue(): string
    {
        // Legacy/reserve path: UI-triggered queued sync is currently disabled because changes are applied immediately.
        $runId = $this->repo->createSyncRun('running');
        $done = 0;
        try {
            foreach ($this->repo->actions() as $action) {
                $payload = json_decode($action['payload_json'], true) ?: [];
                $servers = $this->targetServers($action['server_id']);
                foreach ($servers as $server) {
                    $client = new KeyHelpClient($this->config, $server);
                    $result = match ($action['type']) {
                        'create_user' => $client->createUser($payload),
                        'create_hosting_package' => $client->createHostingPackage($payload),
                        'update_hosting_package' => $client->updateHostingPackage((string)($payload['id'] ?? ''), is_array($payload['payload'] ?? null) ? $payload['payload'] : []),
                        default => throw new RuntimeException('Unknown action: ' . $action['type']),
                    };
                }
                $this->repo->markAction((int)$action['id'], 'done', $result ?? []);
                $done++;
            }
            $message = t('message.actions_transferred', ['count' => $done]);
            $this->repo->finishSyncRun($runId, 'done', $message);
            return $message;
        } catch (Throwable $e) {
            if (isset($action)) {
                $this->repo->markAction((int)$action['id'], 'failed', ['error' => $e->getMessage()]);
            }
            $message = t('message.sync_stopped', ['message' => $e->getMessage()]);
            $this->repo->finishSyncRun($runId, 'failed', $message);
            throw new RuntimeException($message, 0, $e);
        }
    }

    private function dashboardServerEntry(array $server): array
    {
        try {
            $client = new KeyHelpClient($this->config, $server);
            return [
                'server' => $server,
                'info' => $this->normalizeItem($client->serverInfo()),
                'error' => '',
            ];
        } catch (Throwable $e) {
            $this->logViewError($e, 'Serverstatus konnte nicht geladen werden.', $server);
            return [
                'server' => $server,
                'info' => [],
                'error' => t('message.server_status_failed'),
            ];
        }
    }

    private function logViewError(Throwable $exception, string $message, array $server): void
    {
        if (function_exists('log_exception')) {
            log_exception($this->config, $exception, $message, [
                'server_id' => $server['id'] ?? null,
                'server' => $server['name'] ?? '',
            ]);
        }
    }

    private function mainDomains(array $domains, array $server): array
    {
        $domains = $this->withoutSystemDomains($domains, $server);
        $names = array_values(array_filter(array_map(fn($domain) => $this->domainName($domain), $domains)));
        return array_values(array_filter($domains, function (array $domain) use ($names): bool {
            $name = $this->domainName($domain);
            if ($name === null) {
                return false;
            }
            foreach ($names as $candidate) {
                if ($this->isSubdomainOf($name, $candidate)) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function withoutSystemDomains(array $domains, array $server): array
    {
        $serverHost = strtolower((string)(parse_url($server['base_url'], PHP_URL_HOST) ?: ''));
        return array_values(array_filter($domains, function (array $domain) use ($serverHost): bool {
            $name = $this->domainName($domain);
            if ($name === null) {
                return false;
            }
            return $serverHost === '' || ($name !== $serverHost && !$this->isSubdomainOf($name, $serverHost));
        }));
    }

    private function withOwnerDetails(KeyHelpClient $client, array $domain, array $usersById): array
    {
        $id = $domain['id'] ?? null;
        if ($id !== null && (string)$id !== '') {
            try {
                $detail = $this->normalizeItem($client->getDomain($id));
                if ($detail !== []) {
                    $domain = array_replace_recursive($domain, $detail);
                }
            } catch (Throwable) {
                // Keep the list response if the detail endpoint is unavailable.
            }
        }

        $ownerId = DomainOwner::id($domain);
        $ownerName = DomainOwner::name($domain, $usersById);
        if ($ownerId !== '' && ($ownerName === '' || $ownerName === 'User #' . $ownerId)) {
            $clientData = $this->clientById($client, $ownerId);
            if ($clientData !== []) {
                $domain['client'] = $clientData;
            }
        }
        return $domain;
    }

    private function clientById(KeyHelpClient $client, string $id): array
    {
        $serverKey = spl_object_id($client);
        if (isset($this->clientsByServer[$serverKey][$id])) {
            return $this->clientsByServer[$serverKey][$id];
        }
        try {
            $clientData = $this->normalizeItem($client->getClient($id));
        } catch (Throwable) {
            $clientData = [];
        }
        $this->clientsByServer[$serverKey][$id] = $clientData;
        return $clientData;
    }

    private function usersById(KeyHelpClient $client): array
    {
        try {
            return DomainOwner::userIndex($this->normalizeList($client->listUsers()));
        } catch (Throwable) {
            return [];
        }
    }

    private function domainName(array $domain): ?string
    {
        $name = $domain['domain'] ?? $domain['name'] ?? $domain['domainName'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return null;
        }
        return strtolower(trim($name, " \t\n\r\0\x0B."));
    }

    private function isSubdomainOf(string $name, string $parent): bool
    {
        $name = strtolower(trim($name, '.'));
        $parent = strtolower(trim($parent, '.'));
        return $name !== $parent && str_ends_with($name, '.' . $parent);
    }

    private function targetServers(?int $serverId): array
    {
        $servers = $this->repo->servers(true);
        if (!$serverId) {
            return $servers;
        }
        return array_values(array_filter($servers, fn($server) => (int)$server['id'] === $serverId));
    }

    private function normalizeItem(array $response): array
    {
        foreach (['data', 'item', 'domain'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }
        return $response;
    }

    private function normalizeList(array $response): array
    {
        if (array_is_list($response)) {
            return $response;
        }
        foreach (['data', 'items', 'domains', 'users', 'hosting_plans', 'hostingPlans', 'plans'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }
        return [];
    }
}
