<?php
final class Database
{
    public static function connect(array $config): PDO
    {
        $database = $config['database'] ?? [];
        $pdo = new PDO(
            self::dsn($database),
            $database['user'] ?? null,
            $database['password'] ?? null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        self::migrate($pdo);
        return $pdo;
    }

    private static function dsn(array $database): string
    {
        if (!empty($database['dsn'])) {
            return (string)$database['dsn'];
        }
        $type = strtolower((string)($database['type'] ?? 'mysql'));
        if ($type === 'mysqli') {
            $type = 'mysql';
        }
        if ($type !== 'mysql') {
            throw new RuntimeException('Nicht unterstuetzter Datenbanktyp: ' . $type);
        }
        $host = (string)($database['host'] ?? '127.0.0.1');
        $port = (int)($database['port'] ?? 3306);
        $name = (string)($database['database'] ?? '');
        $charset = (string)($database['charset'] ?? 'utf8mb4');
        if ($name === '') {
            throw new RuntimeException('Datenbankname fehlt in der Konfiguration.');
        }
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
    }

    private static function migrate(PDO $pdo): void
    {
        $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            $pdo->exec($statement);
        }
        self::addColumnIfMissing($pdo, 'servers', 'ssh_link_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER api_token');
        self::addColumnIfMissing($pdo, 'servers', 'ssh_port', 'INT UNSIGNED NOT NULL DEFAULT 22 AFTER ssh_link_enabled');
        self::addColumnIfMissing($pdo, 'servers', 'ssh_username', 'VARCHAR(190) NULL AFTER ssh_port');
        self::addColumnIfMissing($pdo, 'domains', 'domain_status', 'INT NULL');
        self::addColumnIfMissing($pdo, 'domains', 'is_disabled', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::addColumnIfMissing($pdo, 'domains', 'suspend_on', 'DATE NULL');
        self::addColumnIfMissing($pdo, 'domains', 'delete_on', 'DATE NULL');
        self::addColumnIfMissing($pdo, 'domains', 'billing_frequency', "ENUM('monthly', 'bimonthly', 'quarterly', 'halfyearly', 'yearly') NOT NULL DEFAULT 'yearly' AFTER next_billing_at");
        self::addColumnIfMissing($pdo, 'domains', 'last_change_at', 'DATE NULL');
        self::addColumnIfMissing($pdo, 'domains', 'domain_owner_contact', 'TEXT NULL AFTER registrar');
        self::addColumnIfMissing($pdo, 'domains', 'domain_admin_c', 'TEXT NULL AFTER domain_owner_contact');
        self::addColumnIfMissing($pdo, 'domains', 'domain_tech_c', 'TEXT NULL AFTER domain_admin_c');
        self::addColumnIfMissing($pdo, 'domains', 'domain_zone_c', 'TEXT NULL AFTER domain_tech_c');
        self::addColumnIfMissing($pdo, 'billing_tax_rates', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
        self::addColumnIfMissing($pdo, 'billing_tld_prices', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
        self::addColumnIfMissing($pdo, 'billing_user_items', 'description_text', 'TEXT NULL AFTER description');
        self::addColumnIfMissing($pdo, 'billing_user_items', 'next_billing_at', 'DATE NULL AFTER last_billed_at');
        self::addColumnIfMissing($pdo, 'billing_user_settings', 'last_invoice_at', 'DATE NULL AFTER invoice_frequency');
        self::addColumnIfMissing($pdo, 'billing_user_settings', 'next_invoice_at', 'DATE NULL AFTER last_invoice_at');
        self::addColumnIfMissing($pdo, 'invoices', 'recipient_snapshot', 'JSON NULL AFTER pdf_path');
        self::addColumnIfMissing($pdo, 'invoices', 'sender_snapshot', 'TEXT NULL AFTER recipient_snapshot');
        self::addColumnIfMissing($pdo, 'invoices', 'send_error', 'TEXT NULL AFTER sender_snapshot');
        self::addColumnIfMissing($pdo, 'invoice_items', 'billing_reference', 'VARCHAR(190) NOT NULL AFTER service_date');
        self::addIndexIfMissing($pdo, 'invoice_items', 'uniq_invoice_item_reference', 'UNIQUE KEY `uniq_invoice_item_reference` (`billing_reference`)');
        self::addColumnIfMissing($pdo, 'hosting_packages', 'external_id', 'VARCHAR(190) NULL AFTER id');
        self::addColumnIfMissing($pdo, 'hosting_packages', 'synced_at', 'DATETIME NULL AFTER server_id');
        self::dropIndexIfExists($pdo, 'hosting_packages', 'name');
        self::addIndexIfMissing($pdo, 'hosting_packages', 'uniq_server_hosting_package', 'UNIQUE KEY `uniq_server_hosting_package` (`server_id`, `external_id`)');
    }

    private static function dropIndexIfExists(PDO $pdo, string $table, string $index): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        if ((int)$stmt->fetchColumn() > 0) {
            $pdo->exec('ALTER TABLE `' . $table . '` DROP INDEX `' . $index . '`');
        }
    }

    private static function addIndexIfMissing(PDO $pdo, string $table, string $index, string $definition): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $definition);
        }
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
}
