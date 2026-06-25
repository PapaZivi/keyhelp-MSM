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
        'update_server' => 'Der Server konnte nicht gespeichert werden. Details wurden ins Log geschrieben.',
        default => 'Die Aktion konnte nicht ausgefuehrt werden. Details wurden ins Log geschrieben.',
    };
}