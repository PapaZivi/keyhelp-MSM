<?php
function i18n_init(array $config): void
{
    $supported = i18n_supported_locales();
    $default = (string)($config['app']['locale'] ?? 'de');
    if (!array_key_exists($default, $supported)) {
        $default = array_key_first($supported) ?: 'de';
    }

    $requested = (string)($_GET['lang'] ?? '');
    if (array_key_exists($requested, $supported)) {
        $_SESSION['locale'] = $requested;
    }

    $locale = (string)($_SESSION['locale'] ?? $default);
    if (!array_key_exists($locale, $supported)) {
        $locale = $default;
    }

    i18n_set_locale($locale, false);
}

function i18n_supported_locales(): array
{
    $locales = [];
    foreach (glob(dirname(__DIR__) . '/lang/*.json') ?: [] as $path) {
        $locale = basename($path, '.json');
        try {
            $catalog = i18n_load_catalog($locale);
            $locales[$locale] = (string)($catalog['language'] ?? $locale);
        } catch (Throwable) {
            continue;
        }
    }
    ksort($locales);
    return $locales;
}

function i18n_load_catalog(string $locale): array
{
    if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale)) {
        throw new RuntimeException('Invalid locale code: ' . $locale);
    }
    $path = dirname(__DIR__) . '/lang/' . $locale . '.json';
    if (!is_file($path)) {
        throw new RuntimeException('Translation file not found: ' . $locale);
    }

    $json = file_get_contents($path);
    $catalog = json_decode($json === false ? '' : $json, true);
    if (!is_array($catalog) || !isset($catalog['messages']) || !is_array($catalog['messages'])) {
        throw new RuntimeException('Translation file has invalid structure: ' . $locale);
    }
    return $catalog;
}

function i18n_load_messages(string $locale): array
{
    return i18n_load_catalog($locale)['messages'];
}

function i18n_locale_code(?string $locale = null): string
{
    $locale = $locale ?? current_locale();
    $catalog = i18n_load_catalog($locale);
    return (string)($catalog['locale'] ?? str_replace('_', '-', $locale));
}

function i18n_set_locale(string $locale, bool $persistSession = true): void
{
    $supported = i18n_supported_locales();
    if (!array_key_exists($locale, $supported)) {
        $locale = array_key_first($supported) ?: 'de';
    }
    if ($persistSession) {
        $_SESSION['locale'] = $locale;
    }
    $GLOBALS['i18n_locale'] = $locale;
    $GLOBALS['i18n_messages'] = i18n_load_messages($locale);
}

function current_locale(): string
{
    return (string)($GLOBALS['i18n_locale'] ?? 'de');
}

function t(string $key, array $params = []): string
{
    $messages = $GLOBALS['i18n_messages'] ?? [];
    $text = (string)($messages[$key] ?? $key);
    foreach ($params as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }
    return $text;
}

function i18n_translate(string $key, array $params = [], string $fallback = '', ?string $locale = null): string
{
    $messages = $locale === null ? ($GLOBALS['i18n_messages'] ?? []) : i18n_load_messages($locale);
    $text = (string)($messages[$key] ?? ($fallback !== '' ? $fallback : $key));
    foreach ($params as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }
    return $text;
}

function i18n_js_messages(): array
{
    $messages = $GLOBALS['i18n_messages'] ?? [];
    return array_filter($messages, static fn(string $key): bool => str_starts_with($key, 'js.') || in_array($key, ['common.unknown', 'common.loading', 'server.active', 'server.inactive', 'common.off', 'domains.locked_or_disabled', 'users.create_title', 'users.edit_title'], true), ARRAY_FILTER_USE_KEY);
}
