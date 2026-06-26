<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($config['app']['name']) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="app-shell" data-page="<?= h($page) ?>" data-server-refresh-interval="<?= (int)$serverRefreshInterval ?>">
<aside class="sidebar">
    <div class="sidebar-brand"><span class="brand-mark">K</span><strong><?= h($config['app']['name']) ?></strong></div>
    <nav class="side-nav" aria-label="Hauptnavigation">
        <?php foreach ($navItems as $navPage => $label): ?>
            <a class="<?= $page === $navPage ? 'active' : '' ?>" href="/?page=<?= h($navPage) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
<div class="app-main">
    <header class="topbar">
        <div><h1><?= h($navItems[$page]) ?></h1></div>
        <div class="top-actions">
            <form method="post"><input type="hidden" name="_action" value="run_sync"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><button class="primary">Sync starten</button></form>
            <a class="ghost" href="/?logout=1">Logout</a>
        </div>
    </header>
    <main class="layout">
        <?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

        <?php if ($page === 'dashboard'): ?>
            <section class="metrics">
                <div><strong><?= count($servers) ?></strong><span>Server</span></div>
                <div><strong><?= count($domains) ?></strong><span>Domains</span></div>
                <div><strong><?= count($packages) ?></strong><span>Pakete</span></div>
                <div><strong><?= count($actions) ?></strong><span>offene Aktionen</span></div>
            </section>
            <section class="dashboard-grid" data-dashboard-grid>
                <article class="server-card server-card-skeleton" aria-label="Serverdaten werden geladen">
                    <header><span class="skeleton-line wide"></span><span class="skeleton-circle"></span></header>
                    <div class="skeleton-facts">
                        <span></span><span></span>
                        <span></span><span></span>
                        <span></span><span></span>
                        <span></span><span></span>
                        <span></span><span></span>
                        <span></span><span></span>
                        <span></span><span></span>
                    </div>
                </article>
            </section>

        <?php elseif ($page === 'domains'): ?>
            <section class="page-section">
                <div class="section-head"><h2>Domains</h2><form method="post"><input type="hidden" name="_action" value="import_domains"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><button>Domains einlesen</button></form></div>
                <div class="table-wrap"><table><thead><tr><th>Domain</th><th>Server</th><th>Benutzer</th><th>Registriert</th><th>Naechste Abrechnung</th><th>Anbieter</th><th>Loeschung</th><th>Subdomains</th><th></th></tr></thead><tbody>
                    <?php foreach ($domains as $domain): ?>
                        <tr class="domain-row <?= h(domain_row_class($domain)) ?>" data-domain-id="<?= (int)$domain['id'] ?>" data-domain-name="<?= h($domain['domain']) ?>">
                            <td><?= h($domain['domain']) ?></td>
                            <td><?= h($domain['server_name']) ?></td>
                            <td><?= h($domain['owner_name'] ?: ($domain['owner_external_id'] ? 'User #' . $domain['owner_external_id'] : '')) ?></td>
                            <td><?php if ($domain['registered_at']): ?><span class="readonly-value" data-field="registered_at"><?= h($domain['registered_at']) ?></span><?php else: ?><input type="date" name="registered_at" value=""><?php endif; ?></td>
                            <td><?php if ($domain['next_billing_at']): ?><span class="readonly-value" data-field="next_billing_at"><?= h($domain['next_billing_at']) ?></span><?php else: ?><input type="date" name="next_billing_at" value=""><?php endif; ?></td>
                            <td><input name="registrar" value="<?= h($domain['registrar']) ?>"></td>
                            <td class="domain-status-cell"><?= domain_status_html($domain) ?></td>
                            <td><button type="button" class="subdomain-toggle" data-server-id="<?= (int)$domain['server_id'] ?>" data-domain="<?= h($domain['domain']) ?>">anzeigen</button></td>
                            <td><div class="domain-actions"><button type="button" class="icon-button domain-refresh" title="Domain vom Server aktualisieren" aria-label="Domain vom Server aktualisieren"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-clockwise" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z"/><path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466"/></svg></button><button type="button" class="domain-save">Speichern</button></div></td>
                        </tr>
                        <tr class="subdomain-row" id="subdomains-<?= (int)$domain['id'] ?>" hidden><td colspan="9"><div class="subdomain-box">Wird geladen...</div></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            </section>

        <?php elseif ($page === 'users'): ?>
            <section class="page-section split-layout">
                <div>
                    <div class="section-head"><h2>Benutzer</h2></div>
                    <div class="async-loader" data-users-loader><span class="spinner" aria-hidden="true"></span><span>Benutzer werden geladen...</span></div>
                    <div class="users-result" data-users-result hidden></div>
                </div>
                <aside class="form-panel">
                    <h2>Benutzer vormerken</h2>
                    <form method="post" class="stack">
                        <input type="hidden" name="_action" value="queue_user"><input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                        <input name="username" placeholder="Benutzername" required>
                        <input name="email" type="email" placeholder="E-Mail" required>
                        <input name="password" type="password" placeholder="Initiales Passwort" required>
                        <select name="server_id"><option value="">systemweit</option><?php foreach ($servers as $server): ?><option value="<?= (int)$server['id'] ?>"><?= h($server['name']) ?></option><?php endforeach; ?></select>
                        <button>Aktion vormerken</button>
                    </form>
                </aside>
            </section>

        <?php elseif ($page === 'hosting'): ?>
            <section class="page-section split-layout">
                <div>
                    <div class="section-head"><h2>Hostingpakete</h2><form method="post"><input type="hidden" name="_action" value="import_hosting_plans"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><button>Hostingplaene einlesen</button></form></div>
                    <div class="table-wrap"><table><thead><tr><th>Name</th><th>Scope</th><th>Server</th><th>Quelle</th><th>Beschreibung</th></tr></thead><tbody>
                        <?php foreach ($packages as $package): ?><tr><td><?= h($package['name']) ?></td><td><?= h($package['scope']) ?></td><td><?= h($package['server_name'] ?: 'systemweit') ?></td><td><?= $package['external_id'] ? 'KeyHelp #' . h($package['external_id']) : 'lokal' ?></td><td><?= h($package['description']) ?></td></tr><?php endforeach; ?>
                        <?php if (!$packages): ?><tr><td colspan="5" class="empty">Keine Hostingpakete angelegt.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </div>
                <aside class="form-panel">
                    <h2>Paket anlegen</h2>
                    <form method="post" class="stack">
                        <input type="hidden" name="_action" value="add_package"><input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                        <input name="name" placeholder="Paketname" required>
                        <textarea name="description" placeholder="Beschreibung"></textarea>
                        <textarea name="limits_json" placeholder='{"diskSpace": 10240, "mailboxes": 10}'>{}</textarea>
                        <select name="scope"><option value="system">systemweit</option><option value="server">serverspezifisch</option></select>
                        <select name="server_id"><option value="">alle Server</option><?php foreach ($servers as $server): ?><option value="<?= (int)$server['id'] ?>"><?= h($server['name']) ?></option><?php endforeach; ?></select>
                        <button>Paket anlegen und vormerken</button>
                    </form>
                </aside>
            </section>

        <?php elseif ($page === 'server'): ?>
            <section class="page-section split-layout">
                <div>
                    <h2>Server</h2>
                    <div class="server-list">
                        <?php foreach ($servers as $server): ?>
                            <div class="server-item" data-server-id="<?= (int)$server['id'] ?>">
                                <div class="server-summary">
                                    <div><b class="server-name"><?= h($server['name']) ?></b><span class="server-url"><?= h($server['base_url']) ?></span></div>
                                    <code class="server-key-preview"><?= h(substr((string)$server['api_token'], 0, 10)) ?>...</code>
                                    <span class="status <?= (int)$server['active'] === 1 ? 'on' : 'off' ?>"><?= (int)$server['active'] === 1 ? 'aktiv' : 'inaktiv' ?></span>
                                    <button type="button" class="server-edit-toggle">Bearbeiten</button>
                                </div>
                                <form method="post" class="server-editor ajax-server-form" hidden>
                                    <input type="hidden" name="_action" value="update_server"><input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$server['id'] ?>">
                                    <input name="name" value="<?= h($server['name']) ?>" required>
                                    <input name="base_url" value="<?= h($server['base_url']) ?>" required>
                                    <input name="api_token" type="password" autocomplete="new-password" placeholder="Neuer API-Key, leer lassen fuer unveraendert">
                                    <label class="check"><input type="checkbox" name="active" value="1" <?= (int)$server['active'] === 1 ? 'checked' : '' ?>> aktiv</label>
                                    <button>Aktualisieren</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <aside class="form-panel">
                    <h2>Server anlegen</h2>
                    <form method="post" class="stack">
                        <input type="hidden" name="_action" value="add_server"><input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                        <input name="name" placeholder="Name" required>
                        <input name="base_url" placeholder="https://server.example.tld" required>
                        <input name="api_token" type="password" autocomplete="new-password" placeholder="API Token" required>
                        <button>Server speichern</button>
                    </form>
                </aside>
            </section>

        <?php elseif ($page === 'config'): ?>
            <section class="page-section config-page">
                <div class="section-head"><h2>Konfiguration</h2></div>
                <form method="post" class="settings-form">
                    <input type="hidden" name="_action" value="update_config">
                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                    <label for="server_refresh_interval">Serverstatus automatisch aktualisieren</label>
                    <select id="server_refresh_interval" name="server_refresh_interval">
                        <?php foreach ($serverRefreshIntervalOptions as $option): ?>
                            <option value="<?= (int)$option ?>" <?= (int)$option === (int)$serverRefreshInterval ? 'selected' : '' ?>><?= (int)$option ?> Sekunden</option>
                        <?php endforeach; ?>
                    </select>
                    <button>Konfiguration speichern</button>
                </form>
            </section>
        <?php endif; ?>
    </main>
</div><script type="module" src="/assets/app.js"></script>
</body>
</html>
