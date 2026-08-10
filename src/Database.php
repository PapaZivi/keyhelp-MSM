<?php
class Database
{
    public static function connect(array $config): PDO
    {
        $database = $config['database'] ?? [];
        $pdo = new PDO(
            static::dsn($database),
            $database['user'] ?? null,
            $database['password'] ?? null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        static::migrate($pdo);
        return $pdo;
    }

    protected static function dsn(array $database): string
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

    protected static function migrate(PDO $pdo): void
    {
        $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            $pdo->exec($statement);
        }
    }
}
