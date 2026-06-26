<?php
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_with(string $message, string $type = 'ok'): never
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    $target = (string)($_POST['_return'] ?? '/');
    if ($target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
        $target = '/';
    }
    header('Location: ' . $target);
    exit;
}

function locale_url(string $locale): string
{
    $params = $_GET;
    $params['lang'] = $locale;
    $query = http_build_query($params);
    return '/?' . $query;
}

function log_exception(array $config, Throwable $exception, string $message, array $context = []): void
{
    if (class_exists('Logger')) {
        (new Logger($config))->log(1, $message, $context + [
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

function user_error_message(string $action): string
{
    return match ($action) {
        'import_domains' => t('message.import_domains_failed'),
        'import_hosting_plans' => t('message.import_hosting_plans_failed'),
        'run_sync' => t('message.sync_failed'),
        'update_domain' => t('message.update_domain_failed'),
        'refresh_domain' => t('message.refresh_domain_failed'),
        'update_server' => t('message.update_server_failed'),
        default => t('message.generic_action_failed'),
    };
}

function domain_row_class(array $domain): string
{
    if (!empty($domain['delete_on'])) {
        return 'domain-delete-pending';
    }
    if (!empty($domain['is_disabled']) || ((string)($domain['domain_status'] ?? '') !== '' && (int)$domain['domain_status'] !== 1)) {
        return 'domain-disabled';
    }
    if ((int)($domain['duplicate_server_count'] ?? 0) > 1) {
        return 'domain-duplicate';
    }
    return '';
}

function domain_status_html(array $domain): string
{
    if (!empty($domain['delete_on'])) {
        return '<span class="domain-state delete" title="' . h(t('domains.deletion_pending')) . '"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eraser status-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M8.086 2.207a2 2 0 0 1 2.828 0l3.879 3.879a2 2 0 0 1 0 2.828l-5.5 5.5A2 2 0 0 1 7.879 15H5.12a2 2 0 0 1-1.414-.586L.793 11.5a2 2 0 0 1 0-2.828zm2.121.707a1 1 0 0 0-1.414 0L4.16 7.547l5.293 5.293 4.633-4.633a1 1 0 0 0 0-1.414zM8.746 13.547 3.453 8.254 1.5 10.207a1 1 0 0 0 0 1.414l2.914 2.914a1 1 0 0 0 .707.293H7.88a1 1 0 0 0 .707-.293z"/></svg><span>' . h(format_date_local($domain['delete_on'])) . '</span></span>';
    }
    if (!empty($domain['is_disabled']) || ((string)($domain['domain_status'] ?? '') !== '' && (int)$domain['domain_status'] !== 1)) {
        return '<span class="domain-state locked" title="' . h(t('domains.locked_or_disabled')) . '"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-lock status-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2m3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2M5 8h6a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1"/></svg><span>' . h(t('domains.locked')) . '</span></span>';
    }
    return '';
}

function value_path(array $data, string $path, mixed $fallback = null): mixed
{
    $current = $data;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return $fallback;
        }
        $current = $current[$segment];
    }
    return $current;
}

function format_date_local(null|string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable) {
        return $value;
    }
    return current_locale() === 'en' ? $date->format('m/d/Y') : $date->format('d.m.Y');
}

function locale_number(float $value, int $decimals): string
{
    return current_locale() === 'en'
        ? number_format($value, $decimals, '.', ',')
        : number_format($value, $decimals, ',', '.');
}

function format_bytes_de(null|int|float|string $bytes): string
{
    if ($bytes === null || $bytes === '') {
        return '-';
    }
    $value = (float)$bytes;
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return locale_number($value, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
}

function format_percent_de(null|int|float|string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return locale_number((float)$value, 2) . ' %';
}

function format_uptime_de(array $uptime): string
{
    if ($uptime === []) {
        return '-';
    }
    $days = (int)($uptime['days'] ?? 0);
    $hours = (int)($uptime['hours'] ?? 0);
    $minutes = (int)($uptime['minutes'] ?? 0);
    $seconds = (int)($uptime['seconds'] ?? 0);
    return $days . ' ' . t('common.days') . ' ' . sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

function user_display_name(array $user): string
{
    foreach (['username', 'login', 'userName', 'name', 'displayName', 'email'] as $key) {
        if (isset($user[$key]) && trim((string)$user[$key]) !== '') {
            return (string)$user[$key];
        }
    }
    return isset($user['id']) ? 'User #' . $user['id'] : t('common.unknown');
}

function user_email(array $user): string
{
    foreach (['email', 'email_address', 'mail'] as $key) {
        if (isset($user[$key]) && trim((string)$user[$key]) !== '') {
            return (string)$user[$key];
        }
    }
    if (isset($user['contact_data']) && is_array($user['contact_data'])) {
        return (string)($user['contact_data']['email'] ?? '');
    }
    return '';
}
function server_status_view(array $entry): array
{
    $server = $entry['server'] ?? [];
    $info = $entry['info'] ?? [];
    $hostname = (string)value_path($info, 'meta.hostname', $server['name'] ?? t('common.unknown'));
    $panelVersion = trim((string)value_path($info, 'meta.panel_version', ''));
    $panelBuild = value_path($info, 'meta.panel_build', '');
    $kernel = value_path($info, 'components.kernel', value_path($info, 'operating_system.kernel', value_path($info, 'kernel.version', value_path($info, 'kernel', '-'))));
    if (is_array($kernel)) {
        $kernel = (string)($kernel['version'] ?? $kernel['label'] ?? '-');
    }
    $load1 = (float)value_path($info, 'utilization.load.minute_1', 0);
    $load5 = (float)value_path($info, 'utilization.load.minute_5', 0);
    $load15 = (float)value_path($info, 'utilization.load.minute_15', 0);
    $panel = $panelVersion !== '' ? $panelVersion . ($panelBuild !== '' ? ' (Build ' . $panelBuild . ')' : '') : '-';

    return [
        'server_id' => (int)($server['id'] ?? 0),
        'server_name' => (string)($server['name'] ?? ''),
        'dashboard_url' => rtrim((string)($server['base_url'] ?? ''), '/') . '/index.php?page=admin_dashboard',
        'hostname' => $hostname,
        'reboot_required' => (bool)value_path($info, 'operating_system.updates.reboot_required', false),
        'os' => (string)value_path($info, 'operating_system.label', '-'),
        'kernel' => (string)$kernel,
        'panel' => $panel,
        'cpu' => format_percent_de(value_path($info, 'utilization.load.percent')) . ' (' . locale_number($load1, 2) . ' / ' . locale_number($load5, 2) . ' / ' . locale_number($load15, 2) . ')',
        'uptime' => format_uptime_de((array)value_path($info, 'meta.uptime', [])),
        'traffic' => format_bytes_de(value_path($info, 'resources.traffic')),
        'disk' => format_bytes_de(value_path($info, 'resources.consumed_disk_space')),
        'error' => (string)($entry['error'] ?? ''),
    ];
}
