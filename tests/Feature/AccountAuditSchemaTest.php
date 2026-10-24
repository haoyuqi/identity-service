<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AccountAuditSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_noncanonical_email_and_unknown_roles(): void
    {
        User::factory()->create(['email' => 'admin@example.test']);

        $this->assertInsertIsRejected($this->userRow(' Admin@Example.test '));
        $this->assertInsertIsRejected($this->userRow('member@example.test', 'owner'));
    }

    public function test_database_rejects_unknown_audit_actions_and_allows_known_ones(): void
    {
        $target = User::factory()->create();

        foreach (AuditAction::cases() as $action) {
            DB::table('admin_audit_logs')->insert([
                'actor_id' => null,
                'target_user_id' => $target->id,
                'action' => $action->value,
            ]);
        }

        $this->assertDatabaseCount('admin_audit_logs', count(AuditAction::cases()));

        try {
            DB::transaction(static function () use ($target): void {
                DB::table('admin_audit_logs')->insert([
                    'actor_id' => null,
                    'target_user_id' => $target->id,
                    'action' => 'admin.unknown_action',
                ]);
            });
            self::fail('An unknown audit action must be rejected by the database.');
        } catch (QueryException) {
            $this->assertDatabaseCount('admin_audit_logs', count(AuditAction::cases()));
        }
    }

    public function test_schema_uses_timezone_aware_columns_and_account_indexes(): void
    {
        $userTimestampTypes = DB::select(<<<'SQL'
            select column_name, data_type
            from information_schema.columns
            where table_schema = 'public'
              and table_name = 'users'
              and column_name in ('created_at', 'updated_at', 'email_verified_at', 'disabled_at')
            SQL);

        $this->assertCount(4, $userTimestampTypes);
        foreach ($userTimestampTypes as $column) {
            $this->assertSame('timestamp with time zone', $column->data_type);
        }

        $auditCreatedAtType = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'admin_audit_logs')
            ->where('column_name', 'created_at')
            ->value('data_type');
        $this->assertSame('timestamp with time zone', $auditCreatedAtType);

        $indexes = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->whereIn('indexname', [
                'users_role_index',
                'users_disabled_at_index',
                'admin_audit_logs_target_user_id_created_at_index',
            ])
            ->count();

        $this->assertSame(3, $indexes);
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
    }

    public function test_upgrade_normalizes_existing_email_and_preserves_utc_timestamp_instants(): void
    {
        $migration = require database_path('migrations/2026_10_23_000001_add_account_status_and_admin_audit_logs.php');
        $migration->down();

        DB::table('users')->insert($this->legacyUserRow(
            '  Legacy@Example.test  ',
            '2026-10-20 12:30:00',
        ));

        $migration->up();

        $this->assertDatabaseHas('users', ['email' => 'legacy@example.test']);
        $createdAt = DB::selectOne(
            "select created_at at time zone 'UTC' as value from users where email = ?",
            ['legacy@example.test'],
        );
        $verifiedAt = DB::selectOne(
            "select email_verified_at at time zone 'UTC' as value from users where email = ?",
            ['legacy@example.test'],
        );

        $this->assertSame('2026-10-20 12:30:00', $createdAt->value);
        $this->assertSame('2026-10-20 12:30:00', $verifiedAt->value);
        $this->assertDatabaseHas('users', ['email' => 'legacy@example.test', 'role' => 'member']);
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
    }

    public function test_upgrade_rejects_existing_emails_that_normalize_to_the_same_value(): void
    {
        $migration = require database_path('migrations/2026_10_23_000001_add_account_status_and_admin_audit_logs.php');
        $migration->down();

        DB::table('users')->insert([
            $this->legacyUserRow('Admin@Example.test'),
            $this->legacyUserRow(' admin@example.test '),
        ]);

        $this->expectException(QueryException::class);
        $migration->up();
    }

    public function test_audit_action_constraint_migration_rolls_back_and_reapplies(): void
    {
        $migration = require database_path('migrations/2026_10_24_000001_constrain_admin_audit_actions.php');
        $target = User::factory()->create();

        $migration->down();
        $this->assertFalse($this->auditActionConstraintExists());

        // Without the constraint the database accepts any action value.
        DB::table('admin_audit_logs')->insert([
            'actor_id' => null,
            'target_user_id' => $target->id,
            'action' => 'admin.unknown_action',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'admin.unknown_action']);
        DB::table('admin_audit_logs')->delete();

        $migration->up();
        $this->assertTrue($this->auditActionConstraintExists());

        try {
            DB::transaction(static function () use ($target): void {
                DB::table('admin_audit_logs')->insert([
                    'actor_id' => null,
                    'target_user_id' => $target->id,
                    'action' => 'admin.unknown_action',
                ]);
            });
            self::fail('Reapplying the migration must restore the constraint.');
        } catch (QueryException) {
            $this->assertDatabaseCount('admin_audit_logs', 0);
        }
    }

    public function test_rollback_removes_new_schema_and_preserves_existing_account_data(): void
    {
        $user = User::factory()->create(['email' => 'kept@example.test']);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'synthetic-token',
            'created_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_23_000001_add_account_status_and_admin_audit_logs.php');
        $migration->down();

        $this->assertFalse(Schema::hasTable('admin_audit_logs'));
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertFalse(Schema::hasColumn('users', 'disabled_at'));
        $this->assertDatabaseHas('users', ['email' => 'kept@example.test']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'kept@example.test']);

        $migration->up();
    }

    private function auditActionConstraintExists(): bool
    {
        return DB::table('pg_constraint')
            ->where('conname', 'admin_audit_logs_action_check')
            ->exists();
    }

    private function userRow(string $email, string $role = 'member'): array
    {
        return [
            'name' => 'Test Account',
            'email' => $email,
            'password' => 'not-a-real-password-hash',
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function assertInsertIsRejected(array $row): void
    {
        try {
            DB::transaction(static function () use ($row): void {
                DB::table('users')->insert($row);
            });
            self::fail('A database constraint accepted an invalid account row.');
        } catch (QueryException) {
            $this->assertDatabaseCount('users', 1);
        }
    }

    private function legacyUserRow(string $email, string $createdAt = '2026-10-20 12:30:00'): array
    {
        return [
            'name' => 'Legacy Account',
            'email' => $email,
            'email_verified_at' => $createdAt,
            'password' => 'not-a-real-password-hash',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }
}
