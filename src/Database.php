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
        self::addColumnIfMissing($pdo, 'domains', 'domain_status', 'INT NULL');
        self::addColumnIfMissing($pdo, 'domains', 'is_disabled', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::addColumnIfMissing($pdo, 'domains', 'delete_on', 'DATE NULL');
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