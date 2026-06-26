const translations = window.KH_I18N || {};
function tr(key, fallback = key) {
    return translations[key] || fallback;
}

const dirtyDomainFields = new Set();
const serverRefreshIntervalSeconds = Math.max(5, parseInt(document.body.dataset.serverRefreshInterval || '60', 10) || 60);

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
    const existing = row.querySelector('[data-field="' + fieldName + '"]');
    if (existing) {
        if (value === '') {
            const input = document.createElement('input');
            input.type = 'date';
            input.name = fieldName;
            input.value = '';
            existing.replaceWith(input);
            initDomainDirtyTracking(row);
            return;
        }
        existing.textContent = value;
        return;
    }

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

function clearDomainDirty(row) {
    const prefix = row.dataset.domainId + ':';
    for (const key of Array.from(dirtyDomainFields)) {
        if (key.startsWith(prefix)) {
            dirtyDomainFields.delete(key);
        }
    }
}

function markDomainRowSaved(row) {
    row.querySelectorAll('input[name="registered_at"], input[name="next_billing_at"], input[name="registrar"]').forEach((field) => {
        field.dataset.savedValue = field.value;
        updateDirtyField(field);
    });
}

function applyDomainData(row, domain, rowClass = '', statusHtml = '') {
    row.querySelector('td:nth-child(3)').textContent = domain.owner_name || (domain.owner_external_id ? 'User #' + domain.owner_external_id : '');
    updateDateCell(row, 'registered_at', domain.registered_at || '');
    updateDateCell(row, 'next_billing_at', domain.next_billing_at || '');
    row.querySelector('[name="registrar"]').value = domain.registrar || '';
    row.querySelector('.domain-status-cell').innerHTML = statusHtml || '';
    row.className = 'domain-row ' + (rowClass || '');
    row.dataset.domainName = domain.domain || row.dataset.domainName;
    markDomainRowSaved(row);
}

function updateDuplicateClasses(domainName) {
    const rows = Array.from(document.querySelectorAll('.domain-row')).filter((row) => row.dataset.domainName === domainName);
    if (rows.length <= 1) {
        rows.forEach((row) => row.classList.remove('domain-duplicate'));
    }
}

initDomainDirtyTracking();

const refreshIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-clockwise" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z"/><path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466"/></svg>';

function serverHostnameHtml(status) {
    const label = status.hostname || status.server_name || tr('common.unknown', 'Unknown');
    if (!status.dashboard_url) {
        return '<span class="server-status-hostname">' + escapeHtml(label) + '</span>';
    }
    return '<a class="server-status-hostname server-dashboard-link" href="' + escapeHtml(status.dashboard_url) + '" target="' + escapeHtml(label) + '">' + escapeHtml(label) + '</a>';
}

function updateServerHostname(card, status) {
    const current = card.querySelector('.server-status-hostname');
    if (current) {
        current.outerHTML = serverHostnameHtml(status);
    }
}
function serverCardHtml(status) {
    const hiddenError = status.error ? '' : ' hidden';
    const hiddenFacts = status.error ? ' hidden' : '';
    const hiddenReboot = status.reboot_required && !status.error ? '' : ' hidden';
    return '<article class="server-card ' + (status.reboot_required ? 'needs-reboot' : '') + '" data-server-id="' + escapeHtml(status.server_id) + '">'
        + '<header><h2>' + serverHostnameHtml(status) + '</h2>'
        + '<button type="button" class="server-status-refresh" title="' + escapeHtml(tr('js.refresh_server', 'Refresh server information')) + '" aria-label="' + escapeHtml(tr('js.refresh_server', 'Refresh server information')) + '">' + refreshIconSvg + '<span class="refresh-countdown" data-refresh-countdown></span></button></header>'
        + '<p class="error-text server-status-error"' + hiddenError + '>' + escapeHtml(status.error || '') + '</p>'
        + '<dl class="server-facts"' + hiddenFacts + '>'
        + serverFactHtml(tr('js.os', 'OS:'), 'os', status.os)
        + serverFactHtml(tr('js.kernel', 'Kernel:'), 'kernel', status.kernel)
        + serverFactHtml(tr('js.control_panel', 'Control Panel:'), 'panel', status.panel)
        + serverFactHtml(tr('js.cpu', 'CPU:'), 'cpu', status.cpu)
        + serverFactHtml(tr('js.uptime', 'Uptime:'), 'uptime', status.uptime)
        + serverFactHtml(tr('js.traffic_current_month', 'Traffic this month:'), 'traffic', status.traffic)
        + serverFactHtml(tr('js.consumed_disk', 'Consumed disk space:'), 'disk', status.disk)
        + '</dl><p class="reboot-text"' + hiddenReboot + '>' + escapeHtml(tr('js.reboot_required', 'Reboot required')) + '</p></article>';
}

function serverFactHtml(label, field, value) {
    return '<div><dt>' + escapeHtml(label) + '</dt><dd data-status-field="' + escapeHtml(field) + '">' + escapeHtml(value || '-') + '</dd></div>';
}

async function loadDashboard() {
    const grid = document.querySelector('[data-dashboard-grid]');
    if (!grid) {
        return;
    }
    const body = new FormData();
    body.set('_ajax', '1');
    body.set('_action', 'load_dashboard');
    try {
        const data = await postAjax(body);
        grid.innerHTML = data.statuses.length ? data.statuses.map(serverCardHtml).join('') : '<p class="empty">' + escapeHtml(tr('js.no_active_servers', 'No active servers available.')) + '</p>';
        grid.querySelectorAll('.server-card[data-server-id]').forEach((card) => scheduleServerStatusRefresh(card));
    } catch (error) {
        grid.innerHTML = '<p class="error-text">' + escapeHtml(error.message) + '</p>';
    }
}

async function loadUsers() {
    const loader = document.querySelector('[data-users-loader]');
    const result = document.querySelector('[data-users-result]');
    if (!loader || !result) {
        return;
    }
    const body = new FormData();
    body.set('_ajax', '1');
    body.set('_action', 'load_users');
    try {
        const data = await postAjax(body);
        result.innerHTML = data.groups.length ? data.groups.map(userGroupHtml).join('') : '<p class="empty">' + escapeHtml(tr('js.no_active_servers', 'No active servers available.')) + '</p>';
        result.hidden = false;
        loader.hidden = true;
    } catch (error) {
        loader.hidden = true;
        result.innerHTML = '<p class="error-text">' + escapeHtml(error.message) + '</p>';
        result.hidden = false;
    }
}

function userGroupHtml(group) {
    const title = '<h3>' + escapeHtml(group.server.name || tr('domains.server', 'Server')) + '</h3>';
    if (group.error) {
        return '<section class="server-user-group">' + title + '<p class="error-text">' + escapeHtml(group.error) + '</p></section>';
    }
    const rows = group.users.length
        ? group.users.map((user) => '<tr><td>' + escapeHtml(user.name) + '</td><td>' + escapeHtml(user.email || '') + '</td><td>' + escapeHtml(user.id || '') + '</td></tr>').join('')
        : '<tr><td colspan="3" class="empty">' + escapeHtml(tr('js.no_users_found', 'No users found.')) + '</td></tr>';
    return '<section class="server-user-group">' + title + '<div class="table-wrap"><table class="compact-table"><thead><tr><th>' + escapeHtml(tr('js.user', 'User')) + '</th><th>' + escapeHtml(tr('js.email', 'Email')) + '</th><th>' + escapeHtml(tr('js.id', 'ID')) + '</th></tr></thead><tbody>' + rows + '</tbody></table></div></section>';
}

if (document.body.dataset.page === 'dashboard') {
    loadDashboard();
}
if (document.body.dataset.page === 'users') {
    loadUsers();
}
const serverStatusTimers = new Map();

function applyServerStatus(card, status) {
    updateServerHostname(card, status);
    card.classList.toggle('needs-reboot', Boolean(status.reboot_required));
    const error = card.querySelector('.server-status-error');
    const facts = card.querySelector('.server-facts');
    const reboot = card.querySelector('.reboot-text');
    error.textContent = status.error || '';
    error.hidden = !status.error;
    facts.hidden = Boolean(status.error);
    reboot.hidden = !status.reboot_required || Boolean(status.error);
    ['os', 'kernel', 'panel', 'cpu', 'uptime', 'traffic', 'disk'].forEach((field) => {
        const target = card.querySelector('[data-status-field="' + field + '"]');
        if (target) {
            target.textContent = status[field] || '-';
        }
    });
}

function scheduleServerStatusRefresh(card) {
    const oldState = serverStatusTimers.get(card);
    if (oldState) {
        clearTimeout(oldState.timeout);
        clearInterval(oldState.interval);
    }
    let remaining = serverRefreshIntervalSeconds;
    updateServerRefreshCountdown(card, remaining);
    const interval = setInterval(() => {
        remaining = Math.max(0, remaining - 1);
        updateServerRefreshCountdown(card, remaining);
    }, 1000);
    const timeout = setTimeout(() => refreshServerStatus(card, false), serverRefreshIntervalSeconds * 1000);
    serverStatusTimers.set(card, { timeout, interval });
}

function updateServerRefreshCountdown(card, remaining) {
    const target = card.querySelector('[data-refresh-countdown]');
    if (target) {
        target.textContent = String(remaining);
    }
}

async function refreshServerStatus(card, manual) {
    const button = card.querySelector('.server-status-refresh');
    const body = new FormData();
    body.set('_ajax', '1');
    body.set('_action', 'refresh_server_status');
    body.set('id', card.dataset.serverId);
    button.disabled = true;
    button.classList.add('loading');
    try {
        const data = await postAjax(body);
        applyServerStatus(card, data.status);
        if (manual) {
            showToast(data.message || '[SERVER] ' + tr('js.domain_updated', 'Status updated.'));
        }
    } catch (error) {
        applyServerStatus(card, {
            hostname: card.querySelector('.server-status-hostname')?.textContent || tr('common.unknown', 'Unknown'),
            dashboard_url: card.querySelector('.server-dashboard-link')?.href || '',
            error: error.message,
            reboot_required: false,
        });
        if (manual) {
            showToast(error.message, 'error');
        }
    } finally {
        button.disabled = false;
        button.classList.remove('loading');
        scheduleServerStatusRefresh(card);
    }
}

document.querySelectorAll('.server-card[data-server-id]').forEach((card) => scheduleServerStatusRefresh(card));

document.addEventListener('click', (event) => {
    const button = event.target.closest('.server-status-refresh');
    if (!button) {
        return;
    }
    refreshServerStatus(button.closest('.server-card'), true);
});

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
    event.returnValue = tr('js.unsaved_domain_changes', 'There are unsaved domain changes.');
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
        status.textContent = server.active ? tr('server.active', 'active') : tr('server.inactive', 'inactive');
        status.className = 'status ' + (server.active ? 'on' : 'off');
        form.querySelector('[name="api_token"]').value = '';
        form.hidden = true;
        summary.hidden = false;
        showToast(data.message || tr('js.server_saved', 'Server saved.'));
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
        applyDomainData(row, data.domain, data.row_class, data.status_html);
        row.classList.add('row-saved');
        setTimeout(() => row.classList.remove('row-saved'), 900);
        showToast(data.message || tr('js.domain_saved', 'Domain saved.'));
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('.domain-refresh');
    if (!button) {
        return;
    }
    const row = button.closest('.domain-row');
    const domainId = row.dataset.domainId;
    const domainName = row.dataset.domainName;
    const body = new FormData();
    body.set('_ajax', '1');
    body.set('_action', 'refresh_domain');
    body.set('id', domainId);
    button.disabled = true;
    button.classList.add('loading');
    try {
        const data = await postAjax(body);
        if (data.status === 'deleted') {
            clearDomainDirty(row);
            document.getElementById('subdomains-' + domainId)?.remove();
            row.remove();
            updateDuplicateClasses(domainName);
            showToast(data.message || tr('js.domain_deleted', 'Domain deleted locally.'));
            return;
        }
        applyDomainData(row, data.domain, data.row_class, data.status_html);
        row.classList.add('row-saved');
        setTimeout(() => row.classList.remove('row-saved'), 900);
        showToast(data.message || tr('js.domain_updated', 'Domain updated.'));
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
        button.classList.remove('loading');
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
    box.textContent = tr('common.loading', 'Loading...');
    try {
        const params = new URLSearchParams({ ajax: 'subdomains', server_id: button.dataset.serverId, domain: button.dataset.domain });
        const response = await fetch('?' + params.toString(), { headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || tr('js.subdomains_failed', 'Subdomains could not be loaded.'));
        }
        button.dataset.loaded = '1';
        box.innerHTML = data.subdomains.length
            ? '<ul>' + data.subdomains.map((item) => '<li><b>' + escapeHtml(item.domain) + '</b><span>' + escapeHtml(item.owner || '') + '</span></li>').join('') + '</ul>'
            : '<p>' + escapeHtml(tr('js.no_subdomains', 'No subdomains found.')) + '</p>';
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
        throw new Error(data.message || tr('js.save_failed', 'Save failed.'));
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
