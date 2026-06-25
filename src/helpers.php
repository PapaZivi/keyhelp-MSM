<?php
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_with(string $message, string $type = 'ok'): never
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: /');
    exit;
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
        'import_domains' => 'Die Domains konnten nicht eingelesen werden. Details wurden ins Log geschrieben.',
        'run_sync' => 'Der Sync konnte nicht abgeschlossen werden. Details wurden ins Log geschrieben.',
        'update_domain' => 'Die Domain konnte nicht gespeichert werden. Details wurden ins Log geschrieben.',
        'refresh_domain' => 'Die Domain konnte nicht vom Server aktualisiert werden. Details wurden ins Log geschrieben.',
        'update_server' => 'Der Server konnte nicht gespeichert werden. Details wurden ins Log geschrieben.',
        default => 'Die Aktion konnte nicht ausgefuehrt werden. Details wurden ins Log geschrieben.',
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
        return '<span class="domain-state delete" title="Zur Loeschung vorgemerkt"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eraser status-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M8.086 2.207a2 2 0 0 1 2.828 0l3.879 3.879a2 2 0 0 1 0 2.828l-5.5 5.5A2 2 0 0 1 7.879 15H5.12a2 2 0 0 1-1.414-.586L.793 11.5a2 2 0 0 1 0-2.828zm2.121.707a1 1 0 0 0-1.414 0L4.16 7.547l5.293 5.293 4.633-4.633a1 1 0 0 0 0-1.414zM8.746 13.547 3.453 8.254 1.5 10.207a1 1 0 0 0 0 1.414l2.914 2.914a1 1 0 0 0 .707.293H7.88a1 1 0 0 0 .707-.293z"/></svg><span>' . h($domain['delete_on']) . '</span></span>';
    }
    if (!empty($domain['is_disabled']) || ((string)($domain['domain_status'] ?? '') !== '' && (int)$domain['domain_status'] !== 1)) {
        return '<span class="domain-state locked" title="Gesperrt oder deaktiviert"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-lock status-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2m3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2M5 8h6a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1"/></svg><span>gesperrt</span></span>';
    }
    return '';
}
