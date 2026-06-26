<?php
session_start();
$configFile = dirname(__DIR__) . '/config/config.php';
$config = file_exists($configFile) ? require $configFile : require dirname(__DIR__) . '/config/config.example.php';
date_default_timezone_set($config['app']['timezone']);

require dirname(__DIR__) . '/src/helpers.php';
require dirname(__DIR__) . '/src/I18n.php';
require dirname(__DIR__) . '/src/View.php';
require dirname(__DIR__) . '/src/Logger.php';


i18n_init($config);

set_exception_handler(static function (Throwable $exception) use ($config): void {
    log_exception($config, $exception, 'Unerwarteter Anwendungsfehler.', ['handler' => 'global']);
    http_response_code(500);
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (str_contains($accept, 'application/json') || (($_POST['_ajax'] ?? '') === '1') || isset($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => t('message.generic_action_failed')], JSON_UNESCAPED_UNICODE);
        return;
    }
    render_template('error', ['message' => t('message.app_failed')]);
});

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'login') {
    $userMatches = hash_equals((string)$config['app']['admin_user'], (string)($_POST['user'] ?? ''));
    $passwordMatches = password_verify((string)($_POST['password'] ?? ''), $config['app']['admin_password_hash']);
    if ($userMatches && $passwordMatches) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        redirect_with(t('message.welcome'));
    }
    $_SESSION['flash'] = ['message' => t('message.login_failed'), 'type' => 'error'];
}

if (($_GET['logout'] ?? '') === '1') {
    $_SESSION = [];
    session_destroy();
    header('Location: /');
    exit;
}

if (empty($_SESSION['authenticated'])) {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    render_template('login', [
        'config' => $config,
        'flash' => $flash,
        'supportedLocales' => i18n_supported_locales(),
    ]);
    exit;
}
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/DomainOwner.php';
require dirname(__DIR__) . '/src/KeyHelpClient.php';
require dirname(__DIR__) . '/src/Repository.php';
require dirname(__DIR__) . '/src/SyncService.php';

$repo = new Repository(Database::connect($config));
$configuredLocale = $repo->locale((string)($config['app']['locale'] ?? 'de'));
if (!isset($_GET['lang']) && !isset($_SESSION['locale'])) {
    i18n_set_locale($configuredLocale, false);
}
$sync = new SyncService($config, $repo);
if (($_GET['ajax'] ?? '') === 'subdomains') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $subdomains = $sync->subdomainsFor((int)($_GET['server_id'] ?? 0), (string)($_GET['domain'] ?? ''));
        echo json_encode(['ok' => true, 'subdomains' => $subdomains], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        log_exception($config, $e, 'Subdomains konnten nicht geladen werden.', [
            'action' => 'subdomains',
            'server_id' => $_GET['server_id'] ?? null,
            'domain' => $_GET['domain'] ?? null,
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => t('message.subdomains_failed')], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = $_POST['_action'] ?? '';
        if ($action === 'load_dashboard') {
            $statuses = array_map(static fn(array $entry): array => server_status_view($entry), $sync->dashboardServers());
            echo json_encode(['ok' => true, 'statuses' => $statuses], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'load_users') {
            $groups = array_map(static function (array $group): array {
                return [
                    'server' => [
                        'id' => (int)($group['server']['id'] ?? 0),
                        'name' => (string)($group['server']['name'] ?? ''),
                    ],
                    'error' => (string)($group['error'] ?? ''),
                    'users' => array_map(static fn(array $user): array => [
                        'id' => (string)($user['id'] ?? $user['id_user'] ?? ''),
                        'name' => user_display_name($user),
                        'email' => user_email($user),
                    ], $group['users'] ?? []),
                ];
            }, $sync->userOverview());
            echo json_encode(['ok' => true, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'refresh_server_status') {
            $entry = $sync->dashboardServer((int)$_POST['id']);
            $status = server_status_view($entry);
            echo json_encode([
                'ok' => true,
                'message' => t('message.server_status_updated', ['name' => ($status['hostname'] ?: $status['server_name'])]),
                'status' => $status,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'update_domain') {
            $repo->updateDomainBilling($_POST);
            $domain = $repo->domain((int)$_POST['id']);
            echo json_encode([
                'ok' => true,
                'message' => t('message.domain_saved', ['name' => ($domain['domain'] ?? t('common.unknown'))]),
                'domain' => $domain,
                'row_class' => domain_row_class($domain),
                'status_html' => domain_status_html($domain),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'refresh_domain') {
            $result = $sync->refreshDomain((int)$_POST['id']);
            if (($result['status'] ?? '') === 'updated' && isset($result['domain'])) {
                $result['row_class'] = domain_row_class($result['domain']);
                $result['status_html'] = domain_status_html($result['domain']);
            }
            echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'update_server') {
            $repo->updateServer($_POST);
            $server = $repo->server((int)$_POST['id']);
            echo json_encode([
                'ok' => true,
                'message' => t('message.server_updated', ['name' => ($server['name'] ?? t('common.unknown'))]),
                'server' => [
                    'id' => (int)$server['id'],
                    'name' => $server['name'],
                    'base_url' => $server['base_url'],
                    'api_key_preview' => substr((string)$server['api_token'], 0, 10) . '...',
                    'active' => (int)$server['active'] === 1,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw new RuntimeException('Unknown AJAX action.');
    } catch (Throwable $e) {
        $failedAction = $action ?? 'ajax';
        log_exception($config, $e, 'AJAX-Aktion fehlgeschlagen.', [
            'action' => $failedAction,
            'post' => array_diff_key($_POST, ['api_token' => true, 'password' => true]),
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => user_error_message($failedAction)], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    try {
        $message = match ($action) {
            'add_server' => (function () use ($repo): string {
                $repo->addServer($_POST);
                return t('message.server_created', ['name' => ($_POST['name'] ?? t('common.unknown'))]);
            })(),
            'update_server' => (function () use ($repo): string {
                $repo->updateServer($_POST);
                return t('message.server_updated', ['name' => ($_POST['name'] ?? t('common.unknown'))]);
            })(),
            'add_package' => (function () use ($repo): string {
                $repo->addPackage($_POST);
                return t('message.hosting_created', ['name' => ($_POST['name'] ?? t('common.unknown'))]);
            })(),
            'queue_user' => (function () use ($repo): string {
                $repo->queue('create_user', ($_POST['server_id'] ?? '') !== '' ? (int)$_POST['server_id'] : null, $_POST);
                return t('message.user_queued', ['name' => ($_POST['username'] ?? t('common.unknown'))]);
            })(),
            'update_config' => (function () use ($repo): string {
                $seconds = $repo->updateServerRefreshInterval((int)($_POST['server_refresh_interval'] ?? 60));
                $locale = $repo->updateLocale((string)($_POST['locale'] ?? current_locale()));
                i18n_set_locale($locale);
                return t('message.config_saved', ['seconds' => $seconds]);
            })(),
            'update_domain' => (function () use ($repo): string {
                $repo->updateDomainBilling($_POST);
                $domain = $repo->domain((int)$_POST['id']);
                return t('message.domain_saved', ['name' => ($domain['domain'] ?? t('common.unknown'))]);
            })(),
            'import_domains' => $sync->importDomains(),
            'import_hosting_plans' => $sync->importHostingPlans(),
            'run_sync' => $sync->runQueue(),
            default => throw new RuntimeException('Unknown action.'),
        };
        redirect_with($message);
    } catch (Throwable $e) {
        log_exception($config, $e, 'POST-Aktion fehlgeschlagen.', [
            'action' => $action ?? '',
            'post' => array_diff_key($_POST, ['api_token' => true, 'password' => true]),
        ]);
        redirect_with(user_error_message($action ?? ''), 'error');
    }
}

$servers = $repo->servers();
$domains = $repo->domains();
$packages = $repo->packages();
$actions = $repo->actions();
$serverRefreshInterval = $repo->serverRefreshInterval();
$appLocale = $repo->locale(current_locale());
$serverRefreshIntervalOptions = Repository::refreshIntervalOptions();
$allowedPages = ['dashboard', 'domains', 'users', 'hosting', 'server', 'config'];
$page = (string)($_GET['page'] ?? 'dashboard');
if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}
$returnPath = '/?page=' . rawurlencode($page);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$navItems = [
    'dashboard' => t('nav.dashboard'),
    'domains' => t('nav.domains'),
    'users' => t('nav.users'),
    'hosting' => t('nav.hosting'),
    'server' => t('nav.server'),
    'config' => t('nav.config'),
];

render_template('app', [
    'config' => $config,
    'servers' => $servers,
    'domains' => $domains,
    'packages' => $packages,
    'actions' => $actions,
    'serverRefreshInterval' => $serverRefreshInterval,
    'serverRefreshIntervalOptions' => $serverRefreshIntervalOptions,
    'appLocale' => $appLocale,
    'page' => $page,
    'returnPath' => $returnPath,
    'flash' => $flash,
    'navItems' => $navItems,
    'supportedLocales' => i18n_supported_locales(),
    'jsMessages' => i18n_js_messages(),
]);