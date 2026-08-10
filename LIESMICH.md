# KeyHelp MSM

KeyHelp MSM ist eine PHP-Webanwendung zur zentralen Verwaltung mehrerer KeyHelp-Server.

## Aktueller Umfang

- Mehrere KeyHelp-Server über eine Oberfläche verwalten.
- Serververbindungen lokal speichern, inklusive geschützter API-Key-Anzeige.
- Server-Dashboard mit Hostname, Betriebssystem, Kernel, KeyHelp-Version, CPU-Last, Uptime, Traffic, Speicherverbrauch, Refresh-Timer, Reboot-Aktion und optionalem SSH-Link.
- Domains von allen konfigurierten Servern importieren.
- Doppelte Domains, deaktivierte oder gesperrte Domains, gelöschte Domains und zur Löschung vorgemerkte Domains anzeigen.
- Subdomains bei Bedarf per AJAX nachladen.
- Lokale Abrechnungsdaten für Domains speichern, zum Beispiel Registrierungsdatum, nächstes Abrechnungsdatum, Abrechnungsintervall, Registrar und domainbezogene Preise.
- Lokale Benutzer als zentrale Kundenakte verwalten.
- Remote-Benutzer aus KeyHelp unabhängig vom Remote-Benutzernamen lokalen Benutzern zuordnen.
- Domains lokalen Benutzern zuordnen, entweder über die Remote-Benutzer-Zuordnung oder manuell.
- Remote-Benutzer über einen Wizard aus einem lokalen Benutzer heraus anlegen.
- Hostingpakete von KeyHelp-Servern importieren und lokal speichern.
- Abrechnungseinstellungen, Steuersätze, TLD-Preise, Domain-Sonderpreise und manuelle Rechnungsposten verwalten.
- Globalen Rechnungslauf oder Rechnungslauf für einen einzelnen lokalen Benutzer starten.
- Kundenkonto je lokalem Benutzer mit Rechnungen, Zahlungen, vorgemerkten Beträgen und Saldo führen.
- Zahlungen mit Buchungsdatum und internem Erfassungszeitpunkt speichern.
- Rechnungs-PDFs mit TCPDF aus einem bearbeitbaren HTML-Template erzeugen.
- Rechnungen aus der Abrechnung und dem Kundenkonto heraus als Vorschau öffnen.
- Rechnungen freigeben, für Sammelversand vormerken, stornieren, zulässige Entwürfe löschen und stornierte Rechnungsposten wieder sammeln.
- Sprache, Locale, Zahlen-/Datums-/Zeitformate, Währung, Theme-Modus und Dashboard-Refresh-Intervall konfigurieren.
- Die Anwendung über JSON-Sprachpakete übersetzen.

## Deployment als Webanwendung

Die Anwendung ist für Apache, Nginx oder einen vergleichbaren Webserver gedacht. Der DocumentRoot muss auf `public/` zeigen.

Beispiel Apache-VHost für einen von KeyHelp verwalteten Webspace:

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

In KeyHelp wird dafür eine normale Domain oder Subdomain angelegt. Der DocumentRoot zeigt auf das Verzeichnis `public/` der Anwendung, und PHP wird für diesen Webspace aktiviert. Die Dateien außerhalb von `public/` dürfen nicht direkt über den Browser erreichbar sein.

Beispiel Nginx-Konfiguration:

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

1. MySQL-Datenbank und Benutzer anlegen, zum Beispiel `keyhelp_msm`.
2. `config/config.example.php` nach `config/config.php` kopieren.
3. Datenbankzugang, Admin-Login, KeyHelp-Server und Logging-Einstellungen eintragen.
4. Sicherstellen, dass PHP PDO-MySQL und cURL aktiviert hat.
5. TCPDF installieren, wenn Rechnungs-PDFs erzeugt werden sollen. Unter Debian/Ubuntu kann dafür das Paket `php-tcpdf` verwendet werden.
6. Den Webserver so konfigurieren, dass nur `public/` öffentlich erreichbar ist.

Die Anwendung legt die benötigten Tabellen beim ersten Aufruf automatisch an.

## Datenbankschema und Migrationen

Dieses Release verwendet `database/schema.sql` als bereinigtes Basisschema für Neuinstallationen.

Künftige Datenbankmigrationen sollen in `src/Migration.php` ergänzt werden. `Migration` erweitert `Database`, sodass die Verbindung in `Database.php` bleibt und Schemaänderungen von Release zu Release getrennt gepflegt werden.

Ältere Vorabinstallationen vor diesem Release werden durch diese bereinigte Release-Basis nicht automatisch migriert.

## KeyHelp-API

Standardmäßig verwenden KeyHelp-API-Requests den Header `X-API-Key`. Wenn deine KeyHelp-Installation eine andere Methode benötigt, kann das über `keyhelp.auth` in `config/config.php` angepasst werden.

API-Requests und Antworten können über die konfigurierte Logdatei und den Debug-Level protokolliert werden.

## Abrechnung

Das Abrechnungsmodul ist über den Navigationspunkt `Abrechnung` erreichbar.

Es verwaltet:

- Steuersätze
- TLD-Preise
- Domain-Sonderpreise
- Rabatte lokaler Benutzer
- manuelle Rechnungsposten
- Domain-Abrechnungsintervalle
- Zahlungen im Kundenkonto
- Rechnungsfreigabe und Versand
- Rechnungs-PDF-Erzeugung

Der globale Rechnungslauf wird auf der Abrechnungsseite gestartet. Ein benutzerspezifischer Rechnungslauf kann im Kundenkonto-Tab eines lokalen Benutzers gestartet werden.

Rechnungs-PDFs werden hier gespeichert:

```text
storage/invoices/
```

Der erzeugte Pfad wird zusätzlich in der Datenbank im Feld `invoices.pdf_path` gespeichert.

## Übersetzungen

Die Weboberfläche unterstützt mehrere Sprachen über JSON-Dateien in `lang/`.

Jede Datei muss diese Struktur verwenden:

```json
{
  "language": "Deutsch",
  "locale": "de-DE",
  "messages": {
    "common.save": "Speichern"
  }
}
```

Um eine Sprache zu ergänzen, wird eine weitere JSON-Datei in `lang/` abgelegt. Die Anwendung erkennt verfügbare Sprachpakete automatisch.
