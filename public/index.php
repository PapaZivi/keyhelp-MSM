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
require dirname(__DIR__) . '/src/InvoicePdfRenderer.php';
require dirname(__DIR__) . '/src/Repository.php';
require dirname(__DIR__) . '/src/SyncService.php';
require dirname(__DIR__) . '/src/BillingService.php';

$repo = new Repository(Database::connect($config));
$configuredLocale = $repo->locale((string)($config['app']['locale'] ?? 'de'));
if (!isset($_GET['lang']) && !isset($_SESSION['locale'])) {
    i18n_set_locale($configuredLocale, false);
}
$sync = new SyncService($config, $repo);
$billingService = new BillingService($config, $repo);

if (isset($_GET['invoice_pdf'])) {
    $invoiceId = 0;
    $path = '';
    $storageRoot = false;
    $resolvedPath = false;
    try {
        $invoiceId = (int)$_GET['invoice_pdf'];
        if ($invoiceId <= 0) {
            throw new InvalidArgumentException(t('billing.invoice_not_found'));
        }
        $path = $billingService->invoicePdfPath($invoiceId);
        $storageRoot = realpath(dirname(__DIR__) . '/storage/invoices');
        $resolvedPath = realpath($path);
        if (!$storageRoot || !$resolvedPath || !str_starts_with($resolvedPath, $storageRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(t('billing.invoice_pdf_failed'));
        }
        $invoice = $repo->invoice($invoiceId);
        if (!$invoice) {
            throw new InvalidArgumentException(t('billing.invoice_not_found'));
        }
        $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)($invoice['invoice_number'] ?? 'invoice')) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($resolvedPath));
        readfile($resolvedPath);
    } catch (Throwable $e) {
        log_exception($config, $e, 'Rechnungs-PDF konnte nicht ausgeliefert werden.', [
            'action' => 'invoice_pdf',
            'invoice_id' => $invoiceId,
            'path' => $path,
            'storage_root' => $storageRoot ?: null,
            'resolved_path' => $resolvedPath ?: null,
        ]);
        $notFound = $e instanceof InvalidArgumentException;
        http_response_code($notFound ? 404 : 500);
        render_template('error', [
            'message' => $notFound ? t('billing.invoice_not_found') : t('billing.invoice_pdf_failed'),
        ]);
    }
    exit;
}

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
            ob_start();
            render_partial('users_content', [
                'userGroups' => $repo->usersByServer(),
                'returnPath' => '/?page=users',
                'billingUserSettings' => $repo->billingUserSettingsByUserId(),
                'billingUserItems' => $repo->billingUserItemsByUserId(),
            ]);
            echo json_encode(['ok' => true, 'html' => ob_get_clean()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'import_users') {
            $message = $sync->importUsers();
            ob_start();
            render_partial('users_content', [
                'userGroups' => $repo->usersByServer(),
                'returnPath' => '/?page=users',
                'billingUserSettings' => $repo->billingUserSettingsByUserId(),
                'billingUserItems' => $repo->billingUserItemsByUserId(),
            ]);
            echo json_encode([
                'ok' => true,
                'message' => $message,
                'html' => ob_get_clean(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'create_user') {
            $serverId = (int)($_POST['server_id'] ?? 0);
            $message = $sync->createUser($serverId, $_POST);
            ob_start();
            render_partial('users_content', [
                'userGroups' => $repo->usersByServer(),
                'returnPath' => '/?page=users',
                'billingUserSettings' => $repo->billingUserSettingsByUserId(),
                'billingUserItems' => $repo->billingUserItemsByUserId(),
            ]);
            echo json_encode([
                'ok' => true,
                'message' => $message,
                'html' => ob_get_clean(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'update_user') {
            $localUserId = (int)($_POST['user_id'] ?? 0);
            $message = $sync->updateUser($localUserId, $_POST);
            if ($localUserId > 0) {
                $repo->saveBillingUserSettings([
                    'user_id' => $localUserId,
                    'discount_percent' => $_POST['billing_discount_percent'] ?? 0,
                    'invoice_frequency' => $_POST['billing_invoice_frequency'] ?? 'monthly',
                ]);
                if (trim((string)($_POST['billing_item_description'] ?? '')) !== '') {
                    $repo->saveBillingUserItem([
                        'user_id' => $localUserId,
                        'description' => $_POST['billing_item_description'],
                        'amount' => $_POST['billing_item_amount'] ?? 0,
                        'tax_rate_id' => $_POST['billing_item_tax_rate_id'] ?? null,
                        'booking_date' => $_POST['billing_item_booking_date'] ?? date('Y-m-d'),
                        'frequency' => $_POST['billing_item_frequency'] ?? 'once',
                        'active' => isset($_POST['billing_item_active']) ? 1 : 0,
                        'allow_past_booking_date' => $_POST['billing_item_allow_past_booking_date'] ?? 0,
                    ]);
                }
            }
            ob_start();
            render_partial('users_content', [
                'userGroups' => $repo->usersByServer(),
                'returnPath' => '/?page=users',
                'billingUserSettings' => $repo->billingUserSettingsByUserId(),
                'billingUserItems' => $repo->billingUserItemsByUserId(),
            ]);
            echo json_encode([
                'ok' => true,
                'message' => $message,
                'html' => ob_get_clean(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'delete_user') {
            $message = $sync->deleteUser((int)($_POST['user_id'] ?? 0));
            ob_start();
            render_partial('users_content', [
                'userGroups' => $repo->usersByServer(),
                'returnPath' => '/?page=users',
                'billingUserSettings' => $repo->billingUserSettingsByUserId(),
                'billingUserItems' => $repo->billingUserItemsByUserId(),
            ]);
            echo json_encode([
                'ok' => true,
                'message' => $message,
                'html' => ob_get_clean(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'billing_save_tld_price') {
            $repo->saveBillingTldPrice($_POST);
            $tldPrices = $repo->billingTldPrices();
            ob_start();
            render_partial('config_tld_prices', compact('tldPrices'));
            echo json_encode([
                'ok' => true,
                'message' => t('billing.tld_saved'),
                'html' => ob_get_clean(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'user_login_url') {
            echo json_encode([
                'ok' => true,
                'url' => $sync->userLoginUrl((int)($_POST['user_id'] ?? 0)),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'check_username') {
            $serverId = (int)($_POST['server_id'] ?? 0);
            $username = trim((string)($_POST['username'] ?? ''));
            echo json_encode([
                'ok' => true,
                'available' => $username !== '' && !$repo->usernameExists($serverId, $username),
                'message' => $username === ''
                    ? ''
                    : ($repo->usernameExists($serverId, $username) ? t('message.username_taken') : t('message.username_available')),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'suggest_username') {
            $serverId = (int)($_POST['server_id'] ?? 0);
            $username = $repo->suggestUsername($serverId);
            echo json_encode([
                'ok' => true,
                'username' => $username,
                'available' => true,
                'message' => t('message.username_available'),
            ], JSON_UNESCAPED_UNICODE);
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
        if ($action === 'reboot_server') {
            echo json_encode([
                'ok' => true,
                'message' => $sync->rebootServer((int)($_POST['id'] ?? 0)),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'update_domain') {
            $repo->updateDomainBilling($_POST);
            if (isset($_POST['billing_override_present'])) {
                $repo->saveBillingDomainOverride(['domain_id' => (int)$_POST['id']] + $_POST);
            }
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
        if ($action === 'import_domains') {
            $message = $sync->importDomains();
            $domains = $repo->domains();
            ob_start();
            render_partial('domains_content', [
                'domains' => $domains,
                'returnPath' => '/?page=domains',
                'billingDomainOverrides' => $repo->billingDomainOverridesByDomainId(),
                'billingTaxRates' => $repo->billingTaxRates(),
            ]);
            $html = ob_get_clean();
            echo json_encode([
                'ok' => true,
                'message' => $message,
                'html' => $html,
            ], JSON_UNESCAPED_UNICODE);
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
                    'ssh_link_enabled' => (int)($server['ssh_link_enabled'] ?? 0) === 1,
                    'ssh_port' => (int)($server['ssh_port'] ?? 22),
                    'ssh_username' => (string)($server['ssh_username'] ?? ''),
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
        if ($e instanceof InvalidArgumentException) {
            http_response_code(422);
        }
        echo json_encode([
            'ok' => false,
            'message' => $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : user_error_message($failedAction),
        ], JSON_UNESCAPED_UNICODE);
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
            'delete_server' => $sync->deleteServer((int)($_POST['id'] ?? 0)),
            'add_package' => $sync->createHostingPackage($_POST),
            'update_package' => $sync->updateHostingPackage($_POST),
            'delete_package' => $sync->deleteHostingPackage((int)($_POST['id'] ?? 0)),
            'import_users' => $sync->importUsers(),
            'create_user' => $sync->createUser((int)($_POST['server_id'] ?? 0), $_POST),
            'queue_user' => (function () use ($repo): string {
                $repo->queue('create_user', ($_POST['server_id'] ?? '') !== '' ? (int)$_POST['server_id'] : null, $_POST);
                return t('message.user_queued', ['name' => ($_POST['username'] ?? t('common.unknown'))]);
            })(),
            'update_config' => (function () use ($repo): string {
                $seconds = $repo->updateServerRefreshInterval(isset($_POST['server_refresh_interval']) ? (int)$_POST['server_refresh_interval'] : $repo->serverRefreshInterval());
                $locale = $repo->updateLocale((string)($_POST['locale'] ?? $repo->locale(current_locale())));
                $repo->updateThemeMode((string)($_POST['theme_mode'] ?? $repo->themeMode()));
                $repo->updateUsernamePattern((string)($_POST['username_pattern'] ?? $repo->usernamePattern()));
                i18n_set_locale($locale);
                $interval = $seconds === 0 ? t('common.off') : $seconds . ' ' . t('common.seconds');
                return t('message.config_saved', ['interval' => $interval, 'seconds' => $seconds]);
            })(),
            'update_domain' => (function () use ($repo): string {
                $repo->updateDomainBilling($_POST);
                if (isset($_POST['billing_override_present'])) {
                    $repo->saveBillingDomainOverride(['domain_id' => (int)$_POST['id']] + $_POST);
                }
                $domain = $repo->domain((int)$_POST['id']);
                return t('message.domain_saved', ['name' => ($domain['domain'] ?? t('common.unknown'))]);
            })(),
            'import_domains' => $sync->importDomains(),
            'import_hosting_plans' => $sync->importHostingPlans(),
            'run_sync' => $sync->runQueue(),
            'billing_run' => $billingService->run((string)($config['app']['admin_user'] ?? 'admin')),
            'billing_backbill_domains' => $billingService->backbillDomains($_POST, (string)($config['app']['admin_user'] ?? 'admin')),
            'billing_save_settings' => (function () use ($repo): string {
                $repo->saveBillingSettings($_POST);
                return t('billing.settings_saved');
            })(),
            'billing_save_tax_rate' => (function () use ($repo): string {
                $repo->saveBillingTaxRate($_POST);
                return t('billing.tax_saved');
            })(),
            'billing_save_tld_price' => (function () use ($repo): string {
                $repo->saveBillingTldPrice($_POST);
                return t('billing.tld_saved');
            })(),
            'billing_save_user_settings' => (function () use ($repo): string {
                $repo->saveBillingUserSettings($_POST);
                return t('billing.user_settings_saved');
            })(),
            'billing_save_user_item' => (function () use ($repo): string {
                $repo->saveBillingUserItem($_POST);
                return t('billing.user_item_saved');
            })(),
            'billing_save_domain_override' => (function () use ($repo): string {
                $repo->saveBillingDomainOverride($_POST);
                return t('billing.domain_override_saved');
            })(),
            'billing_invoice_send' => $billingService->approveAndSend((int)($_POST['invoice_id'] ?? 0), (string)($config['app']['admin_user'] ?? 'admin')),
            'billing_invoice_queue' => $billingService->queueInvoice((int)($_POST['invoice_id'] ?? 0), (string)($config['app']['admin_user'] ?? 'admin')),
            'billing_invoice_cancel' => $billingService->cancelInvoice((int)($_POST['invoice_id'] ?? 0), (string)($config['app']['admin_user'] ?? 'admin')),
            'billing_invoice_delete' => $billingService->deleteInvoice((int)($_POST['invoice_id'] ?? 0), (string)($config['app']['admin_user'] ?? 'admin')),
            'billing_invoice_requeue' => $billingService->requeueCancelledInvoice((int)($_POST['invoice_id'] ?? 0), (string)($config['app']['admin_user'] ?? 'admin')),
            'billing_send_queue' => $billingService->sendQueued((string)($config['app']['admin_user'] ?? 'admin')),
            default => throw new RuntimeException('Unknown action.'),
        };
        redirect_with($message);
    } catch (Throwable $e) {
        log_exception($config, $e, 'POST-Aktion fehlgeschlagen.', [
            'action' => $action ?? '',
            'post' => array_diff_key($_POST, ['api_token' => true, 'password' => true]),
        ]);
        redirect_with(
            $e instanceof InvalidArgumentException ? $e->getMessage() : user_error_message($action ?? ''),
            'error'
        );
    }
}

$servers = $repo->servers();
$domains = $repo->domains();
$userGroups = $repo->usersByServer();
$packages = $repo->packages();
$actions = $repo->actions();
$serverRefreshInterval = $repo->serverRefreshInterval();
$appLocale = $repo->locale(current_locale());
$themeMode = $repo->themeMode();
$usernamePattern = $repo->usernamePattern();
$billingData = $repo->billingOverview();
$serverRefreshIntervalOptions = Repository::refreshIntervalOptions();
$themeModeOptions = Repository::themeModeOptions();
$allowedPages = ['dashboard', 'domains', 'users', 'hosting', 'server', 'billing', 'config'];
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
    'billing' => t('nav.billing'),
    'config' => t('nav.config'),
];

render_template('app', [
    'config' => $config,
    'servers' => $servers,
    'domains' => $domains,
    'userGroups' => $userGroups,
    'packages' => $packages,
    'billingData' => $billingData,
    'actions' => $actions,
    'serverRefreshInterval' => $serverRefreshInterval,
    'serverRefreshIntervalOptions' => $serverRefreshIntervalOptions,
    'themeMode' => $themeMode,
    'usernamePattern' => $usernamePattern,
    'themeModeOptions' => $themeModeOptions,
    'appLocale' => $appLocale,
    'page' => $page,
    'returnPath' => $returnPath,
    'flash' => $flash,
    'navItems' => $navItems,
    'supportedLocales' => i18n_supported_locales(),
    'jsMessages' => i18n_js_messages(),
]);
