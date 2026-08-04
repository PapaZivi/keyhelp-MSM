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
        'import_users' => t('message.import_users_failed'),
        'create_user' => t('message.create_user_failed'),
        'update_user' => t('message.create_user_failed'),
        'delete_user' => t('message.create_user_failed'),
        'user_login_url' => t('message.user_login_failed'),
        'import_hosting_plans' => t('message.import_hosting_plans_failed'),
        'run_sync' => t('message.sync_failed'),
        'update_domain' => t('message.update_domain_failed'),
        'refresh_domain' => t('message.refresh_domain_failed'),
        'update_server' => t('message.update_server_failed'),
        'reboot_server' => t('message.server_reboot_failed'),
        default => t('message.generic_action_failed'),
    };
}

function domain_is_locked(array $domain): bool
{
    return !empty($domain['is_disabled']);
}

function domain_has_problem(array $domain): bool
{
    return !domain_is_locked($domain) && (string)($domain['domain_status'] ?? '') !== '' && (int)$domain['domain_status'] !== 1;
}

function status_tooltip_attrs(string $title): string
{
    return ' data-bs-toggle="tooltip" data-bs-placement="top" data-bs-trigger="hover" data-bs-title="' . h($title) . '" aria-label="' . h($title) . '"';
}

function icon_button_attrs(string $title): string
{
    return status_tooltip_attrs($title) . ' title="' . h($title) . '"';
}

function icon_svg(string $name): string
{
    $paths = [
        'edit' => '<path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207zm1.586 3L10.5 3.207 3 10.707V13h2.293z"/>',
        'trash' => '<path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1h2.5a1 1 0 0 1 1 1M4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/>',
        'eye' => '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>',
        'save' => '<path d="M2 1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V2.5L13.5 1zM2 0h12l2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2"/><path d="M5.5 1v5h5V1h1v6h-7V1zM4 11.5A1.5 1.5 0 0 1 5.5 10h5a1.5 1.5 0 0 1 1.5 1.5V15h-1v-3.5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0-.5.5V15H4z"/>',
        'plus' => '<path fill-rule="evenodd" d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2"/>',
        'refresh' => '<path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z"/><path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466"/>',
        'putty' => '<path d="M2 2.5A1.5 1.5 0 0 1 3.5 1h9A1.5 1.5 0 0 1 14 2.5v6A1.5 1.5 0 0 1 12.5 10H9v1.5h2.5a.5.5 0 0 1 0 1h-7a.5.5 0 0 1 0-1H7V10H3.5A1.5 1.5 0 0 1 2 8.5zM3.5 2a.5.5 0 0 0-.5.5v6a.5.5 0 0 0 .5.5h9a.5.5 0 0 0 .5-.5v-6a.5.5 0 0 0-.5-.5z"/><path d="M4.646 4.146a.5.5 0 0 1 .708 0L7.207 6 5.354 7.854a.5.5 0 1 1-.708-.708L5.793 6 4.646 4.854a.5.5 0 0 1 0-.708M7.5 7.5A.5.5 0 0 1 8 7h2.5a.5.5 0 0 1 0 1H8a.5.5 0 0 1-.5-.5"/>',
        'reboot' => '<path d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z"/><path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466"/><path d="M7.5 6.5h1v4h-1z"/>',
        'clock' => '<path d="M8 3.5a.5.5 0 0 1 .5.5v3.25l2.25 1.35a.5.5 0 1 1-.5.866l-2.5-1.5A.5.5 0 0 1 7.5 7.5V4a.5.5 0 0 1 .5-.5"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m0-1A7 7 0 1 1 8 1a7 7 0 0 1 0 14"/>',
    ];
    $path = $paths[$name] ?? $paths['edit'];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-' . h($name) . '" viewBox="0 0 16 16" aria-hidden="true">' . $path . '</svg>';
}

function status_calendar_icon(string $class, string $label, ?string $date = null): string
{
    $title = $date ? $label . ': ' . format_date_local($date) : $label;
    return '<span class="status-calendar status-tooltip ' . h($class) . '"' . status_tooltip_attrs($title) . '><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="status-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4z"/></svg></span>';
}

function lock_marker_html(string $title): string
{
    return '<span class="status-lock-marker status-tooltip"' . status_tooltip_attrs($title) . '>&#x1F6C7;</span>';
}

function domain_row_class(array $domain): string
{
    $classes = [];
    if (domain_is_locked($domain)) {
        $classes[] = 'domain-disabled';
    }
    if (domain_has_problem($domain)) {
        $classes[] = 'domain-problem';
    }
    if (!empty($domain['delete_on'])) {
        $classes[] = 'domain-delete-pending';
    }
    if ((int)($domain['duplicate_server_count'] ?? 0) > 1) {
        $classes[] = 'domain-duplicate';
    }
    return implode(' ', $classes);
}

function domain_name_status_html(array $domain): string
{
    return domain_is_locked($domain) ? lock_marker_html(t('domains.locked_or_disabled')) : '';
}

function domain_suspension_date(array $domain): string
{
    return trim((string)($domain['suspend_on'] ?? $domain['lock_on'] ?? ''));
}

function domain_status_html(array $domain): string
{
    $states = [];
    if (domain_is_locked($domain)) {
        $states[] = '<span class="domain-state locked status-tooltip"' . status_tooltip_attrs(t('domains.locked_or_disabled')) . '>&#x1F6C7;</span>';
    } elseif (domain_has_problem($domain)) {
        $states[] = '<span class="domain-state problem status-tooltip"' . status_tooltip_attrs(t('domains.problem_status')) . '><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="status-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.964 0L.165 13.233c-.457.778.091 1.767.982 1.767h13.706c.89 0 1.438-.99.982-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/></svg></span>';
    }
    $suspendOn = domain_suspension_date($domain);
    if ($suspendOn !== '') {
        $states[] = status_calendar_icon('suspend', t('users.lock_on'), $suspendOn);
    }
    if (!empty($domain['delete_on'])) {
        $states[] = status_calendar_icon('delete', t('domains.deletion_pending'), (string)$domain['delete_on']);
    }
    return implode(' ', $states);
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

function user_raw_data(array $user): array
{
    if (isset($user['raw_json']) && is_string($user['raw_json']) && trim($user['raw_json']) !== '') {
        $decoded = json_decode($user['raw_json'], true);
        return is_array($decoded) ? $decoded : [];
    }
    return $user;
}

function user_is_locked(array $user): bool
{
    $raw = user_raw_data($user);
    return !empty($raw['is_suspended']) || !empty($raw['is_locked']) || !empty($raw['is_disabled']);
}

function user_suspension_date(array $user): string
{
    $raw = user_raw_data($user);
    return trim((string)($raw['suspend_on'] ?? $raw['lock_on'] ?? ''));
}

function user_deletion_date(array $user): string
{
    $raw = user_raw_data($user);
    return trim((string)($raw['delete_on'] ?? ''));
}

function user_row_class(array $user): string
{
    return user_is_locked($user) ? 'user-disabled' : '';
}

function user_name_status_html(array $user): string
{
    return user_is_locked($user) ? lock_marker_html(t('users.account_locked')) : '';
}

function user_status_html(array $user): string
{
    $states = [];
    $suspendOn = user_suspension_date($user);
    $deleteOn = user_deletion_date($user);
    if ($suspendOn !== '') {
        $states[] = status_calendar_icon('suspend', t('users.lock_on'), $suspendOn);
    }
    if ($deleteOn !== '') {
        $states[] = status_calendar_icon('delete', t('users.delete_on'), $deleteOn);
    }
    return implode(' ', $states);
}
function server_status_view(array $entry): array
{
    $server = $entry['server'] ?? [];
    $info = $entry['info'] ?? [];
    $apiHostname = (string)value_path($info, 'meta.hostname', $server['name'] ?? t('common.unknown'));
    $displayName = (string)($server['name'] ?? $apiHostname);
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
        'server_name' => $displayName,
        'dashboard_url' => server_admin_url($server),
        'ssh_url' => server_ssh_url($server),
        'hostname' => $displayName,
        'api_hostname' => $apiHostname,
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

function server_admin_url(array $server): string
{
    $baseUrl = trim((string)($server['base_url'] ?? ''));
    return $baseUrl === '' ? '' : rtrim($baseUrl, '/') . '/index.php?page=admin_dashboard';
}


function server_ssh_url(array $server, string $hostname = ''): string
{
    if (empty($server['ssh_link_enabled'])) {
        return '';
    }

    $host = server_ssh_host($server, $hostname);
    if ($host === '') {
        return '';
    }

    $username = trim((string)($server['ssh_username'] ?? ''));
    $port = (int)($server['ssh_port'] ?? 22);
    $authority = ($username !== '' ? rawurlencode($username) . '@' : '') . $host;

    return 'ssh://' . $authority . ($port > 0 && $port !== 22 ? ':' . $port : '');
}

function server_ssh_host(array $server, string $hostname = ''): string
{
    $candidates = [
        (string)(parse_url((string)($server['base_url'] ?? ''), PHP_URL_HOST) ?: ''),
        (string)($server['base_url'] ?? ''),
        $hostname,
        (string)($server['name'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
        $host = normalize_ssh_host($candidate);
        if ($host !== '') {
            return $host;
        }
    }

    return '';
}

function normalize_ssh_host(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (str_contains($value, '://')) {
        $host = parse_url($value, PHP_URL_HOST);
        $value = is_string($host) ? $host : '';
    }

    $value = trim($value, " \t\n\r\0\x0B/");
    if ($value === '' || strcasecmp($value, 'ssh') === 0) {
        return '';
    }

    return preg_replace('/:\d+$/', '', $value) ?: '';
}

function server_ssh_link_html(array $server, string $hostname = ''): string
{
    $url = server_ssh_url($server, $hostname);
    if ($url === '') {
        return '';
    }
    return '<a class="server-ssh-link status-tooltip" href="' . h($url) . '"' . icon_button_attrs(t('dashboard.ssh_login')) . '>' . icon_svg('putty') . '</a>';
}

function server_status_placeholder_view(array $server): array
{
    return [
        'server_id' => (int)($server['id'] ?? 0),
        'server_name' => (string)($server['name'] ?? ''),
        'dashboard_url' => server_admin_url($server),
        'ssh_url' => server_ssh_url($server),
        'hostname' => (string)($server['name'] ?? t('common.unknown')),
        'reboot_required' => false,
        'os' => '',
        'kernel' => '',
        'panel' => '',
        'cpu' => '',
        'uptime' => '',
        'traffic' => '',
        'disk' => '',
        'error' => '',
    ];
}
