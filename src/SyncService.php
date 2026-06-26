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

    public function userOverview(): array
    {
        $result = [];
        foreach ($this->repo->servers(true) as $server) {
            try {
                $client = new KeyHelpClient($this->config, $server);
                $users = $this->normalizeList($client->listUsers());
                foreach ($users as &$user) {
                    if (is_array($user)) {
                        $user['_server_id'] = (int)$server['id'];
                        $user['_server_name'] = $server['name'];
                    }
                }
                unset($user);
                $result[] = [
                    'server' => $server,
                    'users' => array_values(array_filter($users, 'is_array')),
                    'error' => '',
                ];
            } catch (Throwable $e) {
                $this->logViewError($e, 'Benutzerliste konnte nicht geladen werden.', $server);
                $result[] = [
                    'server' => $server,
                    'users' => [],
                    'error' => t('message.users_load_failed'),
                ];
            }
        }
        return $result;
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
            $this->repo->deleteDomain($domainId);
            return [
                'status' => 'deleted',
                'message' => t('message.domain_deleted', ['name' => ($targetName ?: t('common.unknown'))]),
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
