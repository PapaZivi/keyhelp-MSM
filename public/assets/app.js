function applyThemeMode() {
    const root = document.documentElement;
    const mode = root.dataset.themeMode || 'light';
    const resolved = mode === 'auto' && window.matchMedia
        ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : (mode === 'dark' ? 'dark' : 'light');
    root.setAttribute('data-bs-theme', resolved);
}

applyThemeMode();
if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyThemeMode);
}
const translations = window.KH_I18N || {};
function tr(key, fallback = key) {
    return translations[key] || fallback;
}

const formatSettings = window.KH_FORMATS || {};
const browserLocale = navigator.language || document.documentElement.lang || 'en-US';
const appLocale = formatSettings.locale && formatSettings.locale !== 'auto'
    ? formatSettings.locale
    : browserLocale;
const serverRefreshIntervalRaw = document.body.getAttribute('data-server-refresh-interval');
const configuredServerRefreshInterval = serverRefreshIntervalRaw === null ? 60 : parseInt(serverRefreshIntervalRaw, 10);
const serverRefreshIntervalSeconds = Number.isFinite(configuredServerRefreshInterval) && configuredServerRefreshInterval >= 0 ? configuredServerRefreshInterval : 60;
const serverStatusCachePrefix = 'khmsm:server-status:';
const serverStatusCacheVersion = 4;
const serverStatusCacheTtlSeconds = serverRefreshIntervalSeconds === 0 ? 300 : serverRefreshIntervalSeconds;
let usernameCheckTimer = null;

const invoiceTemplatePreviewData = {
    logo_src: '/assets/khmsm_fulllogo_512.png',
    'sender.name': 'Musterfirma GmbH',
    'sender.full': 'Musterfirma GmbH<br>Rechnungsweg 1<br>12345 Musterstadt',
    'recipient.address_html': 'Beispiel GmbH<br>Erika Mustermann<br>Musterstraße 12<br>12345 Musterstadt<br>DE',
    'invoice.number': '20260804-0001',
    'invoice.date': '04.08.2026',
    'invoice.period': '01.08.2026 - 04.08.2026',
    'invoice.status': 'Vorschau',
    'invoice.subtotal': '100,00 EUR',
    'invoice.user_discount_percent': '100 %',
    'invoice.user_discount_total': '-100,00 EUR',
    'invoice.user_discount_row': '<tr><td>Gesamtrabatt (100 %)</td><td class="right">-100,00 EUR</td></tr>',
    'invoice.tax_total': '0,00 EUR',
    'invoice.total': '0,00 EUR',
    'customer.number': 'kunde_001',
    'customer.email': 'kunde@example.com',
    'customer.server': 'Server01',
};
const invoiceTemplatePreviewItems = [
    {
        'item.description': 'Hostingpaket Business',
        'item.description2': '',
        'item.quantity': '1',
        'item.unit_price': '79,00 EUR',
        'item.discount_percent': '',
        'item.net_total': '79,00 EUR',
        'item.tax_total': '15,01 EUR',
        'item.gross_total': '94,01 EUR',
        'item.service_date': '04.08.2026',
        'item.reference': 'sample-hosting',
    },
    {
        'item.description': 'Domain beispiel.de',
        'item.description2': '(02.09.2020 - 01.10.2020)',
        'item.quantity': '1',
        'item.unit_price': '21,00 EUR',
        'item.discount_percent': '',
        'item.net_total': '21,00 EUR',
        'item.tax_total': '3,99 EUR',
        'item.gross_total': '24,99 EUR',
        'item.service_date': '04.08.2026',
        'item.reference': 'sample-domain',
    },
];

function initStatusTooltips(scope = document) {
    if (!window.bootstrap?.Tooltip) {
        return;
    }
    scope.querySelectorAll('.status-tooltip[data-bs-toggle="tooltip"]').forEach((element) => {
        element.removeAttribute('title');
        window.bootstrap.Tooltip.getOrCreateInstance(element, {
            container: 'body',
            placement: 'top',
            trigger: 'hover',
        });
    });
}
function initHelpPopovers(scope = document) {
    if (!window.bootstrap?.Popover) {
        return;
    }
    scope.querySelectorAll('[data-bs-toggle="popover"]').forEach((element) => {
        window.bootstrap.Popover.getOrCreateInstance(element, {
            container: 'body',
            html: false,
            trigger: element.dataset.bsTrigger || 'focus',
        });
    });
}

function hideOtherHelpPopovers(current) {
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach((element) => {
        if (element !== current) {
            window.bootstrap?.Popover?.getInstance(element)?.hide();
        }
    });
}

function formDataWithDisabledFields(form) {
    const body = new FormData(form);
    form.querySelectorAll(':disabled[name]').forEach((field) => {
        if (field.matches('[data-api-readonly-control]')) {
            return;
        }
        if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
            return;
        }
        body.set(field.name, field.value);
    });
    return body;
}

function formatDisplayDate(value) {
    if (!value) {
        return '';
    }
    const date = new Date(String(value).slice(0, 10) + 'T00:00:00');
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }
    if (formatSettings.date_format === 'dmy') {
        return String(date.getDate()).padStart(2, '0') + '.'
            + String(date.getMonth() + 1).padStart(2, '0') + '.'
            + date.getFullYear();
    }
    if (formatSettings.date_format === 'mdy') {
        return String(date.getMonth() + 1).padStart(2, '0') + '/'
            + String(date.getDate()).padStart(2, '0') + '/'
            + date.getFullYear();
    }
    if (formatSettings.date_format === 'ymd') {
        return date.getFullYear() + '-'
            + String(date.getMonth() + 1).padStart(2, '0') + '-'
            + String(date.getDate()).padStart(2, '0');
    }
    return new Intl.DateTimeFormat(appLocale).format(date);
}

function formatDecimal(value, decimals = 2) {
    const number = Number(String(value || '0').replace(',', '.'));
    if (!Number.isFinite(number)) {
        return String(value || '');
    }
    const formatted = new Intl.NumberFormat(appLocale, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(number);
    if (formatSettings.decimal_separator === 'comma') {
        return formatted.replace(/[.,]/g, (separator) => separator === '.' ? ',' : '.');
    }
    if (formatSettings.decimal_separator === 'dot') {
        return formatted.replace(/[.,]/g, (separator) => separator === ',' ? '.' : ',');
    }
    return formatted;
}

function formatMoney(value) {
    const currency = /^[A-Z]{3}$/.test(String(formatSettings.currency || ''))
        ? formatSettings.currency
        : 'EUR';
    return formatDecimal(value, 2) + ' ' + currency;
}

function formatDisplayTime(date) {
    const options = {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    };
    if (formatSettings.time_format === '12') {
        options.hour12 = true;
    }
    if (formatSettings.time_format === '24') {
        options.hour12 = false;
    }
    return new Intl.DateTimeFormat(appLocale, options).format(date);
}

function billingFrequencyLabel(value) {
    const frequency = value || 'yearly';
    const key = frequency === 'halfyearly' ? 'semiannual' : frequency;
    return tr('billing.frequency_' + key, frequency);
}

function billingItemStatusLabel(item) {
    return String(item.active || '') === '1'
        ? tr('server.active', 'active')
        : tr('common.inactive', 'inactive');
}

function updateDomainCell(row, fieldName, value) {
    const cell = row.querySelector('[data-domain-cell="' + fieldName + '"]');
    if (!cell) {
        return;
    }
    if (fieldName === 'registered_at' || fieldName === 'next_billing_at') {
        cell.textContent = formatDisplayDate(value);
        return;
    }
    if (fieldName === 'billing_frequency') {
        cell.textContent = billingFrequencyLabel(value);
        return;
    }
    cell.textContent = value || '';
}

function domainIsLocked(domain) {
    return Boolean(domain?.is_disabled);
}

function updateDomainNameStatus(row, domain) {
    const container = row.querySelector('.name-with-status');
    if (!container) {
        return;
    }
    container.querySelector('.status-lock-marker')?.remove();
    if (domainIsLocked(domain)) {
        const marker = document.createElement('span');
        marker.className = 'status-lock-marker';
        marker.textContent = String.fromCodePoint(0x1F6C7);
        const title = tr('domains.locked_or_disabled', 'Locked or disabled');
        marker.classList.add('status-tooltip');
        marker.setAttribute('data-bs-toggle', 'tooltip');
        marker.setAttribute('data-bs-placement', 'top');
        marker.setAttribute('data-bs-trigger', 'hover');
        marker.setAttribute('data-bs-title', title);
        marker.setAttribute('aria-label', title);
        container.append(marker);
        initStatusTooltips(container);
    }
}
function applyDomainData(row, domain, rowClass = '', statusHtml = '') {
    row.querySelector('td:nth-child(3)').textContent = domain.owner_name || (domain.owner_external_id ? 'User #' + domain.owner_external_id : '');
    updateDomainCell(row, 'registered_at', domain.registered_at || '');
    updateDomainCell(row, 'next_billing_at', domain.next_billing_at || '');
    updateDomainCell(row, 'billing_frequency', domain.billing_frequency || 'yearly');
    updateDomainCell(row, 'registrar', domain.registrar || '');
    row.querySelector('.domain-status-cell').innerHTML = statusHtml || '';
    initStatusTooltips(row);
    updateDomainNameStatus(row, domain);
    row.className = 'domain-row ' + (rowClass || '');
    row.dataset.domainName = domain.domain || row.dataset.domainName;
}

function updateDuplicateClasses(domainName) {
    const rows = Array.from(document.querySelectorAll('.domain-row')).filter((row) => row.dataset.domainName === domainName);
    if (rows.length <= 1) {
        rows.forEach((row) => row.classList.remove('domain-duplicate'));
    }
}

function setDomainImportBusy(area, busy) {
    if (!area) {
        return;
    }
    const content = area.querySelector('[data-domain-content]');
    const overlay = area.querySelector('[data-domain-import-overlay]');
    area.setAttribute('aria-busy', busy ? 'true' : 'false');
    if (content) {
        content.classList.toggle('is-busy', busy);
        if (busy) {
            content.setAttribute('inert', '');
        } else {
            content.removeAttribute('inert');
        }
    }
    if (overlay) {
        overlay.hidden = !busy;
        const text = overlay.querySelector('span:last-child');
        if (text) {
            text.textContent = tr('js.domains_importing', 'Domains are being imported...');
        }
    }
}

function setUserImportBusy(area, busy) {
    if (!area) {
        return;
    }
    const content = area.querySelector('[data-users-content]');
    const overlay = area.querySelector('[data-user-import-overlay]');
    area.setAttribute('aria-busy', busy ? 'true' : 'false');
    if (content) {
        content.classList.toggle('is-busy', busy);
        if (busy) {
            content.setAttribute('inert', '');
        } else {
            content.removeAttribute('inert');
        }
    }
    if (overlay) {
        overlay.hidden = !busy;
        const text = overlay.querySelector('span:last-child');
        if (text) {
            text.textContent = tr('js.users_importing', 'Users are being imported...');
        }
    }
}

function setUsernameStatus(form, message, state = '') {
    const status = form.querySelector('[data-username-status]');
    const input = form.querySelector('[data-username-input]');
    if (status) {
        status.textContent = message || '';
        status.classList.toggle('text-success', state === 'ok');
        status.classList.toggle('text-danger', state === 'error');
        status.classList.toggle('text-secondary', state === 'pending');
    }
    if (input) {
        input.classList.toggle('is-valid', state === 'ok');
        input.classList.toggle('is-invalid', state === 'error');
        input.dataset.usernameAvailable = state === 'ok' ? '1' : (state === 'error' ? '0' : '');
    }
}

function generatePassword(length = 20) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!#$%&*+-=?@';
    const values = new Uint32Array(length);
    crypto.getRandomValues(values);
    return Array.from(values, (value) => chars[value % chars.length]).join('');
}

function readNestedValue(data, keys) {
    for (const key of keys) {
        if (Object.prototype.hasOwnProperty.call(data, key) && data[key] !== null && data[key] !== '') {
            return data[key];
        }
    }
    for (const value of Object.values(data)) {
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            const nested = readNestedValue(value, keys);
            if (nested !== undefined && nested !== null && nested !== '') {
                return nested;
            }
        }
    }
    return undefined;
}

function applyResourceUnlimited(checkbox) {
    const field = checkbox.dataset.resourceUnlimited;
    const form = checkbox.closest('form');
    const input = form?.querySelector('[data-resource-field="' + field + '"]');
    const unit = form?.querySelector('[data-resource-unit="' + field + '"]');
    const readonly = checkbox.matches('[data-api-readonly-control]') || Boolean(checkbox.closest('[data-api-readonly]'));
    if (checkbox.checked && input) {
        input.value = '0';
    }
    if (readonly) {
        checkbox.disabled = true;
        if (input) {
            input.disabled = true;
        }
        if (unit) {
            unit.disabled = true;
        }
        return;
    }
    if (!checkbox.closest('[data-resource-row]')?.classList.contains('is-plan-locked')) {
        if (input) {
            input.disabled = checkbox.checked;
        }
        if (unit) {
            unit.disabled = checkbox.checked;
        }
    }
}

function hostingPlanDataFromSelect(select) {
    try {
        return JSON.parse(select?.selectedOptions[0]?.dataset.limits || '{}') || {};
    } catch (error) {
        return {};
    }
}

function renderTemplatePreview(template) {
    let html = String(template || '');
    html = html.replace(/\{\{#items\}\}([\s\S]*?)\{\{\/items\}\}/g, (match, block) => invoiceTemplatePreviewItems.map((item) => replaceTemplateVariables(block, item)).join(''));
    return replaceTemplateVariables(html, invoiceTemplatePreviewData);
}

function replaceTemplateVariables(template, variables) {
    return String(template || '').replace(/\{\{([a-zA-Z0-9_.]+)\}\}/g, (match, key) => variables[key] ?? '');
}

function updateTemplatePreview(editor, preview) {
    if (!editor || !preview) {
        return;
    }
    preview.srcdoc = renderTemplatePreview(editor.value);
}

function initInvoiceTemplatePreviews(scope = document) {
    [
        ['[data-invoice-template-editor]', '[data-invoice-template-preview]'],
        ['[data-dunning-template-editor]', '[data-dunning-template-preview]'],
    ].forEach(([editorSelector, previewSelector]) => {
        const editor = scope.querySelector(editorSelector);
        const preview = scope.querySelector(previewSelector);
        if (!editor || !preview) {
            return;
        }
        updateTemplatePreview(editor, preview);
        editor.addEventListener('input', () => updateTemplatePreview(editor, preview));
    });
}

function initBackbillDomainForm(scope = document) {
    const form = scope.querySelector('.billing-backbill-form');
    if (!form) {
        return;
    }
    const userSelect = form.querySelector('[data-backbill-user]');
    const domainSelect = form.querySelector('[data-backbill-domains]');
    const priceSource = form.querySelector('[data-backbill-price-source]');
    const manualPrice = form.querySelector('[data-backbill-manual-price]');

    const updateDomains = () => {
        const ownerKey = userSelect?.selectedOptions[0]?.dataset.ownerKey || '';
        Array.from(domainSelect?.options || []).forEach((option) => {
            const visible = !ownerKey || option.dataset.ownerKey === ownerKey;
            option.hidden = !visible;
            if (!visible) {
                option.selected = false;
            }
        });
    };
    const updatePriceSource = () => {
        if (!manualPrice || !priceSource) {
            return;
        }
        manualPrice.disabled = priceSource.value !== 'manual';
        if (manualPrice.disabled) {
            manualPrice.value = '';
        }
    };

    userSelect?.addEventListener('change', updateDomains);
    priceSource?.addEventListener('change', updatePriceSource);
    updateDomains();
    updatePriceSource();
}

function applyPlanResourceValues(form, plan) {
    const aliases = {
        disk_space: ['disk_space', 'diskSpace', 'webspace', 'storage', 'quota_disk_space'],
        traffic: ['traffic', 'traffic_limit', 'quota_traffic'],
        domains: ['domains', 'domain_count', 'max_domains'],
        subdomains: ['subdomains', 'subdomain_count', 'max_subdomains'],
        email_accounts: ['email_accounts', 'mailboxes', 'emailAccounts', 'mail_accounts'],
        email_addresses: ['email_addresses', 'emailAddresses', 'mail_addresses'],
        email_forwarders: ['email_forwardings', 'email_forwarders', 'emailForwarders', 'mail_forwarders'],
        databases: ['databases', 'database_count', 'mysql_databases'],
        ftp_users: ['ftp_users', 'ftpUsers', 'ftp_accounts'],
        scheduled_tasks: ['scheduled_tasks', 'scheduledTasks', 'cronjobs'],
    };
    form.querySelectorAll('[data-resource-field]').forEach((input) => {
        const field = input.dataset.resourceField;
        const value = readNestedValue(plan.resources || plan, aliases[field] || [field]);
        if (value !== undefined) {
            setResourceValue(form, field, value);
        }
    });
}

function applyPlanPermissionValues(form, plan) {
    const permissions = plan.permissions || {};
    const map = {
        ftp: 'ftp',
        php: 'php',
        perl_cgi: 'perl',
        ssh: 'ssh',
        backup: 'backup',
        file_manager: 'file_manager',
        dns_editor: 'dns_editor',
        domain_security: 'domain_security',
        certificate_management: 'certificate_management',
        database_remote_access: 'database_remote_access',
        email_catch_all: 'email_catchall',
        delete_main_domains: 'delete_main_domain',
        panel_access: 'panel_access',
        update_contact_data: 'update_contact_data',
        applications: 'applications',
        restricted_ssh: 'ssh_jail',
    };
    Object.entries(map).forEach(([field, apiField]) => {
        if (Object.prototype.hasOwnProperty.call(permissions, apiField)) {
            setFormValue(form, 'permission_' + field, Boolean(permissions[apiField]));
        }
    });
}

function applyPlanPhpValues(form, plan) {
    const php = plan.php || {};
    const map = {
        memory_limit: 'php_memory_limit',
        max_execution_time: 'php_max_execution_time',
        post_max_size: 'php_post_max_size',
        upload_max_filesize: 'php_upload_max_filesize',
        open_basedir: 'php_open_basedir',
        disable_functions: 'php_disable_functions',
        sendmail_from: 'php_sendmail_from',
        env_variables: 'php_environment_variables',
        extra_directives_immutable: 'php_extra_directives_immutable',
        extra_directives_mutable: 'php_extra_directives_mutable',
    };
    Object.entries(map).forEach(([apiField, formField]) => {
        if (Object.prototype.hasOwnProperty.call(php, apiField)) {
            setFormValue(form, formField, php[apiField]);
        }
    });
}

function applyPlanPhpFpmValues(form, plan) {
    const fpm = plan.php_fpm || {};
    const map = {
        pm: 'php_fpm_pm',
        max_children: 'php_fpm_max_children',
        max_requests: 'php_fpm_max_requests',
        status: 'php_fpm_status_enabled',
        status_ip_restriction: 'php_fpm_status_ips',
    };
    Object.entries(map).forEach(([apiField, formField]) => {
        if (Object.prototype.hasOwnProperty.call(fpm, apiField)) {
            setFormValue(form, formField, fpm[apiField]);
        }
    });
}

function setPlanControlledFieldsDisabled(form, locked) {
    form.querySelectorAll('#user-tab-resources [data-resource-control], #user-tab-permissions input, #user-tab-php input, #user-tab-php textarea, #user-tab-php select, #user-tab-php-fpm input, #user-tab-php-fpm textarea, #user-tab-php-fpm select').forEach((control) => {
        control.disabled = locked;
    });
    form.querySelectorAll('[data-resource-row]').forEach((row) => row.classList.toggle('is-plan-locked', locked));
}

function applyHostingPlanResources(form) {
    const select = form.querySelector('[data-hosting-plan-select]');
    if (!select) {
        return;
    }
    const locked = Boolean(select.value);
    if (locked) {
        const plan = hostingPlanDataFromSelect(select);
        applyPlanResourceValues(form, plan);
        applyPlanPermissionValues(form, plan);
        applyPlanPhpValues(form, plan);
        applyPlanPhpFpmValues(form, plan);
    }
    setPlanControlledFieldsDisabled(form, locked);
    if (!locked) {
        form.querySelectorAll('[data-resource-unlimited]').forEach((checkbox) => applyResourceUnlimited(checkbox));
    }
}

function filterHostingPlansForServer(form, serverId) {
    const select = form.querySelector('[data-hosting-plan-select]');
    if (!select) {
        return;
    }
    select.value = '';
    Array.from(select.options).forEach((option) => {
        const optionServerId = option.dataset.serverId || '';
        option.hidden = option.value !== '' && optionServerId !== '' && optionServerId !== String(serverId);
    });
    applyHostingPlanResources(form);
}

function setFormValue(form, name, value) {
    const field = form.querySelector('[name="' + name + '"]');
    if (!field) {
        return;
    }
    if (field.type === 'checkbox') {
        field.checked = Boolean(value);
        return;
    }
    field.value = value ?? '';
}

function bytesToResourceValue(value) {
    const number = Number(value);
    if (!Number.isFinite(number) || number <= 0) {
        return { value: 0, unit: 'MiB', unlimited: number < 0 };
    }
    const gib = 1024 * 1024 * 1024;
    const mib = 1024 * 1024;
    if (number % gib === 0) {
        return { value: number / gib, unit: 'GiB', unlimited: false };
    }
    return { value: Math.round(number / mib), unit: 'MiB', unlimited: false };
}

function setResourceValue(form, field, rawValue) {
    const input = form.querySelector('[data-resource-field="' + field + '"]');
    const unit = form.querySelector('[data-resource-unit="' + field + '"]');
    const checkbox = form.querySelector('[data-resource-unlimited="' + field + '"]');
    const parsed = field === 'disk_space' || field === 'traffic'
        ? bytesToResourceValue(rawValue)
        : { value: Math.max(0, Number(rawValue) || 0), unit: '', unlimited: Number(rawValue) < 0 };
    if (input) {
        input.value = String(parsed.value);
    }
    if (unit && parsed.unit) {
        unit.value = parsed.unit;
    }
    if (checkbox) {
        checkbox.checked = parsed.unlimited;
        applyResourceUnlimited(checkbox);
    }
}

function applyPermissionResourceLocks(form) {
    if (!form) {
        return;
    }
    if (form.querySelector('[data-hosting-plan-select]')?.value) {
        return;
    }
    form.querySelectorAll('[data-resource-requires-permission]').forEach((row) => {
        const permission = row.dataset.resourceRequiresPermission;
        const allowed = Boolean(form.querySelector('[name="permission_' + permission + '"]')?.checked);
        row.classList.toggle('is-permission-locked', !allowed);
        if (!allowed) {
            row.querySelectorAll('[data-resource-control]').forEach((control) => {
                if (control.matches('[data-resource-field]')) {
                    control.value = '0';
                }
                if (control.matches('[data-resource-unlimited]')) {
                    control.checked = false;
                }
                control.disabled = true;
            });
            return;
        }
        const planLocked = row.classList.contains('is-plan-locked');
        row.querySelectorAll('[data-resource-unlimited]').forEach((checkbox) => {
            checkbox.disabled = planLocked;
            applyResourceUnlimited(checkbox);
        });
    });
}

function applyApiReadonlyControls(scope = document) {
    scope.querySelectorAll('[data-api-readonly-control]').forEach((control) => {
        control.disabled = true;
    });
}


function setUserBillingControls(form, mode) {
    const localId = form.querySelector('[data-user-create-local-id]')?.value || '';
    const billingId = form.querySelector('[data-billing-user-id]');
    if (billingId) {
        billingId.value = localId;
    }
    const allowPastDate = form.querySelector('[data-billing-allow-past-date]');
    if (allowPastDate) {
        allowPastDate.value = '0';
    }
    const disabled = mode !== 'edit';
    form.querySelectorAll('#user-tab-billing input, #user-tab-billing select, #user-tab-billing textarea').forEach((control) => {
        if (control.matches('[data-billing-user-id]')) {
            return;
        }
        control.disabled = disabled;
    });
    const note = form.querySelector('[data-billing-create-note]');
    if (note) {
        note.hidden = !disabled;
    }
    renderUserBillingItems(form, []);
}

function renderUserBillingItems(form, items) {
    const target = form.querySelector('[data-billing-existing-items]');
    if (!target) {
        return;
    }
    const safeItems = Array.isArray(items) ? items : [];
    if (safeItems.length === 0) {
        target.innerHTML = '<p class="billing-muted mb-0">' + escapeHtml(tr('billing.no_user_items', 'No billing items have been added yet.')) + '</p>';
        return;
    }
    const rows = safeItems.map((item) => {
        return '<tr>'
            + '<td>' + escapeHtml(item.description || '') + '</td>'
            + '<td>' + escapeHtml(formatMoney(item.amount || '0')) + '</td>'
            + '<td>' + escapeHtml(formatDisplayDate(item.booking_date || '')) + '</td>'
            + '<td>' + escapeHtml(billingFrequencyLabel(item.frequency || 'once')) + '</td>'
            + '<td>' + escapeHtml(billingItemStatusLabel(item)) + '</td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger billing-item-delete" data-billing-item-id="' + escapeHtml(item.id || '') + '">' + escapeHtml(tr('common.delete', 'Delete')) + '</button></td>'
            + '</tr>';
    }).join('');
    target.innerHTML = '<h3 class="h6 mt-4">' + escapeHtml(tr('billing.existing_user_items', 'Existing billing items')) + '</h3>'
        + '<div class="table-responsive"><table class="table table-sm align-middle mb-0">'
        + '<thead><tr>'
        + '<th>' + escapeHtml(tr('billing.item_description', 'Description')) + '</th>'
        + '<th>' + escapeHtml(tr('billing.net_amount', 'Net amount')) + '</th>'
        + '<th>' + escapeHtml(tr('billing.booking_date', 'Booking date')) + '</th>'
        + '<th>' + escapeHtml(tr('billing.interval', 'Interval')) + '</th>'
        + '<th>' + escapeHtml(tr('common.status', 'Status')) + '</th>'
        + '<th>' + escapeHtml(tr('common.actions', 'Actions')) + '</th>'
        + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function prepareUserForm(form, mode, serverId, serverName) {
    form.reset();
    form.dataset.mode = mode;
    const title = form.closest('.modal')?.querySelector('.modal-title');
    if (title) {
        title.textContent = mode === 'edit'
            ? tr('users.edit_title', 'Edit user')
            : tr('users.create_title', 'Create user');
    }
    form.querySelector('[name="_action"]').value = mode === 'edit' ? 'update_user' : 'create_user';
    form.querySelector('[data-user-create-server-id]').value = serverId || '';
    form.querySelector('[data-user-create-local-id]').value = '';
    const label = form.querySelector('[data-user-create-server-label]');
    if (label) {
        label.textContent = serverName || '';
    }
    const username = form.querySelector('[data-username-input]');
    const suggest = form.querySelector('[data-username-suggest]');
    const password = form.querySelector('[name="password"]');
    const confirmation = form.querySelector('[name="password_confirmation"]');
    if (username) {
        username.disabled = mode === 'edit';
        username.required = mode !== 'edit';
    }
    if (suggest) {
        suggest.disabled = mode === 'edit';
    }
    if (password) {
        password.required = mode !== 'edit';
        password.value = '';
    }
    if (confirmation) {
        confirmation.required = mode !== 'edit';
        confirmation.value = '';
    }
    setUsernameStatus(form, '', mode === 'edit' ? 'ok' : '');
    filterHostingPlansForServer(form, serverId || '');
    form.querySelectorAll('[data-resource-unlimited]').forEach((checkbox) => applyResourceUnlimited(checkbox));
    applyApiReadonlyControls(form);
    applyPermissionResourceLocks(form);
    setUserBillingControls(form, mode);
    form.querySelector('.nav-link[data-bs-target="#user-tab-general"]')?.click();
}

function fillUserForm(form, user) {
    setFormValue(form, 'username', user.username || '');
    setFormValue(form, 'language', user.language || 'de');
    setFormValue(form, 'email', user.email || '');
    setFormValue(form, 'notes', user.notes || '');

    const contact = user.contact_data || {};
    setFormValue(form, 'first_name', contact.first_name || '');
    setFormValue(form, 'last_name', contact.last_name || '');
    setFormValue(form, 'company', contact.company || '');
    setFormValue(form, 'phone', contact.telephone || '');
    setFormValue(form, 'address', contact.address || '');
    setFormValue(form, 'postcode', contact.zip || '');
    setFormValue(form, 'city', contact.city || '');
    setFormValue(form, 'region', contact.state || '');
    setFormValue(form, 'country', contact.country || '');
    setFormValue(form, 'customer_number', contact.client_id || '');

    const limits = user.resource_limits || {};
    const resourceMap = {
        disk_space: 'disk_space',
        traffic: 'traffic',
        domains: 'domains',
        subdomains: 'subdomains',
        email_accounts: 'email_accounts',
        email_addresses: 'email_addresses',
        email_forwarders: 'email_forwardings',
        databases: 'databases',
        ftp_users: 'ftp_users',
        scheduled_tasks: 'scheduled_tasks',
    };
    Object.entries(resourceMap).forEach(([field, apiField]) => setResourceValue(form, field, limits[apiField] ?? 0));

    const permissions = user.permissions || {};
    const permissionMap = {
        ftp: 'ftp',
        php: 'php',
        perl_cgi: 'perl',
        ssh: 'ssh',
        backup: 'backup',
        file_manager: 'file_manager',
        dns_editor: 'dns_editor',
        domain_security: 'domain_security',
        certificate_management: 'certificate_management',
        database_remote_access: 'database_remote_access',
        email_catch_all: 'email_catchall',
        delete_main_domains: 'delete_main_domain',
        panel_access: 'panel_access',
        update_contact_data: 'update_contact_data',
        applications: 'applications',
        restricted_ssh: 'ssh_jail',
    };
    Object.entries(permissionMap).forEach(([field, apiField]) => setFormValue(form, 'permission_' + field, Boolean(permissions[apiField])));

    const php = user.php || {};
    setFormValue(form, 'php_memory_limit', php.memory_limit || '128M');
    setFormValue(form, 'php_max_execution_time', php.max_execution_time || 60);
    setFormValue(form, 'php_post_max_size', php.post_max_size || '72M');
    setFormValue(form, 'php_upload_max_filesize', php.upload_max_filesize || '64M');
    setFormValue(form, 'php_open_basedir', php.open_basedir || '');
    setFormValue(form, 'php_disable_functions', php.disable_functions || '');
    setFormValue(form, 'php_sendmail_from', php.sendmail_from || '');
    setFormValue(form, 'php_environment_variables', php.env_variables || '');
    setFormValue(form, 'php_extra_directives_immutable', php.extra_directives_immutable || '');
    setFormValue(form, 'php_extra_directives_mutable', php.extra_directives_mutable || '');

    const fpm = user.php_fpm || {};
    setFormValue(form, 'php_fpm_pm', fpm.pm || 'ondemand');
    setFormValue(form, 'php_fpm_max_children', fpm.max_children || 3);
    setFormValue(form, 'php_fpm_max_requests', fpm.max_requests || 0);
    setFormValue(form, 'php_fpm_status_enabled', Boolean(fpm.status));
    setFormValue(form, 'php_fpm_status_ips', fpm.status_ip_restriction || '');

    setFormValue(form, 'account_locked', Boolean(user.is_suspended));
    setFormValue(form, 'lock_on', (user.suspend_on || '').slice(0, 10));
    setFormValue(form, 'delete_on', (user.delete_on || '').slice(0, 10));

    const hostingPlanId = user.id_hosting_plan || user.hosting_plan_id || user.hosting_plan?.id || '';
    if (hostingPlanId) {
        setFormValue(form, 'hosting_plan_id', hostingPlanId);
        applyHostingPlanResources(form);
    }
    const billing = user._billing || {};
    setFormValue(form, 'billing_discount_percent', billing.discount_percent ?? 0);
    setFormValue(form, 'billing_invoice_frequency', billing.invoice_frequency || 'monthly');
    renderUserBillingItems(form, user._billing_items || []);
    applyApiReadonlyControls(form);
    applyPermissionResourceLocks(form);
}

function clearUserFormProblem(field) {
    field.classList.remove('is-invalid');
}

function showUserFormProblem(form, field, message) {
    const pane = field.closest('.tab-pane');
    if (pane?.id) {
        form.querySelector('.nav-link[data-bs-target="#' + pane.id + '"]')?.click();
    }
    setTimeout(() => {
        field.classList.add('is-invalid');
        field.scrollIntoView({ block: 'center', behavior: 'smooth' });
        field.focus({ preventScroll: true });
    }, 80);
    showToast(message, 'error');
}

function validateUserFormBasics(form, isEdit) {
    const requiredNames = isEdit ? ['email'] : ['username', 'email', 'password', 'password_confirmation'];
    form.querySelectorAll('.is-invalid').forEach((field) => clearUserFormProblem(field));
    for (const name of requiredNames) {
        const field = form.querySelector('[name="' + name + '"]');
        if (!field || field.disabled) {
            continue;
        }
        if (String(field.value || '').trim() === '') {
            showUserFormProblem(form, field, tr('js.required_field_missing_named', 'Please fill in: {field}.').replace('{field}', field.closest('.form-label')?.textContent.trim() || name));
            return false;
        }
    }
    const email = form.querySelector('[name="email"]');
    if (email && email.value.trim() !== '' && !email.validity.valid) {
        showUserFormProblem(form, email, tr('js.invalid_email', 'Please enter a valid email address.'));
        return false;
    }
    return true;
}

function confirmPastBillingDate(form) {
    const description = form.querySelector('[name="billing_item_description"]');
    const bookingDate = form.querySelector('[name="billing_item_booking_date"]');
    const allowPastDate = form.querySelector('[data-billing-allow-past-date]');
    if (allowPastDate) {
        allowPastDate.value = '0';
    }
    if (!description || !bookingDate || String(description.value || '').trim() === '' || bookingDate.disabled) {
        return true;
    }
    const value = String(bookingDate.value || '').trim();
    if (value === '') {
        showUserFormProblem(form, bookingDate, tr('billing.booking_date_invalid', 'The booking date is invalid.'));
        return false;
    }
    const today = new Date().toISOString().slice(0, 10);
    if (value >= today) {
        return true;
    }
    const question = tr(
        'js.confirm_past_booking_date',
        'The booking date {date} is in the past. Save it anyway?'
    ).replace('{date}', value);
    if (!window.confirm(question)) {
        showUserFormProblem(form, bookingDate, tr('billing.booking_date_invalid', 'The booking date is invalid.'));
        return false;
    }
    if (allowPastDate) {
        allowPastDate.value = '1';
    }
    return true;
}

async function checkUsernameAvailability(form) {
    const input = form.querySelector('[data-username-input]');
    const serverId = form.querySelector('[data-user-create-server-id]')?.value || '';
    const username = input?.value.trim() || '';
    if (!username || !serverId) {
        setUsernameStatus(form, '', '');
        return;
    }
    const body = new FormData();
    body.set('_ajax', '1');
    body.set('_action', 'check_username');
    body.set('server_id', serverId);
    body.set('username', username);
    setUsernameStatus(form, tr('js.username_checking', 'Checking username...'), 'pending');
    try {
        const data = await postAjax(body);
        setUsernameStatus(form, data.message || '', data.available ? 'ok' : 'error');
    } catch (error) {
        setUsernameStatus(form, error.message, 'error');
    }
}

initHelpPopovers();
initStatusTooltips();
initInvoiceTemplatePreviews();
initBackbillDomainForm();

const puttyIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-putty" viewBox="0 0 16 16" aria-hidden="true"><path d="M2 2.5A1.5 1.5 0 0 1 3.5 1h9A1.5 1.5 0 0 1 14 2.5v6A1.5 1.5 0 0 1 12.5 10H9v1.5h2.5a.5.5 0 0 1 0 1h-7a.5.5 0 0 1 0-1H7V10H3.5A1.5 1.5 0 0 1 2 8.5zM3.5 2a.5.5 0 0 0-.5.5v6a.5.5 0 0 0 .5.5h9a.5.5 0 0 0 .5-.5v-6a.5.5 0 0 0-.5-.5z"/><path d="M4.646 4.146a.5.5 0 0 1 .708 0L7.207 6 5.354 7.854a.5.5 0 1 1-.708-.708L5.793 6 4.646 4.854a.5.5 0 0 1 0-.708M7.5 7.5A.5.5 0 0 1 8 7h2.5a.5.5 0 0 1 0 1H8a.5.5 0 0 1-.5-.5"/></svg>';
const rebootIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-reboot" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z"/><path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466"/><path d="M7.5 6.5h1v4h-1z"/></svg>';

const refreshIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-clockwise" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z"/><path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466"/></svg>';
const clockIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-clock" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 3.5a.5.5 0 0 1 .5.5v3.25l2.25 1.35a.5.5 0 1 1-.5.866l-2.5-1.5A.5.5 0 0 1 7.5 7.5V4a.5.5 0 0 1 .5-.5"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m0-1A7 7 0 1 1 8 1a7 7 0 0 1 0 14"/></svg>';

function serverHostnameHtml(status) {
    const label = status.hostname || status.server_name || tr('common.unknown', 'Unknown');
    const hostname = status.dashboard_url
        ? '<a class="server-status-hostname server-dashboard-link" href="' + escapeHtml(status.dashboard_url) + '" target="' + escapeHtml(label) + '">' + escapeHtml(label) + '</a>'
        : '<span class="server-status-hostname">' + escapeHtml(label) + '</span>';
    const ssh = status.ssh_url
        ? '<a class="server-ssh-link" href="' + escapeHtml(status.ssh_url) + '" title="' + escapeHtml(tr('js.ssh_login', 'Open SSH connection')) + '" aria-label="' + escapeHtml(tr('js.ssh_login', 'Open SSH connection')) + '">' + puttyIconSvg + '</a>'
        : '';
    return hostname + ssh;
}

function updateServerHostname(card, status) {
    const heading = card.querySelector('header h2');
    if (heading) {
        heading.innerHTML = serverHostnameHtml(status);
    }
}

function initServerTimeTooltip(button) {
    if (!button || !window.bootstrap?.Tooltip) {
        return null;
    }
    const title = button.getAttribute('title');
    if (title) {
        button.setAttribute('data-bs-title', title);
        button.removeAttribute('title');
    }
    button.setAttribute('data-bs-toggle', 'tooltip');
    button.setAttribute('data-bs-placement', 'top');
    button.setAttribute('data-bs-trigger', 'hover');
    return window.bootstrap.Tooltip.getOrCreateInstance(button, {
        placement: 'top',
        trigger: 'hover',
    });
}

function initServerTimeTooltips(scope = document) {
    scope.querySelectorAll('.server-status-time').forEach((button) => initServerTimeTooltip(button));
}

function hideServerTimeTooltips(scope = document) {
    scope.querySelectorAll('.server-status-time').forEach((button) => {
        window.bootstrap?.Tooltip?.getInstance(button)?.hide();
    });
}

function serverCardHtml(status) {
    const hiddenError = status.error ? '' : ' hidden';
    const hiddenFacts = status.error ? ' hidden' : '';
    const hiddenReboot = status.reboot_required && !status.error ? '' : ' hidden';
    return '<article class="card server-card ' + (status.reboot_required ? 'needs-reboot' : '') + '" data-server-id="' + escapeHtml(status.server_id) + '">'
        + '<header><h2>' + serverHostnameHtml(status) + '</h2>'
        + '<div class="server-card-actions"><button type="button" class="server-status-time" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-trigger="hover" data-bs-title="' + escapeHtml(tr('js.last_refresh_unknown', 'No refresh yet')) + '" aria-label="' + escapeHtml(tr('js.last_refresh_unknown', 'No refresh yet')) + '">' + clockIconSvg + '</button>'
        + '<button type="button" class="server-status-refresh" title="' + escapeHtml(tr('js.refresh_server', 'Refresh server information')) + '" aria-label="' + escapeHtml(tr('js.refresh_server', 'Refresh server information')) + '">' + refreshIconSvg + '<span class="refresh-countdown" data-refresh-countdown></span></button></div></header>'
        + '<p class="error-text server-status-error"' + hiddenError + '>' + escapeHtml(status.error || '') + '</p>'
        + '<dl class="server-facts"' + hiddenFacts + '>'
        + serverFactHtml(tr('js.os', 'OS:'), 'os', status.os)
        + serverFactHtml(tr('js.kernel', 'Kernel:'), 'kernel', status.kernel)
        + serverFactHtml(tr('js.control_panel', 'Control Panel:'), 'panel', status.panel)
        + serverFactHtml(tr('js.cpu', 'CPU:'), 'cpu', status.cpu)
        + serverFactHtml(tr('js.uptime', 'Uptime:'), 'uptime', status.uptime)
        + serverFactHtml(tr('js.traffic_current_month', 'Traffic this month:'), 'traffic', status.traffic)
        + serverFactHtml(tr('js.consumed_disk', 'Consumed disk space:'), 'disk', status.disk)
        + '</dl><p class="reboot-text"' + hiddenReboot + '>' + escapeHtml(tr('js.reboot_required', 'Reboot required')) + ' <button type="button" class="server-reboot-button icon-only" data-server-id="' + escapeHtml(status.server_id) + '" title="' + escapeHtml(tr('js.reboot_server', 'Reboot server')) + '" aria-label="' + escapeHtml(tr('js.reboot_server', 'Reboot server')) + '">' + rebootIconSvg + '</button></p></article>';
}

function serverStatusCacheKey(serverId) {
    return serverStatusCachePrefix + serverId;
}

function loadCachedServerStatus(serverId) {
    try {
        const raw = localStorage.getItem(serverStatusCacheKey(serverId));
        if (!raw) {
            return null;
        }
        const cached = JSON.parse(raw);
        if (!cached || cached.version !== serverStatusCacheVersion || !cached.status || !cached.timestamp) {
            return null;
        }
        return cached;
    } catch (error) {
        return null;
    }
}

function isCachedServerStatusFresh(cached) {
    return Boolean(cached) && Date.now() - cached.timestamp < serverStatusCacheTtlSeconds * 1000;
}

function saveCachedServerStatus(status, timestamp = Date.now()) {
    if (!status || !status.server_id) {
        return;
    }
    try {
        localStorage.setItem(serverStatusCacheKey(status.server_id), JSON.stringify({
            version: serverStatusCacheVersion,
            timestamp,
            status,
        }));
    } catch (error) {
        // localStorage may be full or disabled; the live dashboard still works without caching.
    }
}

function clearCachedServerStatus(serverId) {
    if (!serverId) {
        return;
    }
    try {
        localStorage.removeItem(serverStatusCacheKey(serverId));
    } catch (error) {
        // Ignore cache cleanup errors; a fresh server refresh still works.
    }
}

function formatRefreshDate(timestamp) {
    const date = new Date(Number(timestamp));
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    if (formatSettings.date_format && formatSettings.date_format !== 'auto') {
        return formatDisplayDate(date.toISOString().slice(0, 10)) + ' ' + formatDisplayTime(date);
    }
    const options = { dateStyle: 'medium', timeStyle: 'medium' };
    if (formatSettings.time_format === '12') {
        options.hour12 = true;
    }
    if (formatSettings.time_format === '24') {
        options.hour12 = false;
    }
    try {
        return new Intl.DateTimeFormat(appLocale, options).format(date);
    } catch (error) {
        return new Intl.DateTimeFormat(undefined, options).format(date);
    }
}

function refreshTimestampMessage(timestamp) {
    const formatted = formatRefreshDate(timestamp);
    if (formatted === '') {
        return tr('js.last_refresh_unknown', 'No refresh yet');
    }
    return tr('js.last_refresh_at', 'Last refresh: {time}').replace('{time}', formatted);
}

function updateServerLastRefresh(card, timestamp) {
    if (!timestamp) {
        return;
    }
    card.dataset.lastRefresh = String(timestamp);
    const button = card.querySelector('.server-status-time');
    if (!button) {
        return;
    }
    const message = refreshTimestampMessage(timestamp);
    button.removeAttribute('title');
    button.setAttribute('aria-label', message);
    button.setAttribute('data-bs-title', message);
    button.setAttribute('data-bs-placement', 'top');
    button.setAttribute('data-bs-toggle', 'tooltip');
    button.setAttribute('data-bs-trigger', 'hover');
    button.dataset.bsTitle = message;
    const tooltip = initServerTimeTooltip(button);
    if (tooltip) {
        tooltip.setContent({ '.tooltip-inner': message });
        tooltip.update();
    }
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
        initServerTimeTooltips(grid);
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
        result.innerHTML = data.html || (data.groups && data.groups.length ? data.groups.map(userGroupHtml).join('') : '<p class="empty">' + escapeHtml(tr('js.no_active_servers', 'No active servers available.')) + '</p>');
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
        return '<section class="card server-user-group"><div class="card-body">' + title + '<p class="error-text">' + escapeHtml(group.error) + '</p></div></section>';
    }
    const rows = group.users.length
        ? group.users.map((user) => '<tr><td>' + escapeHtml(user.name) + '</td><td>' + escapeHtml(user.email || '') + '</td><td>' + escapeHtml(user.id || '') + '</td></tr>').join('')
        : '<tr><td colspan="3" class="empty">' + escapeHtml(tr('js.no_users_found', 'No users found.')) + '</td></tr>';
    return '<section class="card server-user-group"><div class="card-body">' + title + '<div class="table-responsive"><table class="table table-hover align-middle compact-table"><thead><tr><th>' + escapeHtml(tr('js.user', 'User')) + '</th><th>' + escapeHtml(tr('js.email', 'Email')) + '</th><th>' + escapeHtml(tr('js.id', 'ID')) + '</th></tr></thead><tbody>' + rows + '</tbody></table></div></div></section>';
}

const serverStatusTimers = new Map();

function applyServerStatus(card, status) {
    card.classList.remove('server-card-skeleton');
    card.classList.toggle('needs-reboot', Boolean(status.reboot_required));
    initServerTimeTooltip(card.querySelector('.server-status-time'));
    updateServerHostname(card, status);
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
            target.classList.remove('is-placeholder');
            target.textContent = status[field] || '-';
        }
    });
}

function initDashboard() {
    document.querySelectorAll('.server-card[data-server-id]').forEach((card) => {
        initServerTimeTooltip(card.querySelector('.server-status-time'));
        const cached = loadCachedServerStatus(card.dataset.serverId);
        if (isCachedServerStatusFresh(cached)) {
            applyServerStatus(card, cached.status);
            updateServerLastRefresh(card, cached.timestamp);
            scheduleServerStatusRefresh(card);
            return;
        }
        refreshServerStatus(card, false);
    });
}

if (document.body.dataset.page === 'dashboard') {
    initDashboard();
}

function scheduleServerStatusRefresh(card) {
    const oldState = serverStatusTimers.get(card);
    if (oldState) {
        clearTimeout(oldState.timeout);
        clearInterval(oldState.interval);
    }
    serverStatusTimers.delete(card);
    if (serverRefreshIntervalSeconds === 0) {
        updateServerRefreshCountdown(card, '');
        return;
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
        target.textContent = remaining === '' ? '' : String(remaining);
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
        const refreshedAt = Date.now();
        applyServerStatus(card, data.status);
        saveCachedServerStatus(data.status, refreshedAt);
        updateServerLastRefresh(card, refreshedAt);
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

function packageServerOptions(form) {
    return Array.from(form.querySelectorAll('[data-package-server-option]'));
}

function setPackageServerSelection(form, serverIds = [], selectAll = false) {
    const options = packageServerOptions(form);
    const all = form.querySelector('[data-package-server-all]');
    const selected = new Set((serverIds || []).map((id) => String(id)).filter(Boolean));
    const shouldSelectAll = selectAll || selected.size === 0 || selected.size >= options.length;
    options.forEach((option) => {
        option.checked = shouldSelectAll || selected.has(String(option.value));
    });
    if (all) {
        all.checked = options.length > 0 && options.every((option) => option.checked);
        all.indeterminate = false;
    }
    updatePackageServerSummary(form);
}

function updatePackageServerSummary(form) {
    const options = packageServerOptions(form);
    const checked = options.filter((option) => option.checked);
    const all = form.querySelector('[data-package-server-all]');
    const summary = form.querySelector('[data-package-server-summary]');
    if (all) {
        all.checked = options.length > 0 && checked.length === options.length;
        all.indeterminate = checked.length > 0 && checked.length < options.length;
    }
    if (!summary) {
        return;
    }
    if (checked.length === 0 || checked.length === options.length) {
        summary.textContent = tr('common.all_servers', 'alle Server');
        return;
    }
    const names = checked.map((option) => option.dataset.serverName || option.closest('label')?.textContent?.trim() || option.value);
    summary.textContent = names.length <= 2 ? names.join(', ') : names.length + ' Server';
}
function prepareHostingPackageForm(form, mode, packageData = {}) {
    form.reset();
    form.querySelector('[data-package-action]').value = mode === 'edit' ? 'update_package' : 'add_package';
    form.querySelector('[data-package-id]').value = packageData.id || '';
    setFormValue(form, 'name', packageData.name || '');
    setFormValue(form, 'description', packageData.description || '');
    setPackageServerSelection(form, packageData.serverIds || (packageData.serverId ? [packageData.serverId] : []), mode === 'create');
    const plan = packageData.plan || {};
    applyPlanResourceValues(form, plan);
    applyPlanPermissionValues(form, plan);
    applyPlanPhpValues(form, plan);
    applyPlanPhpFpmValues(form, plan);
    form.querySelectorAll('[data-resource-unlimited]').forEach((checkbox) => applyResourceUnlimited(checkbox));
    applyPermissionResourceLocks(form);
    form.querySelector('.nav-link[data-bs-target="#package-tab-general"]')?.click();
}

function openHostingPackageModal(mode, packageData = {}) {
    const form = document.querySelector('.hosting-package-form');
    const modalElement = document.getElementById('hostingPackageModal');
    if (!form || !modalElement || !window.bootstrap?.Modal) {
        return;
    }
    prepareHostingPackageForm(form, mode, packageData);
    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
}
document.addEventListener('click', async (event) => {
    const button = event.target.closest('.server-reboot-button');
    if (!button) {
        return;
    }
    const card = button.closest('.server-card');
    const name = card?.querySelector('.server-status-hostname')?.textContent.trim() || tr('common.unknown', 'Unknown');
    const question = tr('js.confirm_reboot_server', 'Really reboot server {name}?').replace('{name}', name);
    if (!window.confirm(question)) {
        return;
    }
    const body = new FormData();
    body.set('_ajax', '1');
    body.set('_action', 'reboot_server');
    body.set('id', button.dataset.serverId || card?.dataset.serverId || '');
    button.disabled = true;
    try {
        const data = await postAjax(body);
        showToast(data.message || tr('js.server_reboot_started', 'Server reboot has been started.'));
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
});
document.addEventListener('click', (event) => {
    const button = event.target.closest('.server-status-refresh');
    if (!button) {
        return;
    }
    refreshServerStatus(button.closest('.server-card'), true);
});

document.addEventListener('mouseover', (event) => {
    const button = event.target.closest('.server-status-time');
    if (!button) {
        return;
    }
    const hadTooltip = Boolean(window.bootstrap?.Tooltip?.getInstance(button));
    const tooltip = initServerTimeTooltip(button);
    if (!hadTooltip) {
        tooltip?.show();
    }
});

document.addEventListener('touchstart', (event) => {
    const button = event.target.closest('.server-status-time');
    if (!button) {
        return;
    }
    const tooltip = initServerTimeTooltip(button);
    button.focus({ preventScroll: true });
    tooltip?.show();
}, { passive: true });

document.addEventListener('focusout', (event) => {
    if (!event.target.closest?.('.server-status-time')) {
        return;
    }
    setTimeout(() => {
        if (!document.activeElement?.closest?.('.server-status-time')) {
            hideServerTimeTooltips();
        }
    }, 0);
});

window.addEventListener('scroll', () => hideServerTimeTooltips(), true);

document.addEventListener('show.bs.popover', (event) => {
    hideOtherHelpPopovers(event.target);
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-bs-toggle="popover"]');
    if (!button) {
        return;
    }
    initHelpPopovers(document);
    initStatusTooltips(document);
    hideOtherHelpPopovers(button);
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
        clearCachedServerStatus(server.id);
        summary.querySelector('.server-name').textContent = server.name;
        summary.querySelector('.server-url').textContent = server.base_url;
        summary.querySelector('.server-key-preview').textContent = server.api_key_preview;
        const status = summary.querySelector('.server-active-dot');
        if (status) {
            const statusTitle = server.active ? tr('server.active', 'active') : tr('server.inactive', 'inactive');
            status.classList.toggle('is-active', Boolean(server.active));
            status.classList.toggle('is-inactive', !server.active);
            status.setAttribute('title', statusTitle);
            status.setAttribute('data-bs-title', statusTitle);
            status.setAttribute('aria-label', statusTitle);
            window.bootstrap?.Tooltip?.getInstance(status)?.dispose();
            initStatusTooltips(summary);
        }form.querySelector('[name="api_token"]').value = '';
        form.hidden = true;
        summary.hidden = false;
        showToast(data.message || tr('js.server_saved', 'Server saved.'));
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('.ajax-domain-import-form');
    if (!form) {
        return;
    }
    event.preventDefault();
    const area = form.closest('[data-domain-import-area]');
    const button = form.querySelector('button');
    setDomainImportBusy(area, true);
    button.disabled = true;
    try {
        const data = await postAjax(new FormData(form));
        const content = area.querySelector('[data-domain-content]');
        if (content && data.html) {
            content.outerHTML = data.html;
            initStatusTooltips(area);
        }
        setDomainImportBusy(area, false);
        showToast(data.message || tr('js.domain_updated', 'Domain updated.'));
    } catch (error) {
        setDomainImportBusy(area, false);
        button.disabled = false;
        showToast(error.message, 'error');
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('.ajax-user-import-form');
    if (!form) {
        return;
    }
    event.preventDefault();
    const area = form.closest('[data-user-import-area]');
    const button = form.querySelector('button');
    setUserImportBusy(area, true);
    button.disabled = true;
    try {
        const data = await postAjax(new FormData(form));
        const content = area.querySelector('[data-users-content]');
        if (content && data.html) {
            content.outerHTML = data.html;
            initStatusTooltips(area);
        }
        setUserImportBusy(area, false);
        showToast(data.message || tr('js.user_updated', 'Users updated.'));
    } catch (error) {
        setUserImportBusy(area, false);
        button.disabled = false;
        showToast(error.message, 'error');
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('.config-tld-form');
    if (!form) {
        return;
    }
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    if (button) {
        button.disabled = true;
    }
    try {
        const data = await postAjax(new FormData(form));
        const current = document.querySelector('[data-tld-prices-content]');
        if (current && data.html) {
            current.outerHTML = data.html;
            initStatusTooltips(document);
        }
        form.reset();
        form.querySelector('[name="id"]').value = '';
        const active = form.querySelector('[name="active"]');
        if (active) {
            active.checked = true;
        }
        showToast(data.message || tr('billing.tld_saved', 'TLD price saved.'));
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        if (button) {
            button.disabled = false;
        }
    }
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('.tld-price-edit');
    if (!button) {
        return;
    }
    const row = button.closest('[data-tld-price-row]');
    const form = document.querySelector('.config-tld-form');
    if (!row || !form) {
        return;
    }
    let price = {};
    try {
        price = JSON.parse(row.dataset.tldPriceJson || '{}') || {};
    } catch (error) {
        price = {};
    }
    form.querySelector('[name="id"]').value = price.id || '';
    form.querySelector('[name="tld"]').value = price.tld || '';
    form.querySelector('[name="registration_price"]').value = price.registration_price || '0.00';
    form.querySelector('[name="yearly_price"]').value = price.yearly_price || '0.00';
    form.querySelector('[name="change_price"]').value = price.change_price || '0.00';
    form.querySelector('[name="tax_rate_id"]').value = price.tax_rate_id || '';
    form.querySelector('[name="active"]').checked = String(price.active || '') === '1';
    form.scrollIntoView({ block: 'center', behavior: 'smooth' });
    form.querySelector('[name="tld"]')?.focus({ preventScroll: true });
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('.hosting-package-create');
    if (!button) {
        return;
    }
    openHostingPackageModal('create');
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('.hosting-package-edit');
    if (!button) {
        return;
    }
    let plan = {};
    try {
        plan = JSON.parse(button.dataset.packageJson || '{}') || {};
    } catch (error) {
        plan = {};
    }
    openHostingPackageModal('edit', {
        id: button.dataset.packageId || '',
        name: button.dataset.packageName || plan.name || '',
        description: button.dataset.packageDescription || '',
        serverId: button.dataset.packageServerId || '',
        serverIds: (button.dataset.packageServerIds || '').split(',').filter(Boolean),
        plan,
    });
});

document.addEventListener('click', (event) => {
    const checkbox = event.target.closest('[data-package-server-all], [data-package-server-option]');
    if (!checkbox) {
        return;
    }
    const form = checkbox.closest('.hosting-package-form');
    if (!form) {
        return;
    }
    if (checkbox.matches('[data-package-server-all]')) {
        packageServerOptions(form).forEach((option) => {
            option.checked = checkbox.checked;
        });
    }
    updatePackageServerSummary(form);
});
document.addEventListener('click', (event) => {
    const button = event.target.closest('.user-create-open');
    if (!button) {
        return;
    }
    const form = document.querySelector('.ajax-user-create-form');
    if (!form) {
        return;
    }
    prepareUserForm(form, 'create', button.dataset.serverId || '', button.dataset.serverName || '');
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('.user-edit-open');
    if (!button) {
        return;
    }
    const row = button.closest('[data-user-row]');
    const form = document.querySelector('.ajax-user-create-form');
    if (!row || !form) {
        return;
    }
    let user = {};
    try {
        user = JSON.parse(row.dataset.userJson || '{}') || {};
    } catch (error) {
        user = {};
    }
    prepareUserForm(form, 'edit', row.dataset.userServerId || '', row.dataset.userServerName || '');
    form.querySelector('[data-user-create-local-id]').value = row.dataset.userId || '';
    setUserBillingControls(form, 'edit');
    fillUserForm(form, user);
});

document.addEventListener('change', (event) => {
    const checkbox = event.target.closest('[data-resource-unlimited]');
    if (!checkbox) {
        return;
    }
    applyResourceUnlimited(checkbox);
});

document.addEventListener('change', (event) => {
    const permission = event.target.closest('.ajax-user-create-form [name^="permission_"], .hosting-package-form [name^="permission_"]');
    if (!permission) {
        return;
    }
    applyPermissionResourceLocks(permission.closest('form'));
});

document.addEventListener('change', (event) => {
    const select = event.target.closest('[data-hosting-plan-select]');
    if (!select) {
        return;
    }
    const form = select.closest('form');
    applyHostingPlanResources(form);
    applyPermissionResourceLocks(form);
});

document.addEventListener('input', (event) => {
    const field = event.target.closest('.ajax-user-create-form .is-invalid');
    if (!field) {
        return;
    }
    clearUserFormProblem(field);
});

document.addEventListener('change', (event) => {
    const field = event.target.closest('.ajax-user-create-form .is-invalid');
    if (!field) {
        return;
    }
    clearUserFormProblem(field);
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-password-generate]');
    if (!button) {
        return;
    }
    const form = button.closest('.ajax-user-create-form');
    const password = generatePassword(20);
    form.querySelector('[name="password"]').value = password;
    form.querySelector('[name="password_confirmation"]').value = password;
});

document.addEventListener('input', (event) => {
    const input = event.target.closest('[data-username-input]');
    if (!input) {
        return;
    }
    const form = input.closest('.ajax-user-create-form');
    clearTimeout(usernameCheckTimer);
    setUsernameStatus(form, tr('js.username_checking', 'Checking username...'), 'pending');
    usernameCheckTimer = setTimeout(() => checkUsernameAvailability(form), 350);
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-username-suggest]');
    if (!button) {
        return;
    }
    const form = button.closest('.ajax-user-create-form');
    const serverId = form.querySelector('[data-user-create-server-id]')?.value || '';
    const input = form.querySelector('[data-username-input]');
    const body = new FormData();
    body.set('_ajax', '1');
    body.set('_action', 'suggest_username');
    body.set('server_id', serverId);
    button.disabled = true;
    setUsernameStatus(form, tr('js.username_generating', 'Generating username...'), 'pending');
    try {
        const data = await postAjax(body);
        input.value = data.username || '';
        setUsernameStatus(form, data.message || '', data.available ? 'ok' : '');
    } catch (error) {
        setUsernameStatus(form, error.message, 'error');
    } finally {
        button.disabled = false;
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('.ajax-user-create-form');
    if (!form) {
        return;
    }
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const isEdit = form.dataset.mode === 'edit';
    if (!validateUserFormBasics(form, isEdit)) {
        return;
    }
    if (!confirmPastBillingDate(form)) {
        return;
    }
    const password = form.querySelector('[name="password"]')?.value || '';
    const confirm = form.querySelector('[name="password_confirmation"]')?.value || '';
    if (password !== confirm) {
        const confirmation = form.querySelector('[name="password_confirmation"]');
        if (confirmation) {
            showUserFormProblem(form, confirmation, tr('js.passwords_do_not_match', 'Passwords do not match.'));
        } else {
            showToast(tr('js.passwords_do_not_match', 'Passwords do not match.'), 'error');
        }
        return;
    }
    if (!isEdit) {
        await checkUsernameAvailability(form);
    }
    if (!isEdit && form.querySelector('[data-username-input]')?.dataset.usernameAvailable !== '1') {
        const username = form.querySelector('[data-username-input]');
        if (username) {
            showUserFormProblem(form, username, tr('js.username_not_available', 'Username is not available.'));
        } else {
            showToast(tr('js.username_not_available', 'Username is not available.'), 'error');
        }
        return;
    }
    button.disabled = true;
    try {
        const data = await postAjax(formDataWithDisabledFields(form));
        const area = document.querySelector('[data-user-import-area]');
        const content = area?.querySelector('[data-users-content]');
        if (content && data.html) {
            content.outerHTML = data.html;
            initStatusTooltips(area || document);
        }
        const modalElement = form.closest('.modal');
        window.bootstrap?.Modal?.getInstance(modalElement)?.hide();
        showToast(data.message || tr('js.user_created', 'User created.'));
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('.billing-item-delete');
    if (!button) {
        return;
    }
    const form = button.closest('.ajax-user-create-form');
    const itemId = button.dataset.billingItemId || '';
    const userId = form?.querySelector('[data-user-create-local-id]')?.value || '';
    if (!form || !itemId) {
        return;
    }
    const question = tr('billing.confirm_delete_user_item', 'Delete this billing item?');
    if (!window.confirm(question)) {
        return;
    }
    const body = new FormData();
    body.set('_action', 'delete_billing_user_item');
    body.set('id', itemId);
    body.set('user_id', userId);
    button.disabled = true;
    try {
        const data = await postAjax(body);
        renderUserBillingItems(form, data.items || []);
        const row = document.querySelector('[data-user-row][data-user-id="' + CSS.escape(userId) + '"]');
        if (row?.dataset.userJson) {
            try {
                const payload = JSON.parse(row.dataset.userJson);
                payload._billing_items = data.items || [];
                row.dataset.userJson = JSON.stringify(payload);
            } catch (error) {
                // The modal already has the fresh data; stale row cache is non-critical.
            }
        }
        showToast(data.message || tr('billing.user_item_deleted', 'Billing item deleted.'));
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
});

document.addEventListener('click', async (event) => {
    const link = event.target.closest('[data-user-login]');
    if (!link) {
        return;
    }
    event.preventDefault();
    const row = link.closest('[data-user-row]');
    const body = new FormData();
    body.set('_ajax', '1');
    body.set('_action', 'user_login_url');
    body.set('user_id', row?.dataset.userId || '');
    try {
        const data = await postAjax(body);
        if (data.url) {
            window.open(data.url, '_blank', 'noopener');
        }
    } catch (error) {
        showToast(error.message, 'error');
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('.user-delete');
    if (!button) {
        return;
    }
    const row = button.closest('[data-user-row]');
    const name = row?.querySelector('[data-user-login]')?.textContent.trim() || '';
    if (!window.confirm(tr('js.confirm_delete_user', 'Delete user {name}?').replace('{name}', name))) {
        return;
    }
    const area = button.closest('[data-user-import-area]');
    const body = new FormData();
    body.set('_ajax', '1');
    body.set('_action', 'delete_user');
    body.set('user_id', row?.dataset.userId || '');
    button.disabled = true;
    try {
        const data = await postAjax(body);
        const content = area?.querySelector('[data-users-content]');
        if (content && data.html) {
            content.outerHTML = data.html;
            initStatusTooltips(area || document);
        }
        showToast(data.message || tr('js.user_deleted', 'User deleted.'));
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
});


function fillDomainSettingsForm(form, row) {
    let domain = {};
    try {
        domain = JSON.parse(row.dataset.domainJson || '{}') || {};
    } catch (error) {
        domain = {};
    }
    const override = domain._billing_override || {};
    setFormValue(form, 'id', row.dataset.domainId || '');
    const idField = form.querySelector('[data-domain-settings-id]');
    if (idField) {
        idField.value = row.dataset.domainId || '';
    }
    const label = form.querySelector('[data-domain-settings-label]');
    if (label) {
        label.textContent = (domain.server_name ? domain.server_name + ' / ' : '') + (domain.domain || row.dataset.domainName || '');
    }
    setFormValue(form, 'registered_at', (domain.registered_at || '').slice(0, 10));
    setFormValue(form, 'next_billing_at', (domain.next_billing_at || '').slice(0, 10));
    setFormValue(form, 'billing_frequency', domain.billing_frequency || 'yearly');
    setFormValue(form, 'last_change_at', (domain.last_change_at || '').slice(0, 10));
    setFormValue(form, 'registrar', domain.registrar || '');
    setFormValue(form, 'yearly_price', override.yearly_price ?? '');
    setFormValue(form, 'discount_percent', override.discount_percent ?? '');
    setFormValue(form, 'registration_price', override.registration_price ?? '');
    setFormValue(form, 'change_price', override.change_price ?? '');
    setFormValue(form, 'tax_rate_id', override.tax_rate_id ?? '');
    setFormValue(form, 'active', !override.id || Number(override.active ?? 1) === 1);
    setFormValue(form, 'domain_owner_contact', domain.domain_owner_contact || '');
    setFormValue(form, 'domain_admin_c', domain.domain_admin_c || '');
    setFormValue(form, 'domain_tech_c', domain.domain_tech_c || '');
    setFormValue(form, 'domain_zone_c', domain.domain_zone_c || '');
    form.querySelector('.nav-link[data-bs-target="#domain-tab-general"]')?.click();
}

function updateDomainRowDataset(row, domain, override = null) {
    if (!row || !domain) {
        return;
    }
    let current = {};
    try {
        current = JSON.parse(row.dataset.domainJson || '{}') || {};
    } catch (error) {
        current = {};
    }
    row.dataset.domainJson = JSON.stringify({ ...current, ...domain, _billing_override: override || current._billing_override || {} });
}


document.addEventListener('click', (event) => {
    const button = event.target.closest('.domain-settings-open');
    if (!button) {
        return;
    }
    const row = button.closest('.domain-row');
    const form = document.querySelector('.ajax-domain-settings-form');
    if (!row || !form) {
        return;
    }
    form.reset();
    fillDomainSettingsForm(form, row);
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('.ajax-domain-settings-form');
    if (!form) {
        return;
    }
    event.preventDefault();
    const id = form.querySelector('[data-domain-settings-id]')?.value || '';
    const row = document.querySelector('.domain-row[data-domain-id="' + CSS.escape(id) + '"]');
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
        const data = await postAjax(new FormData(form));
        if (row && data.domain) {
            applyDomainData(row, data.domain, data.row_class || '', data.status_html || '');
            updateDomainRowDataset(row, data.domain);
        }
        window.bootstrap?.Modal?.getInstance(form.closest('.modal'))?.hide();
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
            document.getElementById('subdomains-' + domainId)?.remove();
            row.remove();
            updateDuplicateClasses(domainName);
            showToast(data.message || tr('js.domain_deleted', 'Domain deleted locally.'));
            return;
        }
        applyDomainData(row, data.domain, data.row_class, data.status_html);
        updateDomainRowDataset(row, data.domain);
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
