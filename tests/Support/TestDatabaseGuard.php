<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class TestDatabaseGuard
{
    private const DATABASE = 'identity_service_test';

    private const HOSTS = ['postgres', '127.0.0.1'];

    private const ROLE = 'identity_service_test';

    public static function ensureSafeDatabase(string $environment, string $defaultConnection, array $connection): void
    {
        self::assertSafeConfiguration($environment, $defaultConnection, $connection);

        $maintenanceConnection = self::connect($connection, 'postgres');
        $server = $maintenanceConnection->query(
            'select current_database() as database_name, current_user as role_name, current_setting(\'server_version_num\') as version_number'
        )->fetch(PDO::FETCH_ASSOC);

        if ($server['database_name'] !== 'postgres'
            || $server['role_name'] !== self::ROLE
            || (int) $server['version_number'] < 180000
            || (int) $server['version_number'] >= 190000) {
            throw new RuntimeException('Tests require the isolated PostgreSQL 18 test connection.');
        }

        $databaseQuery = $maintenanceConnection->prepare(
            'select role_name.rolname as owner_name from pg_database database_name join pg_roles role_name on role_name.oid = database_name.datdba where database_name.datname = :database'
        );
        $databaseQuery->execute(['database' => self::DATABASE]);
        $database = $databaseQuery->fetch(PDO::FETCH_ASSOC);

        if ($database === false) {
            $maintenanceConnection->exec('create database identity_service_test owner identity_service_test');
        } elseif ($database['owner_name'] !== self::ROLE) {
            throw new RuntimeException('The isolated test database must be owned by its dedicated test role.');
        }

        $testConnection = self::connect($connection, self::DATABASE);
        $identity = $testConnection->query(
            'select current_database() as database_name, current_user as role_name, current_setting(\'server_version_num\') as version_number'
        )->fetch(PDO::FETCH_ASSOC);

        if ($identity['database_name'] !== self::DATABASE
            || $identity['role_name'] !== self::ROLE
            || (int) $identity['version_number'] < 180000
            || (int) $identity['version_number'] >= 190000) {
            throw new RuntimeException('Tests connected outside the isolated PostgreSQL 18 test database.');
        }
    }

    public static function assertSafeConfiguration(string $environment, string $defaultConnection, array $connection): void
    {
        if ($environment !== 'testing'
            || $defaultConnection !== 'testing'
            || ($connection['driver'] ?? null) !== 'pgsql'
            || in_array($connection['url'] ?? null, [null, ''], true) === false
            || ! in_array($connection['host'] ?? null, self::HOSTS, true)
            || (string) ($connection['port'] ?? '') !== '5432'
            || ($connection['database'] ?? null) !== self::DATABASE
            || ($connection['username'] ?? null) !== self::ROLE) {
            throw new RuntimeException('Tests require the isolated PostgreSQL 18 test connection.');
        }
    }

    private static function connect(array $connection, string $database): PDO
    {
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=5432;dbname=%s', $connection['host'], $database),
            $connection['username'],
            $connection['password'] ?? null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return $pdo;
    }
}
