<?php
final class Logger
{
    public function __construct(private array $config) {}

    public function log(int $level, string $message, array $context = []): void
    {
        $app = $this->config['app'] ?? [];
        if ((int)($app['debug_level'] ?? 0) < $level) {
            return;
        }
        $file = (string)($app['debug_log_file'] ?? '');
        if ($file === '') {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] level=%d %s%s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            PHP_EOL
        );
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}