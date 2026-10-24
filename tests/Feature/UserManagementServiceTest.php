<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\UserManagementService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class UserManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_administrator_can_create_normalized_member_and_admin_accounts(): void
    {
        $actor = User::factory()->admin()->create();
        $service = new UserManagementService;

        $member = $service->createAccount(
            '  New Member  ',
            ' MEMBER@Example.Test ',
            UserRole::MEMBER,
            'member-password-123',
            $actor,
        );
        $administrator = $service->createAccount(
            'New Admin',
            'new-admin@example.test',
            UserRole::ADMIN,
            'admin-password-123',
            $actor,
        );

        self::assertSame('New Member', $member->name);
        self::assertSame('member@example.test', $member->email);
        self::assertSame(UserRole::MEMBER, $member->role);
        self::assertTrue(Hash::check('member-password-123', $member->password));
        self::assertNotSame('member-password-123', $member->getRawOriginal('password'));
        self::assertSame(UserRole::ADMIN, $administrator->role);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $actor->id,
            'target_user_id' => $member->id,
            'action' => 'admin.account_created',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $actor->id,
            'target_user_id' => $administrator->id,
            'action' => 'admin.account_created',
        ]);
    }

    public function test_creation_rejects_duplicate_email_and_invalid_or_oversized_password(): void
    {
        $actor = User::factory()->admin()->create();
        User::factory()->create(['email' => 'existing@example.test']);
        $service = new UserManagementService;

        try {
            $service->createAccount(
                'Duplicate',
                'existing@example.test',
                UserRole::MEMBER,
                'a-valid-password-123',
                $actor,
            );
            self::fail('Duplicate email should fail validation.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('email', $exception->errors());
        }

        foreach (['short', str_repeat('é', 37)] as $password) {
            try {
                $service->createAccount(
                    'Invalid',
                    'invalid@example.test',
                    UserRole::MEMBER,
                    $password,
                    $actor,
                );
                self::fail('Invalid password should fail validation.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('password', $exception->errors());
            }
        }

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_the_service_refuses_a_non_postgresql_connection(): void
    {
        config(['database.connections.non_postgres' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => 'non_postgres']);
        DB::purge('non_postgres');

        try {
            new UserManagementService;
            self::fail('A non-PostgreSQL connection must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('The account service requires a PostgreSQL connection.', $exception->getMessage());
        } finally {
            config(['database.default' => 'testing']);
            DB::purge('non_postgres');
            config()->offsetUnset('database.connections.non_postgres');
        }
    }

    public function test_creation_hashes_a_raw_password_that_looks_like_a_bcrypt_hash(): void
    {
        $actor = User::factory()->admin()->create();
        $rawPassword = Hash::make('underlying-password');

        $user = (new UserManagementService)->createAccount(
            'Hash-Looking Password',
            'hash-looking-password@example.test',
            UserRole::MEMBER,
            $rawPassword,
            $actor,
        );

        self::assertTrue(Hash::check($rawPassword, $user->getRawOriginal('password')));
        self::assertNotSame($rawPassword, $user->getRawOriginal('password'));
    }

    public function test_creation_rechecks_actor_role_and_enabled_status_inside_transaction(): void
    {
        $member = User::factory()->create();
        $disabledAdmin = User::factory()->admin()->disabled()->create();
        $actor = User::factory()->admin()->create();
        User::query()->whereKey($actor->id)->update(['disabled_at' => now()->utc()]);
        $service = new UserManagementService;

        foreach ([$member, $disabledAdmin, $actor] as $inactiveActor) {
            try {
                $service->createAccount(
                    'Rejected',
                    'rejected-'.$inactiveActor->id.'@example.test',
                    UserRole::MEMBER,
                    'valid-password-123',
                    $inactiveActor,
                );
                self::fail('Inactive and non-administrator actors must be rejected.');
            } catch (AuthorizationException $exception) {
                self::assertSame('An enabled administrator is required.', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_creation_rolls_back_account_when_audit_insert_fails(): void
    {
        $actor = User::factory()->admin()->create();
        AdminAuditLog::creating(static function (): never {
            throw new RuntimeException('sensitive audit failure');
        });

        try {
            (new UserManagementService)->createAccount(
                'No Partial Account',
                'no-partial@example.test',
                UserRole::MEMBER,
                'valid-password-123',
                $actor,
            );
            self::fail('An audit failure should abort account creation.');
        } catch (RuntimeException $exception) {
            self::assertSame('sensitive audit failure', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'no-partial@example.test']);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_member_can_be_disabled_with_actor_audit_and_timestamp(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->create();

        $disabled = (new UserManagementService)->disableAccount($target, $actor);

        self::assertNotNull($disabled->disabled_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $actor->id,
            'target_user_id' => $target->id,
            'action' => 'admin.account_disabled',
        ]);
    }

    public function test_last_enabled_administrator_cannot_be_disabled(): void
    {
        $administrator = User::factory()->admin()->create();

        try {
            (new UserManagementService)->disableAccount($administrator, $administrator);
            self::fail('The last enabled administrator must remain active.');
        } catch (DomainException $exception) {
            self::assertSame('The last enabled administrator cannot be disabled.', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $administrator->id, 'disabled_at' => null]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_account_mutations_hold_the_shared_advisory_lock_until_commit(): void
    {
        $actor = User::factory()->admin()->create();
        config()->set('database.connections.lock_probe', config('database.connections.testing'));
        DB::purge('lock_probe');
        $probeConnection = DB::connection('lock_probe');
        $lockWasHeld = false;

        DB::listen(static function (QueryExecuted $query) use ($probeConnection, &$lockWasHeld): void {
            if ($query->sql !== 'select pg_advisory_xact_lock(?)') {
                return;
            }

            $result = $probeConnection->selectOne(
                'select case when pg_try_advisory_xact_lock(?) then 1 else 0 end as acquired',
                [UserManagementService::ADMINISTRATOR_STATE_LOCK_KEY],
            );
            $lockWasHeld = (int) $result->acquired === 0;
        });

        (new UserManagementService)->createAccount(
            'Lock Test',
            'lock-test@example.test',
            UserRole::MEMBER,
            'valid-password-123',
            $actor,
        );

        self::assertTrue($lockWasHeld);

        DB::purge('lock_probe');
        config()->set('database.connections.lock_probe', null);
    }

    public function test_two_enabled_administrators_can_disable_one_without_removing_the_last(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->admin()->create();

        (new UserManagementService)->disableAccount($target, $actor);

        $this->assertDatabaseHas('users', ['id' => $actor->id, 'disabled_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
        self::assertNotNull($target->fresh()->disabled_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $actor->id,
            'target_user_id' => $target->id,
            'action' => 'admin.account_disabled',
        ]);
    }

    public function test_service_rejects_self_disable_disabled_target_and_inactive_actor(): void
    {
        $actor = User::factory()->admin()->create();
        User::factory()->admin()->create();
        $disabledTarget = User::factory()->disabled()->create();
        $service = new UserManagementService;

        try {
            $service->disableAccount($actor, $actor);
            self::fail('Administrators must not disable themselves when another admin remains.');
        } catch (DomainException $exception) {
            self::assertSame('Administrators cannot disable their own account.', $exception->getMessage());
        }

        try {
            $service->disableAccount($disabledTarget, $actor);
            self::fail('Already disabled accounts must be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('The account is already disabled.', $exception->getMessage());
        }

        User::query()->whereKey($actor->id)->update(['disabled_at' => now()->utc()]);

        try {
            $service->disableAccount(User::factory()->create(), $actor);
            self::fail('A disabled actor must be rejected.');
        } catch (AuthorizationException $exception) {
            self::assertSame('An enabled administrator is required.', $exception->getMessage());
        }

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_disable_rolls_back_status_when_audit_insert_fails(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->create();
        AdminAuditLog::creating(static function (): never {
            throw new RuntimeException('sensitive audit failure');
        });

        try {
            (new UserManagementService)->disableAccount($target, $actor);
            self::fail('An audit failure should abort disabling the account.');
        } catch (RuntimeException $exception) {
            self::assertSame('sensitive audit failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $target->id, 'disabled_at' => null]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
