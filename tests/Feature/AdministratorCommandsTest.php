<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

final class AdministratorCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_commands_reject_noninteractive_invocation(): void
    {
        $this->artisan('identity:admin:bootstrap', ['--no-interaction' => true])
            ->expectsOutput('Interactive input is required.')
            ->assertExitCode(1);

        $this->artisan('identity:admin:reset-password', ['--no-interaction' => true])
            ->expectsOutput('Interactive input is required.')
            ->assertExitCode(1);
    }

    public function test_bootstrap_creates_normalized_first_administrator_and_audit(): void
    {
        $this->artisan('identity:admin:bootstrap')
            ->expectsQuestion('Name', '  Example Admin  ')
            ->expectsQuestion('Email address', ' ADMIN@Example.Test ')
            ->expectsQuestion('Password', 'a-safe-password-123')
            ->expectsQuestion('Confirm password', 'a-safe-password-123')
            ->expectsOutput('Administrator account created.')
            ->doesntExpectOutputToContain('a-safe-password-123')
            ->assertExitCode(0);

        $user = User::query()->sole();

        $this->assertSame('Example Admin', $user->name);
        $this->assertSame('admin@example.test', $user->email);
        $this->assertSame(UserRole::ADMIN, $user->role);
        $this->assertTrue(Hash::check('a-safe-password-123', $user->password));
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => null,
            'target_user_id' => $user->id,
            'action' => 'admin.bootstrap',
        ]);
    }

    public function test_bootstrap_rejects_an_existing_enabled_administrator(): void
    {
        User::factory()->admin()->create();

        $this->artisan('identity:admin:bootstrap')
            ->expectsQuestion('Name', 'Another Admin')
            ->expectsQuestion('Email address', 'another@example.test')
            ->expectsQuestion('Password', 'a-safe-password-123')
            ->expectsQuestion('Confirm password', 'a-safe-password-123')
            ->expectsOutput('An enabled administrator already exists.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_bootstrap_rejects_duplicate_email_without_partial_records(): void
    {
        User::factory()->create(['email' => 'admin@example.test']);

        $this->artisan('identity:admin:bootstrap')
            ->expectsQuestion('Name', 'Example Admin')
            ->expectsQuestion('Email address', 'admin@example.test')
            ->expectsQuestion('Password', 'a-safe-password-123')
            ->expectsQuestion('Confirm password', 'a-safe-password-123')
            ->expectsOutput('An account with this email already exists.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_bootstrap_rejects_invalid_input_and_mismatched_passwords(): void
    {
        $this->artisan('identity:admin:bootstrap')
            ->expectsQuestion('Name', 'Example Admin')
            ->expectsQuestion('Email address', 'not-an-email')
            ->expectsQuestion('Password', 'a-safe-password-123')
            ->expectsQuestion('Confirm password', 'a-safe-password-123')
            ->expectsOutput('The email field must be a valid email address.')
            ->assertExitCode(1);

        $this->artisan('identity:admin:bootstrap')
            ->expectsQuestion('Name', 'Example Admin')
            ->expectsQuestion('Email address', 'admin@example.test')
            ->expectsQuestion('Password', 'a-safe-password-123')
            ->expectsQuestion('Confirm password', 'a-different-password')
            ->expectsOutput('Password confirmation does not match.')
            ->assertExitCode(1);

        $longPassword = str_repeat('é', 37);

        $this->artisan('identity:admin:bootstrap')
            ->expectsQuestion('Name', 'Example Admin')
            ->expectsQuestion('Email address', 'admin@example.test')
            ->expectsQuestion('Password', $longPassword)
            ->expectsQuestion('Confirm password', $longPassword)
            ->expectsOutput('The password must not exceed 72 bytes.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_bootstrap_rolls_back_user_when_audit_creation_fails(): void
    {
        AdminAuditLog::creating(static function (): never {
            throw new RuntimeException('sensitive database detail');
        });

        $this->artisan('identity:admin:bootstrap')
            ->expectsQuestion('Name', 'Example Admin')
            ->expectsQuestion('Email address', 'admin@example.test')
            ->expectsQuestion('Password', 'a-safe-password-123')
            ->expectsQuestion('Confirm password', 'a-safe-password-123')
            ->expectsOutput('Administrator bootstrap failed; no changes were applied.')
            ->doesntExpectOutputToContain('sensitive database detail')
            ->doesntExpectOutputToContain('a-safe-password-123')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_bootstrap_rolls_back_user_when_audit_foreign_key_fails(): void
    {
        AdminAuditLog::creating(static function (AdminAuditLog $auditLog): void {
            $auditLog->target_user_id = 999999999;
        });

        $this->artisan('identity:admin:bootstrap')
            ->expectsQuestion('Name', 'Example Admin')
            ->expectsQuestion('Email address', 'admin@example.test')
            ->expectsQuestion('Password', 'a-safe-password-123')
            ->expectsQuestion('Confirm password', 'a-safe-password-123')
            ->expectsOutput('Administrator bootstrap failed; no changes were applied.')
            ->doesntExpectOutputToContain('foreign key constraint')
            ->doesntExpectOutputToContain('a-safe-password-123')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_password_reset_hashes_password_and_writes_audit(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.test',
            'password' => 'old-password-value',
        ]);

        $this->artisan('identity:admin:reset-password')
            ->expectsQuestion('Email address', ' USER@example.test ')
            ->expectsQuestion('New password', 'a-new-safe-password-123')
            ->expectsQuestion('Confirm password', 'a-new-safe-password-123')
            ->expectsOutput('Password reset.')
            ->doesntExpectOutputToContain('a-new-safe-password-123')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertTrue(Hash::check('a-new-safe-password-123', $user->password));
        $this->assertFalse(Hash::check('old-password-value', $user->password));
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => null,
            'target_user_id' => $user->id,
            'action' => 'admin.password_reset',
        ]);
    }

    public function test_password_reset_fails_safely_for_missing_account_and_invalid_password(): void
    {
        $this->artisan('identity:admin:reset-password')
            ->expectsQuestion('Email address', 'missing@example.test')
            ->expectsQuestion('New password', 'a-new-safe-password-123')
            ->expectsQuestion('Confirm password', 'a-new-safe-password-123')
            ->expectsOutput('No account was found for that email address.')
            ->assertExitCode(1);

        $this->artisan('identity:admin:reset-password')
            ->expectsQuestion('Email address', 'missing@example.test')
            ->expectsQuestion('New password', 'a-new-safe-password-123')
            ->expectsQuestion('Confirm password', 'a-different-password')
            ->expectsOutput('Password confirmation does not match.')
            ->assertExitCode(1);

        $this->artisan('identity:admin:reset-password')
            ->expectsQuestion('Email address', 'user@example.test')
            ->expectsQuestion('New password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertExitCode(1);

        $longPassword = str_repeat('é', 37);

        $this->artisan('identity:admin:reset-password')
            ->expectsQuestion('Email address', 'user@example.test')
            ->expectsQuestion('New password', $longPassword)
            ->expectsQuestion('Confirm password', $longPassword)
            ->expectsOutput('The password must not exceed 72 bytes.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_password_reset_rolls_back_password_when_audit_creation_fails(): void
    {
        $user = User::factory()->create(['password' => 'old-password-value']);

        AdminAuditLog::creating(static function (): never {
            throw new RuntimeException('sensitive database detail');
        });

        $this->artisan('identity:admin:reset-password')
            ->expectsQuestion('Email address', $user->email)
            ->expectsQuestion('New password', 'a-new-safe-password-123')
            ->expectsQuestion('Confirm password', 'a-new-safe-password-123')
            ->expectsOutput('Password reset failed; no changes were applied.')
            ->doesntExpectOutputToContain('sensitive database detail')
            ->doesntExpectOutputToContain('a-new-safe-password-123')
            ->assertExitCode(1);

        $user->refresh();
        $this->assertTrue(Hash::check('old-password-value', $user->password));
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
