<?php $initialTheme = ($themeMode ?? 'auto') === 'dark' ? 'dark' : 'light'; ?>
<!doctype html>
<html lang="<?= h(current_locale()) ?>" data-theme-mode="<?= h($themeMode ?? 'auto') ?>" data-bs-theme="<?= h($initialTheme) ?>">

    <?php render_partial('head', ['config' => $config, 'title' => $config['app']['name']]); ?>

    <body class="app-shell" data-page="<?= h($page) ?>" data-server-refresh-interval="<?= (int)$serverRefreshInterval ?>">
        <aside class="sidebar bg-white border-end">
            <a class="sidebar-brand app-logo-link" href="/" aria-label="<?= h($config['app']['name']) ?>"><img class="app-logo app-logo-sidebar" src="/assets/khmsm_fulllogo_512.png" alt="<?= h($config['app']['name']) ?>"></a>
            <nav class="side-nav nav nav-pills flex-column" aria-label="<?= h(t('nav.dashboard')) ?>">
                <?php foreach ($navItems as $navPage => $label): ?>
                <a class="nav-link <?= $page === $navPage ? 'active' : '' ?>" href="/?page=<?= h($navPage) ?>">
                    <?= h($label) ?>
                </a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <div class="app-main">
            <header class="topbar navbar bg-white border-bottom sticky-top">
                <div>
                    <h1 class="h4 mb-0"><?= h($navItems[$page]) ?></h1>
                </div>
                <div class="top-actions">
                    <form method="post"><input type="hidden" name="_action" value="run_sync"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><button class="btn btn-primary"><?= h(t('common.sync_start')) ?></button></form>
                    <a class="btn btn-outline-secondary" href="/?logout=1"><?= h(t('common.logout')) ?></a>
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
                            <div class="card-body"><strong><?= count($servers) ?></strong><span><?= h(t('dashboard.metric_servers')) ?></span></div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card metric-card">
                            <div class="card-body"><strong><?= count($domains) ?></strong><span><?= h(t('dashboard.metric_domains')) ?></span></div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card metric-card">
                            <div class="card-body"><strong><?= count($packages) ?></strong><span><?= h(t('dashboard.metric_packages')) ?></span></div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card metric-card">
                            <div class="card-body"><strong><?= count($actions) ?></strong><span><?= h(t('dashboard.metric_actions')) ?></span></div>
                        </div>
                    </div>
                </section>
                <section class="dashboard-grid" data-dashboard-grid>
                    <article class="card server-card server-card-skeleton" aria-label="<?= h(t('dashboard.server_loading')) ?>">
                        <header><span class="skeleton-line wide"></span><span class="skeleton-circle"></span></header>
                        <div class="skeleton-facts">
                            <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                        </div>
                    </article>
                </section>

                <?php elseif ($page === 'domains'): ?>
                <section class="page-section card">
                    <div class="card-body">
                        <div class="section-head">
                            <h2 class="h5 mb-0"><?= h(t('domains.title')) ?></h2>
                            <form method="post"><input type="hidden" name="_action" value="import_domains"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><button class="btn btn-primary"><?= h(t('domains.import')) ?></button></form>
                        </div>
                        <div class="table-responsive mt-3">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th><?= h(t('domains.domain')) ?></th>
                                        <th><?= h(t('domains.server')) ?></th>
                                        <th><?= h(t('domains.owner')) ?></th>
                                        <th><?= h(t('domains.registered')) ?></th>
                                        <th><?= h(t('domains.next_billing')) ?></th>
                                        <th><?= h(t('domains.registrar')) ?></th>
                                        <th><?= h(t('domains.deletion')) ?></th>
                                        <th><?= h(t('domains.subdomains')) ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($domains as $domain): ?>
                                    <tr class="domain-row <?= h(domain_row_class($domain)) ?>" data-domain-id="<?= (int)$domain['id'] ?>" data-domain-name="<?= h($domain['domain']) ?>">
                                        <td><?= h($domain['domain']) ?></td>
                                        <td><?= h($domain['server_name']) ?></td>
                                        <td><?= h($domain['owner_name'] ?: ($domain['owner_external_id'] ? 'User #' . $domain['owner_external_id'] : '')) ?></td>
                                        <td><?php if ($domain['registered_at']): ?><span class="readonly-value" data-field="registered_at"><?= h(format_date_local($domain['registered_at'])) ?></span><?php else: ?><input class="form-control" type="date" name="registered_at" value=""><?php endif; ?></td>
                                        <td><?php if ($domain['next_billing_at']): ?><span class="readonly-value" data-field="next_billing_at"><?= h(format_date_local($domain['next_billing_at'])) ?></span><?php else: ?><input class="form-control" type="date" name="next_billing_at" value=""><?php endif; ?></td>
                                        <td><input class="form-control" name="registrar" value="<?= h($domain['registrar']) ?>"></td>
                                        <td class="domain-status-cell"><?= domain_status_html($domain) ?></td>
                                        <td><button type="button" class="btn btn-outline-secondary btn-sm subdomain-toggle" data-server-id="<?= (int)$domain['server_id'] ?>" data-domain="<?= h($domain['domain']) ?>"><?= h(t('common.show')) ?></button></td>
                                        <td>
                                            <div class="domain-actions"><button type="button" class="btn btn-outline-secondary icon-button domain-refresh" title="<?= h(t('domains.refresh')) ?>" aria-label="<?= h(t('domains.refresh')) ?>"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-clockwise" viewBox="0 0 16 16" aria-hidden="true">
                                                        <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z" />
                                                        <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466" />
                                                    </svg></button><button type="button" class="btn btn-primary btn-sm domain-save"><?= h(t('common.save')) ?></button></div>
                                        </td>
                                    </tr>
                                    <tr class="subdomain-row" id="subdomains-<?= (int)$domain['id'] ?>" hidden>
                                        <td colspan="9">
                                            <div class="subdomain-box"><?= h(t('common.loading')) ?></div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <?php elseif ($page === 'users'): ?>
                <section class="row g-4 align-items-start">
                    <div class="col-12 col-xl">
                        <div class="card">
                            <div class="card-body">
                                <div class="section-head">
                                    <h2 class="h5 mb-0"><?= h(t('users.title')) ?></h2>
                                </div>
                                <div class="async-loader" data-users-loader><span class="spinner" aria-hidden="true"></span><span><?= h(t('users.loading')) ?></span></div>
                                <div class="users-result" data-users-result hidden></div>
                            </div>
                        </div>
                    </div>
                    <aside class="col-12 col-xl-4">
                        <div class="card form-panel">
                            <div class="card-body">
                                <h2 class="h5"><?= h(t('users.queue_title')) ?></h2>
                                <form method="post" class="stack"><input type="hidden" name="_action" value="queue_user"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><input class="form-control" name="username" placeholder="<?= h(t('users.username')) ?>" required><input class="form-control" name="email" type="email" placeholder="<?= h(t('users.email')) ?>" required><input class="form-control" name="password" type="password" placeholder="<?= h(t('users.initial_password')) ?>" required><select class="form-select" name="server_id">
                                        <option value=""><?= h(t('common.system_wide')) ?></option><?php foreach ($servers as $server): ?><option value="<?= (int)$server['id'] ?>"><?= h($server['name']) ?></option><?php endforeach; ?>
                                    </select><button class="btn btn-primary"><?= h(t('users.queue_action')) ?></button></form>
                            </div>
                        </div>
                    </aside>
                </section>

                <?php elseif ($page === 'hosting'): ?>
                <section class="row g-4 align-items-start">
                    <div class="col-12 col-xl">
                        <div class="card">
                            <div class="card-body">
                                <div class="section-head">
                                    <h2 class="h5 mb-0"><?= h(t('hosting.title')) ?></h2>
                                    <form method="post"><input type="hidden" name="_action" value="import_hosting_plans"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><button class="btn btn-primary"><?= h(t('hosting.import')) ?></button></form>
                                </div>
                                <div class="table-responsive mt-3">
                                    <table class="table table-hover align-middle">
                                        <thead>
                                            <tr>
                                                <th><?= h(t('hosting.name')) ?></th>
                                                <th><?= h(t('hosting.scope')) ?></th>
                                                <th><?= h(t('domains.server')) ?></th>
                                                <th><?= h(t('hosting.source')) ?></th>
                                                <th><?= h(t('hosting.description')) ?></th>
                                            </tr>
                                        </thead>
                                        <tbody><?php foreach ($packages as $package): ?><tr>
                                                <td><?= h($package['name']) ?></td>
                                                <td><?= h($package['scope']) ?></td>
                                                <td><?= h($package['server_name'] ?: t('common.system_wide')) ?></td>
                                                <td><?= $package['external_id'] ? 'KeyHelp #' . h($package['external_id']) : h(t('common.local')) ?></td>
                                                <td><?= h($package['description']) ?></td>
                                            </tr><?php endforeach; ?><?php if (!$packages): ?><tr>
                                                <td colspan="5" class="empty"><?= h(t('hosting.empty')) ?></td>
                                            </tr><?php endif; ?></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <aside class="col-12 col-xl-4">
                        <div class="card form-panel">
                            <div class="card-body">
                                <h2 class="h5"><?= h(t('hosting.create_title')) ?></h2>
                                <form method="post" class="stack"><input type="hidden" name="_action" value="add_package"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><input class="form-control" name="name" placeholder="<?= h(t('hosting.package_name')) ?>" required><textarea class="form-control" name="description" placeholder="<?= h(t('hosting.description')) ?>"></textarea><textarea class="form-control" name="limits_json" placeholder='{"diskSpace": 10240, "mailboxes": 10}'>{}</textarea><select class="form-select" name="scope">
                                        <option value="system"><?= h(t('common.system_wide')) ?></option>
                                        <option value="server"><?= h(t('hosting.scope_server')) ?></option>
                                    </select><select class="form-select" name="server_id">
                                        <option value=""><?= h(t('common.all_servers')) ?></option><?php foreach ($servers as $server): ?><option value="<?= (int)$server['id'] ?>"><?= h($server['name']) ?></option><?php endforeach; ?>
                                    </select><button class="btn btn-primary"><?= h(t('hosting.create_action')) ?></button></form>
                            </div>
                        </div>
                    </aside>
                </section>

                <?php elseif ($page === 'server'): ?>
                <section class="row g-4 align-items-start">
                    <div class="col-12 col-xl">
                        <div class="card">
                            <div class="card-body">
                                <h2 class="h5"><?= h(t('server.title')) ?></h2>
                                <div class="server-list"><?php foreach ($servers as $server): ?><div class="server-item border rounded" data-server-id="<?= (int)$server['id'] ?>">
                                        <div class="server-summary">
                                            <div><b class="server-name"><?= h($server['name']) ?></b><span class="server-url"><?= h($server['base_url']) ?></span></div><code class="server-key-preview"><?= h(substr((string)$server['api_token'], 0, 10)) ?>...</code><span class="badge status <?= (int)$server['active'] === 1 ? 'text-bg-success' : 'text-bg-danger' ?>"><?= (int)$server['active'] === 1 ? h(t('server.active')) : h(t('server.inactive')) ?></span><button type="button" class="btn btn-outline-secondary btn-sm server-edit-toggle"><?= h(t('common.edit')) ?></button>
                                        </div>
                                        <form method="post" class="server-editor ajax-server-form" hidden><input type="hidden" name="_action" value="update_server"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><input type="hidden" name="id" value="<?= (int)$server['id'] ?>"><input class="form-control" name="name" value="<?= h($server['name']) ?>" required><input class="form-control" name="base_url" value="<?= h($server['base_url']) ?>" required><input class="form-control" name="api_token" type="password" autocomplete="new-password" placeholder="<?= h(t('server.new_api_key')) ?>"><label class="form-check check"><input class="form-check-input" type="checkbox" name="active" value="1" <?= (int)$server['active'] === 1 ? 'checked' : '' ?>> <?= h(t('server.active')) ?></label><button class="btn btn-primary"><?= h(t('common.update')) ?></button></form>
                                    </div><?php endforeach; ?></div>
                            </div>
                        </div>
                    </div>
                    <aside class="col-12 col-xl-4">
                        <div class="card form-panel">
                            <div class="card-body">
                                <h2 class="h5"><?= h(t('server.create_title')) ?></h2>
                                <form method="post" class="stack"><input type="hidden" name="_action" value="add_server"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><input class="form-control" name="name" placeholder="<?= h(t('hosting.name')) ?>" required><input class="form-control" name="base_url" placeholder="https://server.example.tld" required><input class="form-control" name="api_token" type="password" autocomplete="new-password" placeholder="<?= h(t('server.api_token')) ?>" required><button class="btn btn-primary"><?= h(t('server.save')) ?></button></form>
                            </div>
                        </div>
                    </aside>
                </section>

                <?php elseif ($page === 'config'): ?>
                <section class="card config-page">
                    <div class="card-body">
                        <div class="section-head">
                            <h2 class="h5 mb-0"><?= h(t('config.title')) ?></h2>
                        </div>
                        <form method="post" class="settings-form mt-3"><input type="hidden" name="_action" value="update_config"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><label class="form-label" for="locale"><?= h(t('common.language')) ?></label><select class="form-select" id="locale" name="locale"><?php foreach ($supportedLocales as $localeCode => $localeLabel): ?><option value="<?= h($localeCode) ?>" <?= $localeCode === $appLocale ? 'selected' : '' ?>><?= h($localeLabel) ?></option><?php endforeach; ?></select><label class="form-label" for="theme_mode"><?= h(t('config.theme_mode')) ?></label><select class="form-select" id="theme_mode" name="theme_mode"><?php foreach ($themeModeOptions as $option): ?><option value="<?= h($option) ?>" <?= $option === $themeMode ? 'selected' : '' ?>><?= h(t('theme.' . $option)) ?></option><?php endforeach; ?></select><label class="form-label" for="server_refresh_interval"><?= h(t('config.refresh_interval')) ?></label><select class="form-select" id="server_refresh_interval" name="server_refresh_interval"><?php foreach ($serverRefreshIntervalOptions as $option): ?><option value="<?= (int)$option ?>" <?= (int)$option === (int)$serverRefreshInterval ? 'selected' : '' ?>><?= (int)$option === 0 ? h(t('common.off')) : (int)$option . ' ' . h(t('common.seconds')) ?></option><?php endforeach; ?></select><button class="btn btn-primary"><?= h(t('config.save')) ?></button></form>
                    </div>
                </section>
                <?php endif; ?>
            </main>
        </div>
        <script>window.KH_I18N = <?= json_encode($jsMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/dashbrd/dist/assets/js/theme.bundle.js"></script>
        <script type="module" src="/assets/app.js?v=<?= (int)filemtime(dirname(__DIR__) . '/public/assets/app.js') ?>"></script>
    </body>

</html>