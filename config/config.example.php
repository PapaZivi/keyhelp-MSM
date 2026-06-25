<?php
return [
    'app' => [
        'name' => 'KeyHelp Verwaltung',
        'timezone' => 'Europe/Berlin',
        'admin_user' => 'admin',
        'admin_password_hash' => password_hash('bitte-aendern', PASSWORD_DEFAULT),
        'debug_log_file' => __DIR__ . '/../storage/logs/debug.log',
        'debug_level' => 0, // 0 = keine Debug-Ausgabe, 1 = Fehler, 2 = Warnungen, 3 = Info, 4 = Debug
    ],
    'database' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=keyhelp_verwaltung;charset=utf8mb4',
        'user' => 'keyhelp_verwaltung',
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
            'users' => '/api/v2/users',
            'domains' => '/api/v2/domains',
            'domain_detail' => '/api/v2/domains/{id}',
            'client_detail' => '/api/v2/clients/{id}',
            'hosting_plans' => '/api/v2/hosting-plans',
        ],
    ],
];
