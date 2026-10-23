<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits(): array
    {
        $this->assertSafeTestDatabase();

        return parent::setUpTraits();
    }

    private function assertSafeTestDatabase(): void
    {
        $connection = config('database.connections.testing');

        TestDatabaseGuard::ensureSafeDatabase(
            app()->environment(),
            (string) config('database.default'),
            is_array($connection) ? $connection : [],
        );
    }
}
