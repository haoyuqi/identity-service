<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

final class TestDatabaseGuardTest extends TestCase
{
    public function test_it_accepts_the_isolated_test_database_configuration_without_connecting(): void
    {
        $this->expectNotToPerformAssertions();

        TestDatabaseGuard::assertSafeConfiguration('testing', 'testing', self::safeConfiguration());
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_it_rejects_unsafe_configuration_before_opening_a_database_connection(
        string $environment,
        string $defaultConnection,
        array $connection,
    ): void {
        try {
            TestDatabaseGuard::ensureSafeDatabase($environment, $defaultConnection, $connection);
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Tests require the isolated PostgreSQL 18 test connection.',
                $exception->getMessage(),
                'The guard must reject configuration before PDO attempts a connection.',
            );

            return;
        }

        self::fail('Unsafe test database configuration was accepted.');
    }

    public static function unsafeConfigurations(): array
    {
        $configuration = self::safeConfiguration();

        return [
            'wrong environment' => ['production', 'testing', $configuration],
            'wrong default connection' => ['testing', 'pgsql', $configuration],
            'wrong driver' => ['testing', 'testing', array_replace($configuration, ['driver' => 'sqlite'])],
            'database URL provided' => ['testing', 'testing', array_replace($configuration, ['url' => 'pgsql://test.invalid/db'])],
            'unapproved host' => ['testing', 'testing', array_replace($configuration, ['host' => 'database.test.invalid'])],
            'wrong port' => ['testing', 'testing', array_replace($configuration, ['port' => '5433'])],
            'wrong database name' => ['testing', 'testing', array_replace($configuration, ['database' => 'application'])],
            'wrong role' => ['testing', 'testing', array_replace($configuration, ['username' => 'application'])],
        ];
    }

    private static function safeConfiguration(): array
    {
        return [
            'driver' => 'pgsql',
            'url' => null,
            'host' => 'postgres',
            'port' => '5432',
            'database' => 'identity_service_test',
            'username' => 'identity_service_test',
            'password' => 'synthetic-test-credential',
        ];
    }
}
