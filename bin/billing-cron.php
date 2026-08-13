<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
$config = file_exists($configFile) ? require $configFile : require $root . '/config/config.example.php';
date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Berlin');

require $root . '/src/helpers.php';
require $root . '/src/I18n.php';
require $root . '/src/View.php';
require $root . '/src/Logger.php';
require $root . '/src/Database.php';
require $root . '/src/Migration.php';
require $root . '/src/DomainOwner.php';
require $root . '/src/KeyHelpClient.php';
require $root . '/src/InvoicePdfRenderer.php';
require $root . '/src/Repository.php';
require $root . '/src/SyncService.php';
require $root . '/src/BillingService.php';

i18n_init($config);

try {
    $repo = new Repository(Migration::connect($config));
    $configuredLocale = $repo->locale((string)($config['app']['locale'] ?? 'de'));
    i18n_set_locale($configuredLocale, false);

    $billing = new BillingService($config, $repo);
    $runMessage = $billing->run('cron', true);
    $sendMessage = $billing->sendQueued('cron');

    echo $runMessage . PHP_EOL;
    echo $sendMessage . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    log_exception($config, $exception, 'Cron-Rechnungslauf fehlgeschlagen.', ['action' => 'billing_cron']);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
