# KeyHelp MSM

Zentrale PHP-Webanwendung für mehrere KeyHelp-Server.

## Umfang im ersten Stand

- mehrere KeyHelp-Server lokal verwalten
- Domains serverübergreifend synchronisieren und lokalen Abrechnungsdaten zuordnen
- Hostingpakete zentral anlegen und als Aktionen vormerken
- Benutzer systemweit oder serverspezifisch vormerken
- Aktionen werden erst gesammelt und per Sync nach Sichtprüfung ausgeführt
- Sync stoppt beim ersten Serverfehler und protokolliert die Ursache
- lokale Datenhaltung in MySQL

## Deployment als Webanwendung

Die Anwendung ist für Apache, Nginx oder einen vergleichbaren Webserver gedacht. Der DocumentRoot muss auf `public/` zeigen.

Beispiel Apache-VHost:

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

Beispiel Nginx:

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

1. MySQL-Datenbank und Benutzer anlegen, z. B. `keyhelp_verwaltung`.
2. `config/config.example.php` nach `config/config.php` kopieren.
3. DB-Zugang, Admin-Passwort und KeyHelp-Server eintragen. Die KeyHelp-API nutzt standardmäßig den Header `X-API-Key`; bei abweichender Installation kann `keyhelp.auth` in der Config angepasst werden.
4. PHP mit PDO-MySQL und cURL verwenden.
5. Webserver so konfigurieren, dass nur `public/` öffentlich erreichbar ist.

Die Anwendung legt die Tabellen beim ersten Aufruf automatisch an.
