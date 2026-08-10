<?php
$initialTheme = ($themeMode ?? 'auto') === 'dark' ? 'dark' : 'light';
$billingTaxRates = $billingData['taxRates'] ?? [];
$billingUserSettings = $billingData['userSettings'] ?? [];
$billingUserItems = $billingData['userItemsByUserId'] ?? [];
$billingDomainOverrides = $billingData['domainOverrides'] ?? [];
$resourceFields = [
    ['disk_space', 'users.resource_disk_space', true, 'GiB', 2, null],
    ['traffic', 'users.resource_traffic', true, 'MiB', 0, null],
    ['domains', 'users.resource_domains', false, '', 1, null],
    ['subdomains', 'users.resource_subdomains', false, '', 0, null],
    ['email_accounts', 'users.resource_email_accounts', false, '', 1, null],
    ['email_addresses', 'users.resource_email_addresses', false, '', 2, null],
    ['email_forwarders', 'users.resource_email_forwarders', false, '', 0, null],
    ['databases', 'users.resource_databases', false, '', 0, null],
    ['ftp_users', 'users.resource_ftp_users', false, '', 0, 'ftp'],
    ['scheduled_tasks', 'users.resource_scheduled_tasks', false, '', 0, null],
];
$permissionFields = [
    ['ftp', true],
    ['php', true],
    ['perl_cgi', false],
    ['ssh', false],
    ['backup', true],
    ['file_manager', true],
    ['dns_editor', false],
    ['domain_security', false],
    ['certificate_management', false],
    ['database_remote_access', false],
    ['email_catch_all', true],
    ['delete_main_domains', false],
    ['panel_access', true],
    ['update_contact_data', false],
    ['applications', true],
    ['restricted_ssh', false],
];
$defaultDisabledFunctions = implode(', ', [
    'apache_child_terminate',
    'apache_note',
    'apache_setenv',
    'curl_multi_exec',
    'define_syslog_variables',
    'dl',
    'exec',
    'link',
    'opcache_get_status',
    'openlog',
    'passthru',
    'pcntl_exec',
    'pcntl_fork',
    'pcntl_setpriority',
    'popen',
    'posix_getpwuid',
    'posix_kill',
    'posix_mkfifo',
    'posix_setpgid',
    'posix_setsid',
    'posix_setuid',
    'proc_close',
    'proc_get_status',
    'proc_nice',
    'proc_open',
    'proc_terminate',
    'shell_exec',
    'stream_socket_sendto',
    'symlink',
    'syslog',
    'system',
]);
$labelHelp = static function (string $label, string $helpKey): string {
    $help = t($helpKey);
    if ($help === $helpKey || trim($help) === '') {
        return h($label);
    }
    return h($label)
        . ' <button type="button" class="help-popover"'
        . ' data-help-popover data-bs-toggle="popover"'
        . ' data-bs-trigger="focus" data-bs-placement="top"'
        . ' data-bs-content="' . h($help) . '"'
        . ' aria-label="' . h($help) . '">?</button>';
};
$renderResourceFields = static function (array $fields, bool $readonly = false) use ($labelHelp): void {
    foreach ($fields as [$field, $labelKey, $hasUnit, $unit, $default, $requiresPermission]) {
        $readonlyAttrs = $readonly ? ' data-api-readonly' : '';
        $controlReadonly = $readonly ? ' data-api-readonly-control' : '';
        $requiresAttrs = $requiresPermission ? ' data-resource-requires-permission="' . h($requiresPermission) . '"' : '';
        echo '<div class="resource-row" data-resource-row' . $requiresAttrs . $readonlyAttrs . '>';
        echo '<label class="form-label">' . $labelHelp(t($labelKey), $labelKey . '_help');
        echo '<div class="resource-input-group">';
        if ($hasUnit) {
            echo '<select class="form-select"'
                . ' name="' . h($field) . '_unit"'
                . ' data-resource-unit="' . h($field) . '"'
                . ' data-resource-control' . $controlReadonly . '>'
                . '<option value="MiB"' . ($unit === 'MiB' ? ' selected' : '') . '>MiB</option>'
                . '<option value="GiB"' . ($unit === 'GiB' ? ' selected' : '') . '>GiB</option>'
                . '</select>';
        }
        echo '<input class="form-control"'
            . ' name="' . h($field) . '"'
            . ' type="number" min="0"'
            . ' value="' . h((string)$default) . '"'
            . ' data-resource-field="' . h($field) . '"'
            . ' data-resource-control' . $controlReadonly . '>';
        echo '</div></label>';
        echo '<label class="form-check billing-check">'
            . '<input class="form-check-input"'
            . ' type="checkbox"'
            . ' name="' . h($field) . '_unlimited"'
            . ' value="1"'
            . ' data-resource-unlimited="' . h($field) . '"'
            . ' data-resource-control' . $controlReadonly . '> '
            . h(t('users.unlimited'))
            . '</label>';
        echo '</div>';
    }
};
$renderPermissionFields = static function (array $fields, bool $readonly = false) use ($labelHelp): void {
    echo '<div class="permission-grid"' . ($readonly ? ' data-api-readonly' : '') . '>';
    foreach ($fields as [$field, $checked]) {
        echo '<label class="form-check">'
            . '<input class="form-check-input"'
            . ' type="checkbox"'
            . ' name="permission_' . h($field) . '"'
            . ' value="1" '
            . ($checked ? 'checked' : '')
            . ($readonly ? ' data-api-readonly-control' : '')
            . '> '
            . $labelHelp(t('users.permission_' . $field), 'users.permission_' . $field . '_help')
            . '</label>';
    }
    echo '</div>';
};
$renderPhpFields = static function (bool $readonly = false) use ($labelHelp, $defaultDisabledFunctions): void {
    $readonlyAttr = $readonly ? ' data-api-readonly-control' : '';
    $fields = [
        ['php_memory_limit', 'memory_limit', '128M', 'input'],
        ['php_max_execution_time', 'max_execution_time', '60', 'input'],
        ['php_post_max_size', 'post_max_size', '72M', 'input'],
        ['php_upload_max_filesize', 'upload_max_filesize', '64M', 'input'],
        ['php_open_basedir', 'open_basedir', '##DOCROOT##/www:##DOCROOT##/files:##DOCROOT##/tmp', 'input'],
        ['php_disable_functions', 'disable_functions', $defaultDisabledFunctions, 'textarea'],
        ['php_sendmail_from', 'sendmail_from', '', 'input'],
        ['php_environment_variables', 'users.php_environment_variables', '', 'textarea'],
        ['php_extra_directives_immutable', 'users.php_extra_directives_immutable', '', 'textarea'],
        ['php_extra_directives_mutable', 'users.php_extra_directives_mutable', '', 'textarea'],
    ];
    foreach ($fields as [$name, $labelKey, $value, $type]) {
        echo '<label class="form-label">' . $labelHelp(t($labelKey), $labelKey . '_help');
        if ($type === 'textarea') {
            echo '<textarea class="form-control" name="' . h($name) . '" rows="3"' . $readonlyAttr . '>' . h($value) . '</textarea>';
        } else {
            echo '<input class="form-control" name="' . h($name) . '" value="' . h($value) . '"' . $readonlyAttr . '>';
        }
        echo '</label>';
    }
};
$dashboardPackageMarkers = [];
foreach ($packages as $package) {
    $markerSource = (string)($package['name'] ?? '');
    $limits = json_decode((string)($package['limits_json'] ?? ''), true);
    if (is_array($limits)) {
        $markerSource .= ' ' . (string)($limits['name'] ?? '');
    }
    if (preg_match('/\[MSM:(pkg-[^\]]+)\]/', $markerSource, $matches)) {
        $dashboardPackageMarkers[$matches[1]] = true;
    }
}
?>
<!doctype html>
<html lang="<?= h(current_locale()) ?>" data-theme-mode="<?= h($themeMode ?? 'auto') ?>" data-bs-theme="<?= h($initialTheme) ?>">
    <?php render_partial('head', ['config' => $config, 'title' => $config['app']['name']]); ?>
    <body class="app-shell" data-page="<?= h($page) ?>" data-server-refresh-interval="<?= (int)$serverRefreshInterval ?>">
        <aside class="sidebar bg-white border-end">
            <a class="sidebar-brand app-logo-link" href="/" aria-label="<?= h($config['app']['name']) ?>">
                <img
                    class="app-logo app-logo-sidebar"
                    src="/assets/khmsm_fulllogo_512.png"
                    alt="<?= h($config['app']['name']) ?>"
                >
            </a>
            <nav class="side-nav nav nav-pills flex-column" aria-label="<?= h(t('nav.dashboard')) ?>">
                <?php foreach ($navItems as $navPage => $label): ?>
                    <a
                        class="nav-link <?= $page === $navPage ? 'active' : '' ?>"
                        href="/?page=<?= h($navPage) ?>"
                    >
                        <?= h($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <div class="app-main">
            <header class="topbar navbar bg-white border-bottom sticky-top">
                <button
                    class="mobile-menu-toggle"
                    type="button"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#mobileNavigation"
                    aria-controls="mobileNavigation"
                    aria-label="<?= h(t('nav.dashboard')) ?>"
                >
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <a class="mobile-logo app-logo-link" href="/" aria-label="<?= h($config['app']['name']) ?>">
                    <img
                        class="app-logo"
                        src="/assets/khmsm_fulllogo_512.png"
                        alt="<?= h($config['app']['name']) ?>"
                    >
                </a>
                <div
                    class="offcanvas offcanvas-start mobile-navigation"
                    tabindex="-1"
                    id="mobileNavigation"
                >
                    <div class="offcanvas-header">
                        <img
                            class="app-logo"
                            src="/assets/khmsm_fulllogo_512.png"
                            alt="<?= h($config['app']['name']) ?>"
                        >
                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="offcanvas"
                            aria-label="Close"
                        ></button>
                    </div>
                    <div class="offcanvas-body">
                        <nav class="mobile-nav nav nav-pills flex-column" aria-label="<?= h(t('nav.dashboard')) ?>">
                            <?php foreach ($navItems as $navPage => $label): ?>
                                <a
                                    class="nav-link <?= $page === $navPage ? 'active' : '' ?>"
                                    href="/?page=<?= h($navPage) ?>"
                                >
                                    <?= h($label) ?>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                        <div class="mobile-actions">
                            <button class="btn btn-primary" disabled>
                                <?= h(t('common.sync_start')) ?>
                            </button>
                            <a class="btn btn-outline-secondary" href="/?logout=1">
                                <?= h(t('common.logout')) ?>
                            </a>
                        </div>
                    </div>
                </div>
                <div>
                    <h1 class="h4 mb-0">
                        <?= h($page === 'hosting' ? t('hosting.title') : $navItems[$page]) ?>
                    </h1>
                </div>
                <div class="top-actions">
                    <a class="btn btn-outline-secondary" href="/?logout=1">
                        <?= h(t('common.logout')) ?>
                    </a>
                </div>
            </header>
            <main class="layout container-fluid">
                <?php if ($flash): ?>
                    <div class="alert <?= $flash['type'] === 'error' ? 'alert-danger' : 'alert-success' ?>">
                        <?= h($flash['message']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($page === 'dashboard'): ?>
                <section class="metrics row g-3 mb-4">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card metric-card">
                            <div class="card-body">
                                <strong><?= count($servers) ?></strong>
                                <span><?= h(t('dashboard.metric_servers')) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card metric-card">
                            <div class="card-body">
                                <strong><?= count($domains) ?></strong>
                                <span><?= h(t('dashboard.metric_domains')) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card metric-card">
                            <div class="card-body">
                                <strong><?= count($dashboardPackageMarkers) ?></strong>
                                <span><?= h(t('dashboard.metric_packages')) ?></span>
                            </div>
                        </div>
                    </div>
                </section>
                <section class="dashboard-grid" data-dashboard-grid>
                    <?php $dashboardServers = array_values(array_filter($servers, static fn(array $server): bool => (int)($server['active'] ?? 0) === 1)); ?>
                    <?php foreach ($dashboardServers as $server): ?>
                        <?php $status = server_status_placeholder_view($server); ?>
                        <article
                            class="card server-card server-card-skeleton"
                            data-server-id="<?= (int)$status['server_id'] ?>"
                            aria-label="<?= h(t('dashboard.server_loading')) ?>"
                        >
                            <header>
                                <h2>
                                    <?php if ($status['dashboard_url'] !== ''): ?>
                                        <a
                                            class="server-status-hostname server-dashboard-link"
                                            href="<?= h($status['dashboard_url']) ?>"
                                            target="<?= h($status['hostname']) ?>"
                                        >
                                            <?= h($status['hostname']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="server-status-hostname">
                                            <?= h($status['hostname']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?= server_ssh_link_html($server) ?>
                                </h2>

                                <div class="server-card-actions">
                                    <button
                                        type="button"
                                        class="server-status-time"
                                        data-bs-toggle="tooltip"
                                        data-bs-placement="top"
                                        data-bs-trigger="hover"
                                        data-bs-title="<?= h(t('dashboard.last_refresh_unknown')) ?>"
                                        aria-label="<?= h(t('dashboard.last_refresh_unknown')) ?>"
                                    >
                                        <?= icon_svg('clock') ?>
                                    </button>
                                    <button
                                        type="button"
                                        class="server-status-refresh"
                                        <?= icon_button_attrs(t('domains.refresh')) ?>
                                    >
                                        <?= icon_svg('refresh') ?>
                                        <span class="refresh-countdown" data-refresh-countdown></span>
                                    </button>
                                </div>
                            </header>

                            <p class="server-status-error error-text" hidden></p>
                            <p class="reboot-text" hidden>
                                <?= h(t('dashboard.reboot_required')) ?>
                                <button
                                    type="button"
                                    class="server-reboot-button icon-only status-tooltip"
                                    data-server-id="<?= (int)$status['server_id'] ?>"
                                    <?= icon_button_attrs(t('dashboard.reboot_server')) ?>
                                >
                                    <?= icon_svg('reboot') ?>
                                </button>
                            </p>

                            <dl class="server-facts">
                                <?php foreach ([
                                    ['os', 'OS'],
                                    ['kernel', 'Kernel'],
                                    ['panel', 'Control Panel'],
                                    ['cpu', 'CPU'],
                                    ['uptime', 'Uptime'],
                                    ['traffic', 'Traffic this month'],
                                    ['disk', 'Consumed disk space'],
                                ] as [$field, $label]): ?>
                                    <div>
                                        <dt><?= h($label) ?>:</dt>
                                        <dd class="is-placeholder" data-status-field="<?= h($field) ?>">
                                            <span class="skeleton-line"></span>
                                        </dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        </article>
                    <?php endforeach; ?>

                    <?php if (!$dashboardServers): ?>
                        <p class="empty"><?= h(t('js.no_active_servers')) ?></p>
                    <?php endif; ?>
                </section>
                <?php elseif ($page === 'domains'): ?>
                <section class="page-section card">
                    <div class="card-body" data-domain-import-area>
                        <?php render_partial('domains_content', compact('domains', 'returnPath', 'localUsers', 'billingDomainOverrides', 'billingTaxRates')); ?>
                    </div>
                </section>

                <?php elseif ($page === 'users'): ?>
                <section class="page-section card">
                    <div class="card-body" data-user-import-area>
                        <?php render_partial('users_content', compact(
                            'userGroups',
                            'returnPath',
                            'localUsers',
                            'remoteUsersByLocalUserId',
                            'domainsByLocalUserId',
                            'unassignedRemoteUsers',
                            'localUserDeleteBlockers',
                            'customerAccountBalances',
                            'customerAccountEntries',
                            'billingUserSettings',
                            'billingUserItems'
                        )); ?>
                    </div>
                </section>
                <?php include __DIR__ . '/app_user_modal.php'; ?>
                <?php include __DIR__ . '/app_local_user_modal.php'; ?>
                <?php include __DIR__ . '/app_remote_user_wizard.php'; ?>

                <?php elseif ($page === 'hosting'): ?>
                <?php
                $packageMarker = static function (array $package): string {
                    $name = (string)($package['name'] ?? '');
                    if (preg_match('/\[MSM:(pkg-[^\]]+)\]/', $name, $matches)) { return $matches[1]; }
                    $limits = json_decode((string)($package['limits_json'] ?? ''), true);
                    if (is_array($limits) && preg_match('/\[MSM:(pkg-[^\]]+)\]/', (string)($limits['name'] ?? ''), $matches)) { return $matches[1]; }
                    return 'external-' . (string)($package['id'] ?? '0');
                };
                $packageRows = [];
                $packageServerIdsByMarker = [];
                $packageServerNamesByMarker = [];
                foreach ($packages as $packageForMarker) {
                    $marker = $packageMarker($packageForMarker);
                    $serverId = (int)($packageForMarker['server_id'] ?? 0);
                    if (!isset($packageRows[$marker])) {
                        $packageRows[$marker] = $packageForMarker;
                    }
                    if ($serverId > 0) {
                        $packageServerIdsByMarker[$marker][$serverId] = $serverId;
                        $packageServerNamesByMarker[$marker][$serverId] = (string)($packageForMarker['server_name'] ?? '');
                    }
                }
                ?>
                <section class="page-section card">
                    <div class="card-body">
                        <div class="section-head">
                            <h2 class="h5 mb-0"><?= h(t('hosting.title')) ?></h2>
                            <div class="table-actions">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="_action" value="import_hosting_plans">
                                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                    <button
                                        class="btn btn-primary btn-sm icon-only status-tooltip"
                                        type="submit"
                                        <?= icon_button_attrs(t('hosting.import')) ?>
                                    >
                                        <?= icon_svg('refresh') ?>
                                    </button>
                                </form>
                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm icon-only status-tooltip hosting-package-create"
                                    <?= icon_button_attrs(t('hosting.create_action')) ?>
                                >
                                    <?= icon_svg('plus') ?>
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive mt-3">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th><?= h(t('hosting.name')) ?></th>
                                        <th><?= h(t('domains.server')) ?></th>
                                        <th class="text-end"><?= h(t('common.actions')) ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($packageRows as $marker => $package): ?>
                                        <?php
                                        $packageSelectedServerIds = array_values($packageServerIdsByMarker[$marker] ?? []);
                                        $packageServerNames = array_values(array_filter($packageServerNamesByMarker[$marker] ?? []));
                                        $packageDescription = trim((string)($package['description'] ?? ''));
                                        ?>
                                        <tr>
                                            <td><?= h($package['name']) ?></td>
                                            <td><?= h($packageServerNames ? implode(', ', $packageServerNames) : t('common.system_wide')) ?></td>
                                            <td class="text-end">
                                                <div class="table-actions">
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-secondary btn-sm icon-only status-tooltip hosting-package-edit"
                                                        <?= icon_button_attrs(t('common.edit')) ?>
                                                        data-package-id="<?= (int)$package['id'] ?>"
                                                        data-package-name="<?= h($package['name']) ?>"
                                                        data-package-description="<?= h($packageDescription) ?>"
                                                        data-package-server-id="<?= h((string)($package['server_id'] ?? '')) ?>"
                                                        data-package-server-ids="<?= h(implode(',', $packageSelectedServerIds)) ?>"
                                                        data-package-json="<?= h((string)($package['limits_json'] ?? '{}')) ?>"
                                                    >
                                                        <?= icon_svg('edit') ?>
                                                    </button>
                                                    <form
                                                        method="post"
                                                        class="d-inline"
                                                        onsubmit="return confirm('Kontovorlage wirklich löschen?')"
                                                    >
                                                        <input type="hidden" name="_action" value="delete_package">
                                                        <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                                        <input type="hidden" name="id" value="<?= (int)$package['id'] ?>">
                                                        <button
                                                            class="btn btn-outline-danger btn-sm icon-only status-tooltip"
                                                            type="submit"
                                                            <?= icon_button_attrs(t('common.delete')) ?>
                                                        >
                                                            <?= icon_svg('trash') ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php if ($packageDescription !== ''): ?>
                                            <tr class="hosting-package-description-row">
                                                <td colspan="3" class="small text-muted pt-0">
                                                    <?= h($packageDescription) ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (!$packageRows): ?>
                                        <tr>
                                            <td colspan="3" class="empty"><?= h(t('hosting.empty')) ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
                <?php include __DIR__ . '/app_hosting_modal.php'; ?>

                <?php elseif ($page === 'server'): ?>
                <section class="page-section card">
                    <div class="card-body">
                        <div class="section-head">
                            <h2 class="h5 mb-0"><?= h(t('server.title')) ?></h2>
                            <button
                                type="button"
                                class="btn btn-primary btn-sm icon-only status-tooltip"
                                data-bs-toggle="modal"
                                data-bs-target="#serverCreateModal"
                                <?= icon_button_attrs(t('server.create_title')) ?>
                            >
                                <?= icon_svg('plus') ?>
                            </button>
                        </div>

                        <div class="server-list">
                            <?php foreach ($servers as $server): ?>
                                <div class="server-item border rounded" data-server-id="<?= (int)$server['id'] ?>">
                                    <div class="server-summary">
                                        <div>
                                            <span class="server-name-line">
                                                <b class="server-name"><?= h($server['name']) ?></b>
                                                <span
                                                    class="server-active-dot <?= (int)$server['active'] === 1 ? 'is-active' : 'is-inactive' ?> status-tooltip"
                                                    <?= icon_button_attrs((int)$server['active'] === 1 ? t('server.active') : t('server.inactive')) ?>
                                                ></span>
                                            </span>
                                            <span class="server-url"><?= h($server['base_url']) ?></span>
                                        </div>

                                        <code class="server-key-preview">
                                            <?= h(substr((string)$server['api_token'], 0, 10)) ?>...
                                        </code>

                                        <div class="table-actions">
                                            <button
                                                type="button"
                                                class="btn btn-outline-secondary btn-sm icon-only status-tooltip server-edit-toggle"
                                                <?= icon_button_attrs(t('common.edit')) ?>
                                            >
                                                <?= icon_svg('edit') ?>
                                            </button>
                                            <form
                                                method="post"
                                                class="d-inline"
                                                onsubmit="return confirm('Server wirklich löschen? Alle lokal gespeicherten Daten dieses Servers werden entfernt.')"
                                            >
                                                <input type="hidden" name="_action" value="delete_server">
                                                <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                                <input type="hidden" name="id" value="<?= (int)$server['id'] ?>">
                                                <button
                                                    class="btn btn-outline-danger btn-sm icon-only status-tooltip"
                                                    type="submit"
                                                    <?= icon_button_attrs(t('common.delete')) ?>
                                                >
                                                    <?= icon_svg('trash') ?>
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                    <form method="post" class="server-editor ajax-server-form" hidden>
                                        <input type="hidden" name="_action" value="update_server">
                                        <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$server['id'] ?>">
                                        <input class="form-control" name="name" value="<?= h($server['name']) ?>" required>
                                        <input class="form-control" name="base_url" value="<?= h($server['base_url']) ?>" required>
                                        <input
                                            class="form-control"
                                            name="api_token"
                                            type="password"
                                            autocomplete="new-password"
                                            placeholder="<?= h(t('server.new_api_key')) ?>"
                                        >
                                        <label class="form-check check">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="ssh_link_enabled"
                                                value="1"
                                                <?= (int)($server['ssh_link_enabled'] ?? 0) === 1 ? 'checked' : '' ?>
                                            >
                                            <?= h(t('server.ssh_link_enabled')) ?>
                                        </label>
                                        <input
                                            class="form-control"
                                            name="ssh_port"
                                            type="number"
                                            min="1"
                                            max="65535"
                                            value="<?= h((string)($server['ssh_port'] ?? 22)) ?>"
                                            placeholder="<?= h(t('server.ssh_port')) ?>"
                                        >
                                        <input
                                            class="form-control"
                                            name="ssh_username"
                                            value="<?= h((string)($server['ssh_username'] ?? '')) ?>"
                                            placeholder="<?= h(t('server.ssh_username')) ?>"
                                        >
                                        <label class="form-check check">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="active"
                                                value="1"
                                                <?= (int)$server['active'] === 1 ? 'checked' : '' ?>
                                            >
                                            <?= h(t('server.active')) ?>
                                        </label>
                                        <button
                                            class="btn btn-primary icon-only status-tooltip"
                                            type="submit"
                                            <?= icon_button_attrs(t('common.save')) ?>
                                        >
                                            <?= icon_svg('save') ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <div
                    class="modal fade"
                    id="serverCreateModal"
                    tabindex="-1"
                    aria-hidden="true"
                    data-bs-backdrop="static"
                    data-bs-keyboard="false"
                >
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post" class="stack">
                                <div class="modal-header">
                                    <h2 class="modal-title h5"><?= h(t('server.create_title')) ?></h2>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>

                                <div class="modal-body stack">
                                    <input type="hidden" name="_action" value="add_server">
                                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                    <input class="form-control" name="name" placeholder="<?= h(t('hosting.name')) ?>" required>
                                    <input class="form-control" name="base_url" placeholder="https://server.example.tld" required>
                                    <input
                                        class="form-control"
                                        name="api_token"
                                        type="password"
                                        autocomplete="new-password"
                                        placeholder="<?= h(t('server.api_token')) ?>"
                                        required
                                    >
                                    <label class="form-check check">
                                        <input class="form-check-input" type="checkbox" name="ssh_link_enabled" value="1">
                                        <?= h(t('server.ssh_link_enabled')) ?>
                                    </label>
                                    <input
                                        class="form-control"
                                        name="ssh_port"
                                        type="number"
                                        min="1"
                                        max="65535"
                                        value="22"
                                        placeholder="<?= h(t('server.ssh_port')) ?>"
                                    >
                                    <input
                                        class="form-control"
                                        name="ssh_username"
                                        placeholder="<?= h(t('server.ssh_username')) ?>"
                                    >
                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                        <?= h(t('common.cancel')) ?>
                                    </button>
                                    <button
                                        class="btn btn-primary icon-only status-tooltip"
                                        type="submit"
                                        <?= icon_button_attrs(t('common.save')) ?>
                                    >
                                        <?= icon_svg('save') ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php elseif ($page === 'billing'): ?>
                <?php render_partial('billing_content', [
                    'billing' => $billingData,
                    'returnPath' => $returnPath,
                    'userGroups' => $userGroups,
                    'domains' => $domains,
                ]); ?>
                <?php include __DIR__ . '/app_invoice_payment_modal.php'; ?>
                <?php elseif ($page === 'config'): ?>
                <?php render_partial('config_content', compact(
                    'billingData',
                    'returnPath',
                    'supportedLocales',
                    'appLocale',
                    'themeMode',
                    'formatSettings',
                    'themeModeOptions',
                    'formatLocaleOptions',
                    'dateFormatOptions',
                    'timeFormatOptions',
                    'decimalSeparatorOptions',
                    'serverRefreshInterval',
                    'serverRefreshIntervalOptions',
                    'usernamePattern'
                )); ?>
                <?php endif; ?>
            </main>
        </div>
        <script>
            window.KH_I18N = <?= json_encode($jsMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            window.KH_FORMATS = <?= json_encode($formatSettings ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/dashbrd/dist/assets/js/theme.bundle.js"></script>
        <script type="module" src="/assets/app.js?v=<?= (int)filemtime(dirname(__DIR__) . '/public/assets/app.js') ?>"></script>
    </body>
</html>
