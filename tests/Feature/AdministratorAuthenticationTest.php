<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class AdministratorAuthenticationTest extends TestCase
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
            'session.prefix' => 'identity-service-auth-test:'.bin2hex(random_bytes(12)).':',
            'cache.stores.redis.connection' => 'identity_service_auth_test',
        ]);

        Redis::purge('identity_service_auth_test');
        Cache::forgetDriver('redis');
        app('session')->forgetDrivers();
    }

    public function test_enabled_administrator_can_log_in_and_log_out_with_redis_session_cookie(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Administrator sign in');

        $user = User::factory()->create([
            'role' => UserRole::ADMIN,
            'disabled_at' => null,
            'password' => 'correct-password',
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => str_repeat('a', 73),
        ])->assertSessionHasErrors('password');

        Auth::forgetGuards();

        $this->post(route('login.store'), [
            'email' => ['not-an-email'],
            'password' => 'correct-password',
        ])->assertSessionHasErrors('email');

        Auth::forgetGuards();

        $login = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $login->assertRedirect(route('admin.dashboard'));
        $cookie = $this->sessionCookie($login);
        $sessionId = app('session.store')->getId();
        $sessionKey = config('session.prefix').$sessionId;
        $redis = Redis::connection('identity_service_auth_test');
        $this->assertTrue((bool) $redis->exists($sessionKey), 'Login should persist its session in Redis.');
        $sessionTtl = $redis->ttl($sessionKey);
        $this->assertGreaterThan(0, $sessionTtl);
        $this->assertLessThanOrEqual((int) config('session.lifetime') * 60, $sessionTtl);

        $sessionCookie = $login->getCookie((string) config('session.cookie'));

        $this->assertTrue(config('session.encrypt'));
        $this->assertNotNull($sessionCookie);
        $this->assertTrue($sessionCookie->isSecure());
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertSame('lax', $sessionCookie->getSameSite());

        $this->resetBrowserRequestState();
        $this->withCookie(config('session.cookie'), $cookie)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Administrator session is active.');

        $this->resetBrowserRequestState();
        $logout = $this->withCookie(config('session.cookie'), $cookie)
            ->post(route('admin.logout'));
        $logout->assertRedirect(route('login'));
        $this->assertFalse((bool) $redis->exists($sessionKey), 'Logout should remove the persisted session from Redis.');

        $this->resetBrowserRequestState();
        $this->withCookie(config('session.cookie'), $cookie)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_login_normalizes_email_and_rejects_passwords_longer_than_bcrypt_limit(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::ADMIN,
            'disabled_at' => null,
            'password' => 'correct-password',
        ]);

        $login = $this->post(route('login.store'), [
            'email' => '  '.mb_strtoupper($user->email).'  ',
            'password' => 'correct-password',
        ]);
        $login->assertRedirect(route('admin.dashboard'));
        $cookie = $this->sessionCookie($login);

        $this->resetBrowserRequestState();
        $this->withCookie(config('session.cookie'), $cookie)
            ->post(route('admin.logout'))
            ->assertRedirect(route('login'));

    }

    public function test_members_disabled_accounts_and_wrong_passwords_receive_the_same_failure(): void
    {
        $member = User::factory()->create([
            'role' => UserRole::MEMBER,
            'disabled_at' => null,
            'password' => 'correct-password',
        ]);
        $disabled = User::factory()->create([
            'role' => UserRole::ADMIN,
            'disabled_at' => now(),
            'password' => 'correct-password',
        ]);
        $administrator = User::factory()->create([
            'role' => UserRole::ADMIN,
            'disabled_at' => null,
            'password' => 'correct-password',
        ]);

        foreach ([
            [$member->email, 'correct-password'],
            [$disabled->email, 'correct-password'],
            [$administrator->email, 'wrong-password'],
            ['missing@example.test', 'wrong-password'],
        ] as [$email, $password]) {
            $this->post(route('login.store'), compact('email', 'password'))
                ->assertSessionHasErrors(['email' => 'The provided credentials are invalid.']);

            Auth::forgetGuards();
        }
    }

    public function test_password_reset_before_first_protected_request_invalidates_new_login_session(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::ADMIN,
            'disabled_at' => null,
            'password' => 'old-password',
        ]);

        $login = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'old-password',
        ]);
        $cookie = $this->sessionCookie($login);

        app(UserManagementService::class)->resetPassword($user->email, 'new-password');

        $this->resetBrowserRequestState();
        $this->withCookie(config('session.cookie'), $cookie)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));

        $this->resetBrowserRequestState();
        $this->withCookie(config('session.cookie'), $cookie)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_session_without_password_marker_is_rejected(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::ADMIN,
            'disabled_at' => null,
        ]);

        $this->actingAs($user, 'web')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_guest_cannot_open_the_administrator_dashboard(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_administrator_visiting_login_page_is_sent_to_dashboard(): void
    {
        $administrator = User::factory()->create([
            'role' => UserRole::ADMIN,
            'disabled_at' => null,
            'password' => 'correct-password',
        ]);

        $login = $this->post(route('login.store'), [
            'email' => $administrator->email,
            'password' => 'correct-password',
        ]);
        $cookie = $this->sessionCookie($login);

        $this->resetBrowserRequestState();
        $this->withCookie(config('session.cookie'), $cookie)
            ->get(route('login'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_member_and_newly_disabled_admin_sessions_are_rejected(): void
    {
        $member = User::factory()->create(['role' => UserRole::MEMBER]);

        $this->actingAs($member, 'web')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));

        Auth::forgetGuards();

        $administrator = User::factory()->create([
            'role' => UserRole::ADMIN,
            'disabled_at' => null,
            'password' => 'correct-password',
        ]);
        $login = $this->post(route('login.store'), [
            'email' => $administrator->email,
            'password' => 'correct-password',
        ]);
        $cookie = $this->sessionCookie($login);
        $otherAdministrator = User::factory()->admin()->create();
        app(UserManagementService::class)->disableAccount($administrator, $otherAdministrator);

        $this->resetBrowserRequestState();
        $this->withCookie(config('session.cookie'), $cookie)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => 'unknown@example.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');

            Auth::forgetGuards();
        }

        $this->post(route('login.store'), [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_logout_requires_a_valid_csrf_token(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::ADMIN,
            'disabled_at' => null,
            'password' => 'correct-password',
        ]);
        $login = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
        $cookie = $this->sessionCookie($login);

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

        $this->resetBrowserRequestState();
        $this->withCookie(config('session.cookie'), $cookie)
            ->post(route('admin.logout'))
            ->assertStatus(419);

        $this->resetBrowserRequestState();
        $this->withCookie(config('session.cookie'), $cookie)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_redis_session_failure_returns_a_generic_service_unavailable_response(): void
    {
        $connection = config('database.redis.identity_service_auth_test');
        $connection['host'] = '127.0.0.1';
        $connection['port'] = 1;
        $connection['url'] = null;
        $connection['password'] = null;
        $connection['max_retries'] = 0;

        config(['database.redis.identity_service_auth_test' => $connection]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        Cache::forgetDriver('redis');
        app('session')->forgetDrivers();

        $this->assertSame('redis', config('session.driver'));
        $this->assertSame('identity_service_auth_test', config('session.connection'));
        $this->assertSame('identity_service_auth_test', config('cache.stores.redis.connection'));
        $this->assertSame('127.0.0.1', config('database.redis.identity_service_auth_test.host'));
        $this->assertSame(1, config('database.redis.identity_service_auth_test.port'));

        $this->withCookie(config('session.cookie'), bin2hex(random_bytes(20)))
            ->get(route('login'))
            ->assertStatus(503)
            ->assertSeeText('Service temporarily unavailable.')
            ->assertDontSeeText('127.0.0.1');
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
        Auth::forgetGuards();
    }
}
