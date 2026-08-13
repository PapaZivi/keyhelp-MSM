<?php
$configFile = dirname(__DIR__) . '/config/config.php';
$config = file_exists($configFile) ? require $configFile : require dirname(__DIR__) . '/config/config.example.php';
date_default_timezone_set($config['app']['timezone']);
require dirname(__DIR__) . '/src/helpers.php';
require dirname(__DIR__) . '/src/I18n.php';
require dirname(__DIR__) . '/src/Logger.php';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/DomainOwner.php';
require dirname(__DIR__) . '/src/Repository.php';
require dirname(__DIR__) . '/src/BillingService.php';
i18n_init($config);
$repo = new Repository(Database::connect($config));
$service = new BillingService($config, $repo);
echo $service->run('cron', true) . PHP_EOL;
