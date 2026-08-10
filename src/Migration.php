<?php
final class Migration extends Database
{
    protected static function migrate(PDO $pdo): void
    {
        parent::migrate($pdo);
        static::applyMigrations($pdo);
    }

    private static function applyMigrations(PDO $pdo): void
    {
        // Release baseline: schema.sql is the complete initial schema.
    }
}
