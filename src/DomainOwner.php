<?php
final class DomainOwner
{
    public static function id(array $domain): string
    {
        foreach (['id_user', 'ownerId', 'owner_id', 'userId', 'user_id', 'clientId', 'client_id', 'customerId', 'customer_id', 'adminId', 'admin_id'] as $key) {
            if (isset($domain[$key]) && (string)$domain[$key] !== '') {
                return (string)$domain[$key];
            }
        }
        foreach (['owner', 'user', 'client', 'customer', 'account', 'admin'] as $key) {
            if (isset($domain[$key]) && is_array($domain[$key])) {
                foreach (['id', 'uid', 'id_user', 'userId', 'clientId', 'customerId', 'adminId'] as $nestedKey) {
                    if (isset($domain[$key][$nestedKey]) && (string)$domain[$key][$nestedKey] !== '') {
                        return (string)$domain[$key][$nestedKey];
                    }
                }
            }
        }
        return '';
    }

    public static function name(array $domain, array $usersById = []): string
    {
        foreach (['ownerName', 'owner_name', 'userName', 'user_name', 'username', 'login', 'clientName', 'client_name', 'customerName', 'customer_name', 'adminName', 'admin_name', 'email'] as $key) {
            if (isset($domain[$key]) && trim((string)$domain[$key]) !== '') {
                return (string)$domain[$key];
            }
        }
        foreach (['owner', 'user', 'client', 'customer', 'account', 'admin'] as $key) {
            if (isset($domain[$key]) && is_array($domain[$key])) {
                foreach (['username', 'login', 'userName', 'name', 'displayName', 'email'] as $nestedKey) {
                    if (isset($domain[$key][$nestedKey]) && trim((string)$domain[$key][$nestedKey]) !== '') {
                        return (string)$domain[$key][$nestedKey];
                    }
                }
            }
            if (isset($domain[$key]) && is_string($domain[$key]) && trim($domain[$key]) !== '') {
                return $domain[$key];
            }
        }
        $ownerId = self::id($domain);
        if ($ownerId !== '' && isset($usersById[$ownerId])) {
            return self::userLabel($usersById[$ownerId], $ownerId);
        }
        return $ownerId !== '' ? 'User #' . $ownerId : '';
    }

    public static function userIndex(array $users): array
    {
        $index = [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            foreach (['id', 'uid', 'id_user', 'userId', 'clientId', 'customerId'] as $key) {
                if (isset($user[$key]) && (string)$user[$key] !== '') {
                    $index[(string)$user[$key]] = $user;
                }
            }
        }
        return $index;
    }

    private static function userLabel(array $user, string $fallbackId): string
    {
        foreach (['username', 'login', 'userName', 'name', 'displayName', 'email'] as $key) {
            if (isset($user[$key]) && trim((string)$user[$key]) !== '') {
                return (string)$user[$key];
            }
        }
        if (isset($user['contact_data']) && is_array($user['contact_data'])) {
            $firstName = trim((string)($user['contact_data']['first_name'] ?? ''));
            $lastName = trim((string)($user['contact_data']['last_name'] ?? ''));
            $fullName = trim($firstName . ' ' . $lastName);
            if ($fullName !== '') {
                return $fullName;
            }
        }
        return 'User #' . $fallbackId;
    }
}