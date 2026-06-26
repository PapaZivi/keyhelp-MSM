<?php
return [
    'app' => [
        'name' => 'KeyHelp MSM',
        'timezone' => 'Europe/Berlin',
        'admin_user' => 'admin',
        'admin_password_hash' => password_hash('bitte-aendern', PASSWORD_DEFAULT),
        'debug_log_file' => __DIR__ . '/../storage/logs/debug.log',
        'debug_level' => 0, // 0 = keine Debug-Ausgabe, 1 = Fehler, 2 = Warnungen, 3 = Info, 4 = Debug
    ],
    'database' => [
        'type' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'charset' => 'utf8mb4',
        'database' => 'keyhelp_msm',
        'user' => 'keyhelp_msm',
        'password' => 'bitte-aendern',
    ],
    'keyhelp' => [
        'verify_tls' => true,
        'timeout' => 30,
        'auth' => [
            'header' => 'X-API-Key',
            'prefix' => '',
        ],
        'endpoint_map' => [
            'clients' => '/api/v2/clients',
            'domains' => '/api/v2/domains',
            'domain_detail' => '/api/v2/domains/{id}',
            'client_detail' => '/api/v2/clients/{id}',
            'hosting_plans' => '/api/v2/hosting-plans',
        ],
    ],
];
