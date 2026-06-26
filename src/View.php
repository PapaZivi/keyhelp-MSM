<?php
function render_template(string $template, array $data = []): void
{
    $path = dirname(__DIR__) . '/templates/' . $template . '.php';
    if (!is_file($path)) {
        throw new RuntimeException('Template nicht gefunden: ' . $template);
    }
    extract($data, EXTR_SKIP);
    require $path;
}