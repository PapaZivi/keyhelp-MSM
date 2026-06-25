# KeyHelp Management

Central PHP web application for managing multiple KeyHelp servers.

## Initial Scope

- manage multiple KeyHelp servers locally
- synchronize domains across servers and assign them to local billing data
- centrally create hosting packages and queue them as actions
- queue users system-wide or for specific servers
- collect actions first and execute them via sync after manual review
- stop synchronization at the first server error and log the cause
- store local data in MySQL

## Deployment as a Web Application

The application is intended for Apache, Nginx, or a comparable web server. The document root must point to `public/`.

Example Apache vHost:

```apache
<VirtualHost *:80>
    ServerName keyhelp-verwaltung.local
    DocumentRoot "D:/github/keyhelp-verwaltung/public"

    <Directory "D:/github/keyhelp-verwaltung/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Example Nginx:

```nginx
server {
    listen 80;
    server_name keyhelp-verwaltung.local;
    root D:/github/keyhelp-verwaltung/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass 127.0.0.1:9000;
    }
}
```

## Installation

1. Create a MySQL database and user, for example `keyhelp_verwaltung`.
2. Copy `config/config.example.php` to `config/config.php`.
3. Enter the database access data, admin password, and KeyHelp servers. By default, the KeyHelp API uses the `X-API-Key` header. If your installation differs, `keyhelp.auth` can be adjusted in the configuration.
4. Use PHP with PDO-MySQL and cURL.
5. Configure the web server so that only `public/` is publicly accessible.

The application automatically creates the required tables on first access.

## Security

The repository does not contain credentials. `config/config.php` is ignored and remains local.
