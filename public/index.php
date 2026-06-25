<?php
session_start();
$configFile = dirname(__DIR__) . '/config/config.php';
$config = file_exists($configFile) ? require $configFile : require dirname(__DIR__) . '/config/config.example.php';
date_default_timezone_set($config['app']['timezone']);

require dirname(__DIR__) . '/src/helpers.php';
require dirname(__DIR__) . '/src/Logger.php';

set_exception_handler(static function (Throwable $exception) use ($config): void {
    log_exception($config, $exception, 'Unerwarteter Anwendungsfehler.', ['handler' => 'global']);
    http_response_code(500);
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (str_contains($accept, 'application/json') || (($_POST['_ajax'] ?? '') === '1') || isset($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Die Aktion konnte nicht ausgefuehrt werden. Details wurden ins Log geschrieben.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title><p>Die Anwendung konnte die Anfrage nicht verarbeiten. Details wurden ins Log geschrieben.</p>';
});

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'login') {
    $userMatches = hash_equals((string)$config['app']['admin_user'], (string)($_POST['user'] ?? ''));
    $passwordMatches = password_verify((string)($_POST['password'] ?? ''), $config['app']['admin_password_hash']);
    if ($userMatches && $passwordMatches) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        redirect_with('Willkommen.');
    }
    $_SESSION['flash'] = ['message' => 'Login fehlgeschlagen.', 'type' => 'error'];
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
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($config['app']['name']) ?></title>
        <link rel="stylesheet" href="/assets/app.css">
    </head>
    <body class="login-screen">
        <form method="post" class="login-card">
            <input type="hidden" name="_action" value="login">
            <span class="brand-mark">K</span>
            <h1>KeyHelp Verwaltung</h1>
            <?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
            <input name="user" placeholder="Benutzer" required autofocus>
            <input name="password" type="password" placeholder="Passwort" required>
            <button class="primary">Einloggen</button>
        </form>
    
</body>
    </html>
    <?php
    exit;
}

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/DomainOwner.php';
require dirname(__DIR__) . '/src/KeyHelpClient.php';
require dirname(__DIR__) . '/src/Repository.php';
require dirname(__DIR__) . '/src/SyncService.php';

$repo = new Repository(Database::connect($config));
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
        echo json_encode(['ok' => false, 'message' => 'Die Subdomains konnten nicht geladen werden. Details wurden ins Log geschrieben.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = $_POST['_action'] ?? '';
        if ($action === 'update_domain') {
            $repo->updateDomainBilling($_POST);
            $domain = $repo->domain((int)$_POST['id']);
            echo json_encode(['ok' => true, 'message' => 'Domain gespeichert.', 'domain' => $domain], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'update_server') {
            $repo->updateServer($_POST);
            $server = $repo->server((int)$_POST['id']);
            echo json_encode([
                'ok' => true,
                'message' => 'Server gespeichert.',
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
        throw new RuntimeException('Unbekannte AJAX-Aktion.');
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
        match ($action) {
            'add_server' => $repo->addServer($_POST),
            'update_server' => $repo->updateServer($_POST),
            'add_package' => $repo->addPackage($_POST),
            'queue_user' => $repo->queue('create_user', ($_POST['server_id'] ?? '') !== '' ? (int)$_POST['server_id'] : null, $_POST),
            'update_domain' => $repo->updateDomainBilling($_POST),
            'import_domains' => redirect_with($sync->importDomains()),
            'run_sync' => redirect_with($sync->runQueue()),
            default => throw new RuntimeException('Unbekannte Aktion.'),
        };
        redirect_with('Gespeichert.');
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
$domainStatusHtml = static function (array $domain): string {
    if (!empty($domain['delete_on'])) {
        return '<span class="domain-state delete" title="Zur Loeschung vorgemerkt"><span class="status-icon">&#9003;</span><span>' . h($domain['delete_on']) . '</span></span>';
    }
    if (!empty($domain['is_disabled']) || ((string)($domain['domain_status'] ?? '') !== '' && (int)$domain['domain_status'] !== 1)) {
        return '<span class="domain-state locked" title="Gesperrt oder deaktiviert"><span class="status-icon">&#128274;</span><span>gesperrt</span></span>';
    }
    return '';
};
$domainRowClass = static function (array $domain): string {
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
};
$packages = $repo->packages();
$actions = $repo->actions();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($config['app']['name']) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div>
        <span class="brand-mark">K</span>
        <strong>KeyHelp Verwaltung</strong>
    </div>
    <div class="top-actions">
        <form method="post"><input type="hidden" name="_action" value="run_sync"><button class="primary">Sync starten</button></form>
        <a class="ghost" href="/?logout=1">Logout</a>
    </div>
</header>
<main class="layout">
    <?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
    <section class="metrics">
        <div><strong><?= count($servers) ?></strong><span>Server</span></div>
        <div><strong><?= count($domains) ?></strong><span>Domains</span></div>
        <div><strong><?= count($packages) ?></strong><span>Pakete</span></div>
        <div><strong><?= count($actions) ?></strong><span>offene Aktionen</span></div>
    </section>

    <section class="grid two">
        <div class="panel">
            <h2>Server</h2>
            <form method="post" class="stack">
                <input type="hidden" name="_action" value="add_server">
                <input name="name" placeholder="Name" required>
                <input name="base_url" placeholder="https://server.example.tld" required>
                <input name="api_token" type="password" autocomplete="new-password" placeholder="API Token" required>
                <button>Server speichern</button>
            </form>
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
                            <input type="hidden" name="_action" value="update_server">
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

        <div class="panel">
            <h2>Benutzer vormerken</h2>
            <form method="post" class="stack">
                <input type="hidden" name="_action" value="queue_user">
                <input name="username" placeholder="Benutzername" required>
                <input name="email" type="email" placeholder="E-Mail" required>
                <input name="password" type="password" placeholder="Initiales Passwort" required>
                <select name="server_id"><option value="">systemweit</option><?php foreach ($servers as $server): ?><option value="<?= (int)$server['id'] ?>"><?= h($server['name']) ?></option><?php endforeach; ?></select>
                <button>Aktion vormerken</button>
            </form>
        </div>
    </section>

    <section class="grid two">
        <div class="panel">
            <h2>Hostingpakete</h2>
            <form method="post" class="stack">
                <input type="hidden" name="_action" value="add_package">
                <input name="name" placeholder="Paketname" required>
                <textarea name="description" placeholder="Beschreibung"></textarea>
                <textarea name="limits_json" placeholder='{"diskSpace": 10240, "mailboxes": 10}'>{}</textarea>
                <select name="scope"><option value="system">systemweit</option><option value="server">serverspezifisch</option></select>
                <select name="server_id"><option value="">alle Server</option><?php foreach ($servers as $server): ?><option value="<?= (int)$server['id'] ?>"><?= h($server['name']) ?></option><?php endforeach; ?></select>
                <button>Paket anlegen und vormerken</button>
            </form>
        </div>

        <div class="panel">
            <h2>Sync-Warteschlange</h2>
            <div class="queue">
                <?php foreach ($actions as $item): ?><div><b><?= h($item['type']) ?></b><span><?= h($item['server_name'] ?: 'systemweit') ?></span><code><?= h($item['payload_json']) ?></code></div><?php endforeach; ?>
                <?php if (!$actions): ?><p class="empty">Keine offenen Aktionen.</p><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="panel wide">
        <div class="section-head"><h2>Domains</h2><form method="post"><input type="hidden" name="_action" value="import_domains"><button>Domains einlesen</button></form></div>
        <div class="table-wrap"><table><thead><tr><th>Domain</th><th>Server</th><th>Benutzer</th><th>Registriert</th><th>Naechste Abrechnung</th><th>Anbieter</th><th>Loeschung</th><th>Subdomains</th><th></th></tr></thead><tbody>
                <?php foreach ($domains as $domain): ?>
            <tr class="domain-row <?= h($domainRowClass($domain)) ?>" data-domain-id="<?= (int)$domain['id'] ?>">
                <td><?= h($domain['domain']) ?></td>
                <td><?= h($domain['server_name']) ?></td>
                <td><?= h($domain['owner_name'] ?: ($domain['owner_external_id'] ? 'User #' . $domain['owner_external_id'] : '')) ?></td>
                <td><?php if ($domain['registered_at']): ?><span class="readonly-value" data-field="registered_at"><?= h($domain['registered_at']) ?></span><?php else: ?><input type="date" name="registered_at" value=""><?php endif; ?></td>
                <td><?php if ($domain['next_billing_at']): ?><span class="readonly-value" data-field="next_billing_at"><?= h($domain['next_billing_at']) ?></span><?php else: ?><input type="date" name="next_billing_at" value=""><?php endif; ?></td>
                <td><input name="registrar" value="<?= h($domain['registrar']) ?>"></td>
                <td><?= $domainStatusHtml($domain) ?></td>
                <td><button type="button" class="subdomain-toggle" data-server-id="<?= (int)$domain['server_id'] ?>" data-domain="<?= h($domain['domain']) ?>">anzeigen</button></td>
                <td><button type="button" class="domain-save">Speichern</button></td>
            </tr>
            <tr class="subdomain-row" id="subdomains-<?= (int)$domain['id'] ?>" hidden><td colspan="9"><div class="subdomain-box">Wird geladen...</div></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
</main>
<script>
const dirtyDomainFields = new Set();

function initDomainDirtyTracking(scope = document) {
    scope.querySelectorAll('.domain-row input[name="registered_at"], .domain-row input[name="next_billing_at"], .domain-row input[name="registrar"]').forEach((field) => {
        field.dataset.savedValue = field.value;
        updateDirtyField(field);
    });
}

function updateDirtyField(field) {
    const key = field.closest('.domain-row').dataset.domainId + ':' + field.name;
    const isDirty = field.value !== (field.dataset.savedValue ?? '');
    field.classList.toggle('field-dirty', isDirty);
    if (isDirty) {
        dirtyDomainFields.add(key);
    } else {
        dirtyDomainFields.delete(key);
    }
}

function updateDateCell(row, fieldName, value) {
    const input = row.querySelector('[name="' + fieldName + '"]');
    if (!input || value === '') {
        if (input) {
            input.value = value;
        }
        return;
    }
    const span = document.createElement('span');
    span.className = 'readonly-value';
    span.dataset.field = fieldName;
    span.textContent = value;
    input.replaceWith(span);
}
function markDomainRowSaved(row) {
    row.querySelectorAll('input[name="registered_at"], input[name="next_billing_at"], input[name="registrar"]').forEach((field) => {
        field.dataset.savedValue = field.value;
        updateDirtyField(field);
    });
}

initDomainDirtyTracking();

document.addEventListener('input', (event) => {
    if (event.target.matches('.domain-row input[name="registered_at"], .domain-row input[name="next_billing_at"], .domain-row input[name="registrar"]')) {
        updateDirtyField(event.target);
    }
});

document.addEventListener('change', (event) => {
    if (event.target.matches('.domain-row input[name="registered_at"], .domain-row input[name="next_billing_at"], .domain-row input[name="registrar"]')) {
        updateDirtyField(event.target);
    }
});

window.addEventListener('beforeunload', (event) => {
    if (dirtyDomainFields.size === 0) {
        return;
    }
    event.preventDefault();
    event.returnValue = 'Es gibt ungespeicherte Domain-Aenderungen.';
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('.server-edit-toggle');
    if (!button) {
        return;
    }
    const item = button.closest('.server-item');
    item.querySelector('.server-summary').hidden = true;
    item.querySelector('.server-editor').hidden = false;
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('.ajax-server-form');
    if (!form) {
        return;
    }
    event.preventDefault();
    const button = form.querySelector('button');
    button.disabled = true;
    try {
        const data = await postAjax(new FormData(form));
        const server = data.server;
        const item = form.closest('.server-item');
        const summary = item.querySelector('.server-summary');
        summary.querySelector('.server-name').textContent = server.name;
        summary.querySelector('.server-url').textContent = server.base_url;
        summary.querySelector('.server-key-preview').textContent = server.api_key_preview;
        const status = summary.querySelector('.status');
        status.textContent = server.active ? 'aktiv' : 'inaktiv';
        status.className = 'status ' + (server.active ? 'on' : 'off');
        form.querySelector('[name="api_token"]').value = '';
        form.hidden = true;
        summary.hidden = false;
        showToast(data.message || 'Server gespeichert.');
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('.domain-save');
    if (!button) {
        return;
    }
    const row = button.closest('.domain-row');
    const body = new FormData();
    body.set('_ajax', '1');
    body.set('_action', 'update_domain');
    body.set('id', row.dataset.domainId);
    body.set('registered_at', row.querySelector('[name="registered_at"]')?.value || row.querySelector('[data-field="registered_at"]')?.textContent.trim() || '');
    body.set('next_billing_at', row.querySelector('[name="next_billing_at"]')?.value || row.querySelector('[data-field="next_billing_at"]')?.textContent.trim() || '');
    body.set('registrar', row.querySelector('[name="registrar"]').value);
    button.disabled = true;
    try {
        const data = await postAjax(body);
        updateDateCell(row, 'registered_at', data.domain.registered_at || '');
        updateDateCell(row, 'next_billing_at', data.domain.next_billing_at || '');
        row.querySelector('[name="registrar"]').value = data.domain.registrar || '';
        markDomainRowSaved(row);
        row.classList.add('row-saved');
        setTimeout(() => row.classList.remove('row-saved'), 900);
        showToast(data.message || 'Domain gespeichert.');
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('.subdomain-toggle');
    if (!button) {
        return;
    }
    const row = button.closest('.domain-row');
    const target = document.getElementById('subdomains-' + row.dataset.domainId);
    const box = target.querySelector('.subdomain-box');
    target.hidden = !target.hidden;
    if (target.hidden || button.dataset.loaded === '1') {
        return;
    }
    button.disabled = true;
    box.textContent = 'Wird geladen...';
    try {
        const params = new URLSearchParams({ ajax: 'subdomains', server_id: button.dataset.serverId, domain: button.dataset.domain });
        const response = await fetch('?' + params.toString(), { headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'Subdomains konnten nicht geladen werden.');
        }
        button.dataset.loaded = '1';
        box.innerHTML = data.subdomains.length
            ? '<ul>' + data.subdomains.map((item) => '<li><b>' + escapeHtml(item.domain) + '</b><span>' + escapeHtml(item.owner || '') + '</span></li>').join('') + '</ul>'
            : '<p>Keine Subdomains gefunden.</p>';
    } catch (error) {
        box.innerHTML = '<p class="error-text">' + escapeHtml(error.message) + '</p>';
    } finally {
        button.disabled = false;
    }
});

async function postAjax(body) {
    body.set('_ajax', '1');
    const response = await fetch('', { method: 'POST', body, headers: { Accept: 'application/json' } });
    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'Speichern fehlgeschlagen.');
    }
    return data;
}

function showToast(message, type = 'ok') {
    let stack = document.querySelector('.toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'toast-stack';
        document.body.appendChild(stack);
    }
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    stack.appendChild(toast);
    setTimeout(() => toast.classList.add('visible'), 20);
    setTimeout(() => {
        toast.classList.remove('visible');
        setTimeout(() => toast.remove(), 180);
    }, 2800);
}

function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));
}
</script>
</body>
</html>
