# KeyHelp MSM

KeyHelp MSM is a PHP web application for centrally managing multiple KeyHelp servers.

## Current Scope

- Manage multiple KeyHelp servers from one interface.
- Store server connections locally with protected API key display.
- Show server dashboard cards with hostname, operating system, kernel, KeyHelp version, CPU load, uptime, traffic, disk usage, refresh timers, reboot actions and optional SSH links.
- Import domains from all configured servers.
- Show duplicate domains, disabled or locked domains, deleted domains and scheduled deletion states.
- Load subdomains on demand via AJAX.
- Store local domain billing data such as registration date, next billing date, billing interval, registrar and domain-specific prices.
- Manage local users as the central customer account.
- Assign remote KeyHelp users to local users independently of their remote usernames.
- Assign domains to local users, either through their remote user relation or manually.
- Create remote users from a local user through a wizard.
- Import hosting plans from KeyHelp servers and store them locally.
- Manage billing settings, tax rates, TLD prices, domain price overrides and manual billing items.
- Run global billing or a billing run for one local user.
- Keep a customer account per local user with invoices, payments, reserved amounts and balance.
- Record payments with a booking date and an internal creation timestamp.
- Generate invoice PDFs with TCPDF from an editable HTML template.
- Preview invoices from billing and customer account views.
- Approve invoices, queue invoices for batch sending, cancel invoices, delete allowed drafts and requeue cancelled invoice items.
- Configure language, locale, number/date/time formats, currency, theme mode and dashboard refresh interval.
- Translate the application through JSON language packs.

## Deployment As A Web Application

The application is intended for Apache, Nginx or a comparable web server. The document root must point to `public/`.

Example Apache vHost for a KeyHelp-managed webspace:

```apache
<VirtualHost *:80>
    ServerName khmsm.example.com
    ServerAdmin webmaster@example.com
    DocumentRoot /home/users/keyhelpmsm/www/khmsm.example.com/public

    <Directory /home/users/keyhelpmsm/www/khmsm.example.com/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/khmsm.example.com-error.log
    CustomLog ${APACHE_LOG_DIR}/khmsm.example.com-access.log combined
</VirtualHost>
```

In KeyHelp, create a normal domain or subdomain, point its document root to the application's `public/` directory and enable PHP for that webspace. The files outside `public/` must not be web-accessible.

Example Nginx configuration:

```nginx
server {
    listen 80;
    server_name khmsm.example.com;
    root /home/users/keyhelpmsm/www/khmsm.example.com/public;
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

1. Create a MySQL database and user, for example `keyhelp_msm`.
2. Copy `config/config.example.php` to `config/config.php`.
3. Enter the database credentials, admin login, KeyHelp servers and logging settings.
4. Make sure PHP has PDO-MySQL and cURL enabled.
5. Install TCPDF if invoice PDFs should be generated. On Debian/Ubuntu this can be provided by the `php-tcpdf` package.
6. Configure the web server so that only `public/` is publicly accessible.

The application creates the required tables on first access.

## Database Schema And Migrations

This release uses `database/schema.sql` as the clean baseline schema for fresh installations.

Future database migrations should be added to `src/Migration.php`. `Migration` extends `Database`, so connection handling remains in `Database.php` while release-to-release schema changes live separately.

Older pre-release installations are not automatically migrated by this cleaned release baseline.

## KeyHelp API

By default, KeyHelp API requests use the `X-API-Key` header. If your KeyHelp installation requires another method, adjust the `keyhelp.auth` settings in `config/config.php`.

API requests and responses can be logged through the configured log file and debug level.

## Billing

The billing module is available from the `Billing` navigation item.

It manages:

- tax rates
- TLD prices
- domain-specific overrides
- local user discounts
- manual billing items
- domain billing intervals
- customer account payments
- invoice approval and sending
- invoice PDF generation

The global billing run starts from the billing page. A user-specific billing run can be started from the local user's customer account tab.

For automated billing, run the CLI script from cron:

```bash
/usr/bin/php /home/users/keyhelpmsm/www/khmsm.example.com/bin/billing-cron.php
```

The cron job collects due items, creates invoices according to the local user's invoice frequency and billing cut-off settings, and sends invoices that were already queued for batch sending.

Invoice PDFs are stored in:

```text
storage/invoices/
```

The generated path is also stored in the `invoices.pdf_path` database field.

## Translations

The web interface supports multiple languages through JSON files in `lang/`.

Each file must use this structure:

```json
{
  "language": "English",
  "locale": "en-US",
  "messages": {
    "common.save": "Save"
  }
}
```

To add a language, add another JSON file to `lang/`. The application automatically detects available language packs.
