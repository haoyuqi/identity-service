<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

final class AdministrativeAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $redisHost = env('REDIS_TEST_HOST');

        if (! is_string($redisHost) || $redisHost === '') {
            self::fail('REDIS_TEST_HOST must point to a dedicated test Redis service.');
        }

        $connection = config('database.redis.default');
        $connection['url'] = null;
        $connection['host'] = $redisHost;
        $connection['port'] = (int) env('REDIS_TEST_PORT', 6379);
        $connection['database'] = (int) env('REDIS_TEST_DATABASE', 0);
        $connection['username'] = env('REDIS_TEST_USERNAME');
        $connection['password'] = env('REDIS_TEST_PASSWORD');

        config([
            'database.redis.identity_service_auth_test' => $connection,
            'session.driver' => 'redis',
            'session.connection' => 'identity_service_auth_test',
            'session.store' => 'redis',
            'session.prefix' => 'identity-service-accounts-test:'.bin2hex(random_bytes(12)).':',
            'cache.stores.redis.connection' => 'identity_service_auth_test',
        ]);

        Redis::purge('identity_service_auth_test');
        Cache::forgetDriver('redis');
        app('session')->forgetDrivers();
    }

    public function test_active_administrator_can_browse_create_and_view_account_pages(): void
    {
        $administrator = $this->administrator();
        $cookie = $this->login($administrator);

        $this->withAdministratorSession($cookie)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Accounts')
            ->assertSee($administrator->email);

        $this->withAdministratorSession($cookie)
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('Create account')
            ->assertSee('Initial password');

        $target = User::factory()->create([
            'name' => '<script>alert(1)</script>',
            'email' => 'target@example.test',
        ]);
        AdminAuditLog::query()->create([
            'actor_id' => $administrator->id,
            'target_user_id' => $target->id,
            'action' => 'admin.account_created',
        ]);

        $show = $this->withAdministratorSession($cookie)
            ->get(route('admin.users.show', $target));

        $show->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('admin.account_created')
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_account_list_is_paginated(): void
    {
        $administrator = $this->administrator();
        User::factory()->count(26)->create();
        $cookie = $this->login($administrator);

        $this->withAdministratorSession($cookie)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('page=2');

        $lastAccount = User::query()->orderByDesc('id')->firstOrFail();

        $this->withAdministratorSession($cookie)
            ->get(route('admin.users.index', ['page' => 2]))
            ->assertOk()
            ->assertSee($lastAccount->email);
    }

    public function test_account_creation_normalizes_inputs_and_never_displays_the_password(): void
    {
        $administrator = $this->administrator();
        $cookie = $this->login($administrator);
        $password = 'account-create-test-password';

        $response = $this->withAdministratorSession($cookie)
            ->post(route('admin.users.store'), [
                'name' => '  New Account  ',
                'email' => '  NEW.ACCOUNT@Example.Test  ',
                'role' => UserRole::MEMBER->value,
                'password' => $password,
            ]);

        $created = User::query()->where('email', 'new.account@example.test')->firstOrFail();
        $response->assertRedirect(route('admin.users.show', $created))
            ->assertDontSee($password);

        self::assertSame('New Account', $created->name);
        self::assertSame(UserRole::MEMBER, $created->role);
        self::assertTrue(Hash::check($password, $created->password));
        self::assertNotSame($password, $created->getRawOriginal('password'));
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $administrator->id,
            'target_user_id' => $created->id,
            'action' => 'admin.account_created',
        ]);

        $this->withAdministratorSession($cookie)
            ->get(route('admin.users.show', $created))
            ->assertOk()
            ->assertSee('Account created.')
            ->assertDontSee($password)
            ->assertDontSee($created->getRawOriginal('password'));
    }

    public function test_malformed_fields_and_duplicate_email_return_validation_errors_without_changes(): void
    {
        $administrator = $this->administrator();
        User::factory()->create(['email' => 'duplicate@example.test']);
        $cookie = $this->login($administrator);
        $validData = [
            'name' => 'Valid Name',
            'email' => 'valid@example.test',
            'role' => UserRole::MEMBER->value,
            'password' => 'valid-password-123',
        ];

        $cases = [
            [['name' => ['array value']], 'The name field must be a string.'],
            [['email' => ['array value']], 'The email field must be a string.'],
            [['password' => ['array value']], 'The password field must be a string.'],
            [['password' => str_repeat('x', 73)], 'The password must not exceed 72 bytes.'],
            [['role' => ['admin']], 'The selected role is invalid.'],
            [['role' => 'owner'], 'The selected role is invalid.'],
            [['email' => '  DUPLICATE@Example.Test  '], 'An account with this email already exists.'],
        ];

        foreach ($cases as [$override, $errorMessage]) {
            $response = $this->withAdministratorSession($cookie)
                ->from(route('admin.users.create'))
                ->post(route('admin.users.store'), array_replace($validData, $override));

            $response->assertRedirect(route('admin.users.create'));

            $this->withAdministratorSession($cookie)
                ->get(route('admin.users.create'))
                ->assertOk()
                ->assertSee($errorMessage)
                ->assertDontSee('valid-password-123');
        }

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_successful_disable_updates_status_and_writes_audit_together(): void
    {
        $administrator = $this->administrator();
        $target = User::factory()->create();
        $cookie = $this->login($administrator);

        $response = $this->withAdministratorSession($cookie)
            ->patch(route('admin.users.disable', $target));

        $response->assertRedirect(route('admin.users.show', $target));
        self::assertNotNull($target->fresh()->disabled_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $administrator->id,
            'target_user_id' => $target->id,
            'action' => 'admin.account_disabled',
        ]);

        $this->withAdministratorSession($cookie)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSee('Account disabled.')
            ->assertSee('Disabled')
            ->assertDontSee('Disable account');
    }

    public function test_disable_business_conflict_is_returned_as_a_safe_validation_message(): void
    {
        $administrator = $this->administrator();
        $target = User::factory()->create();
        $cookie = $this->login($administrator);
        $targetId = $target->getKey();
        $removed = false;

        DB::listen(function ($query) use (&$removed, $targetId): void {
            if ($removed || ! str_contains($query->sql, 'pg_advisory_xact_lock')) {
                return;
            }

            $removed = true;
            DB::table('users')->where('id', $targetId)->delete();
        });

        $response = $this->withAdministratorSession($cookie)
            ->from(route('admin.users.show', $target))
            ->patch(route('admin.users.disable', $target));

        $response->assertRedirect(route('admin.users.show', $target))
            ->assertSessionHasErrors(['account' => 'The account no longer exists.']);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'disabled_at' => null]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_concurrent_duplicate_email_is_returned_as_validation_error(): void
    {
        $administrator = $this->administrator();
        $cookie = $this->login($administrator);
        $competitorInserted = false;
        $competitorConnection = config('database.connections.testing');
        config(['database.connections.identity_service_racer' => $competitorConnection]);
        DB::purge('identity_service_racer');

        DB::listen(function ($query) use (&$competitorInserted): void {
            if (! $competitorInserted && str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $competitorInserted = true;
                DB::connection('identity_service_racer')->table('users')->insert([
                    'name' => 'Concurrent Account',
                    'email' => 'concurrent@example.test',
                    'password' => Hash::make('concurrent-password'),
                    'role' => UserRole::MEMBER->value,
                    'created_at' => now()->utc(),
                    'updated_at' => now()->utc(),
                ]);
            }
        });

        try {
            $response = $this->withAdministratorSession($cookie)
                ->from(route('admin.users.create'))
                ->post(route('admin.users.store'), [
                    'name' => 'Concurrent Account',
                    'email' => 'concurrent@example.test',
                    'role' => UserRole::MEMBER->value,
                    'password' => 'valid-password-123',
                ]);

            $response->assertRedirect(route('admin.users.create'))
                ->assertSessionHasErrors(['email' => 'An account with this email already exists.']);
            $this->assertDatabaseHas('users', ['email' => 'concurrent@example.test']);
            $this->assertDatabaseCount('admin_audit_logs', 0);
        } finally {
            DB::connection('identity_service_racer')->table('users')->where('email', 'concurrent@example.test')->delete();
            DB::purge('identity_service_racer');
            config()->offsetUnset('database.connections.identity_service_racer');
        }
    }

    public function test_unrelated_database_constraint_failure_is_not_reported_as_duplicate_email(): void
    {
        $administrator = $this->administrator();
        $cookie = $this->login($administrator);
        $failed = false;

        DB::listen(function ($query) use (&$failed): void {
            if ($failed || ! str_contains($query->sql, 'pg_advisory_xact_lock')) {
                return;
            }

            $failed = true;
            DB::table('users')->insert([
                'name' => 'Invalid Account',
                'email' => 'NOT-CANONICAL@example.test',
                'password' => Hash::make('invalid-account-password'),
                'role' => UserRole::MEMBER->value,
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);
        });

        $this->withAdministratorSession($cookie)
            ->post(route('admin.users.store'), [
                'name' => 'Other Account',
                'email' => 'other@example.test',
                'role' => UserRole::MEMBER->value,
                'password' => 'valid-password-123',
            ])
            ->assertServerError();

        $this->assertDatabaseMissing('users', ['email' => 'other@example.test']);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_guests_members_disabled_administrators_and_self_disable_are_rejected(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));

        $member = User::factory()->create(['role' => UserRole::MEMBER]);
        $this->actingAs($member, 'web')
            ->get(route('admin.users.index'))
            ->assertRedirect(route('login'));
        Auth::forgetGuards();

        $disabledAdministrator = User::factory()->admin()->disabled()->create();
        $this->actingAs($disabledAdministrator, 'web')
            ->get(route('admin.users.create'))
            ->assertRedirect(route('login'));
        Auth::forgetGuards();

        $administrator = $this->administrator();
        $cookie = $this->login($administrator);
        $this->withAdministratorSession($cookie)
            ->patch(route('admin.users.disable', $administrator))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $administrator->id, 'disabled_at' => null]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_account_create_and_disable_routes_require_csrf_tokens(): void
    {
        $administrator = $this->administrator();
        $target = User::factory()->create();
        $cookie = $this->login($administrator);

        $this->app->instance(
            PreventRequestForgery::class,
            new class($this->app, $this->app->make(Encrypter::class)) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            },
        );

        $this->withAdministratorSession($cookie)
            ->post(route('admin.users.store'), [
                'name' => 'CSRF Rejected',
                'email' => 'csrf-rejected@example.test',
                'role' => UserRole::MEMBER->value,
                'password' => 'valid-password-123',
            ])
            ->assertStatus(419);

        $this->withAdministratorSession($cookie)
            ->patch(route('admin.users.disable', $target))
            ->assertStatus(419);

        $this->assertDatabaseMissing('users', ['email' => 'csrf-rejected@example.test']);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'disabled_at' => null]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_creation_and_disable_roll_back_when_audit_persistence_fails(): void
    {
        $administrator = $this->administrator();
        $target = User::factory()->create();
        $cookie = $this->login($administrator);

        AdminAuditLog::creating(static function (): never {
            throw new RuntimeException('audit persistence failed');
        });

        $this->withAdministratorSession($cookie)
            ->post(route('admin.users.store'), [
                'name' => 'Rolled Back',
                'email' => 'rolled-back@example.test',
                'role' => UserRole::MEMBER->value,
                'password' => 'valid-password-123',
            ])
            ->assertServerError();

        $this->assertDatabaseMissing('users', ['email' => 'rolled-back@example.test']);
        $this->assertDatabaseCount('admin_audit_logs', 0);

        $this->withAdministratorSession($cookie)
            ->patch(route('admin.users.disable', $target))
            ->assertServerError();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'disabled_at' => null]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    private function administrator(): User
    {
        return User::factory()->create([
            'role' => UserRole::ADMIN,
            'disabled_at' => null,
            'password' => 'correct-password',
        ]);
    }

    private function login(User $administrator): string
    {
        $response = $this->post(route('login.store'), [
            'email' => $administrator->email,
            'password' => 'correct-password',
        ]);
        $response->assertRedirect(route('admin.dashboard'));
        $cookie = $this->sessionCookie($response);
        $this->resetBrowserRequestState();

        return $cookie;
    }

    private function withAdministratorSession(string $cookie): static
    {
        $this->resetBrowserRequestState();

        return $this->withCookie(config('session.cookie'), $cookie);
    }

    private function sessionCookie(TestResponse $response): string
    {
        $cookie = $response->getCookie((string) config('session.cookie'));

        $this->assertNotNull($cookie, 'The response should issue an encrypted session cookie.');

        return $cookie->getValue();
    }

    private function resetBrowserRequestState(): void
    {
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        app('redirect')->setSession(app('session.store'));
        Auth::forgetGuards();
    }
}
