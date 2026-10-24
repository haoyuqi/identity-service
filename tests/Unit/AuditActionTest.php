<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AuditAction;
use PHPUnit\Framework\TestCase;

final class AuditActionTest extends TestCase
{
    public function test_every_action_uses_the_administrative_prefix(): void
    {
        foreach (AuditAction::cases() as $action) {
            self::assertStringStartsWith('admin.', $action->value);
            self::assertLessThanOrEqual(100, strlen($action->value));
        }
    }

    public function test_action_values_match_the_database_constraint(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_10_24_000001_constrain_admin_audit_actions.php',
        );

        self::assertIsString($migration);

        foreach (AuditAction::cases() as $action) {
            self::assertStringContainsString("'{$action->value}'", $migration);
        }
    }
}
