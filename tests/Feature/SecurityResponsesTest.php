<?php

namespace Tests\Feature;

use App\Support\SafeExceptionHandler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mockery;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

final class SecurityResponsesTest extends TestCase
{
    public function test_security_headers_cover_successful_and_sensitive_route_responses(): void
    {
        Route::get('/security/health', static fn () => response('ok'));
        Route::get('/admin/security-home', static fn () => response('private'))->name('admin.security-home');
        Route::get('/security/login-alias', static fn () => response('login'))->name('login');

        $this->assertSecurityHeaders($this->get('/security/health'));
        $this->assertSecurityHeaders($this->get('/admin/security-home'), true);
        $this->assertSecurityHeaders($this->get('/security/login-alias'), true);
    }

    public function test_expected_http_errors_and_redirects_keep_their_status_and_security_headers(): void
    {
        $this->registerExpectedErrorRoutes();
        $this->useProductionEnvironment();

        $this->assertSecurityHeaders(
            $this->withHeader('Accept', 'application/json')->get('/security/unauthenticated')->assertUnauthorized(),
        );
        $this->assertSecurityHeaders($this->get('/security/forbidden')->assertForbidden());
        $this->assertSecurityHeaders($this->get('/security/missing')->assertNotFound());
        $this->assertSecurityHeaders($this->get('/security/csrf')->assertStatus(419));
        $this->assertSecurityHeaders(
            $this->withHeader('Accept', 'application/json')->get('/security/invalid')->assertUnprocessable(),
        );
        $this->assertSecurityHeaders($this->get('/security/throttled')->assertStatus(429));
        $this->assertSecurityHeaders($this->get('/security/redirect')->assertRedirect('/admin/login'));
    }

    public function test_security_headers_include_cross_domain_policy_and_hsts_only_over_https(): void
    {
        Route::get('/security/plain', static fn () => response('plain'));
        Route::get('/security/secure', static fn () => response('secure'));

        $plain = $this->get('/security/plain');
        $plain->assertHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->assertHeaderMissing('Strict-Transport-Security');

        $secure = $this->get('https://localhost/security/secure');
        $secure->assertHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_production_errors_are_generic_even_when_debug_is_enabled(): void
    {
        Route::match(['get', 'post'], '/admin/security-failure/{account}', static function (): never {
            throw new RuntimeException('SQL password_marker token_marker cookie_marker');
        });

        $this->useProductionEnvironment();

        foreach ([false, true] as $debug) {
            config(['app.debug' => $debug]);

            $response = $this->withHeaders([
                'Accept' => 'text/html',
                'Authorization' => 'Bearer authorization_marker',
                'Cookie' => 'session=session_cookie_marker',
            ])->post('/admin/security-failure/account_marker?token=query_token_marker', [
                'password' => 'request_password_marker',
            ]);

            $response->assertStatus(500)
                ->assertSeeText('Server error.')
                ->assertDontSeeText('SQL password_marker token_marker cookie_marker')
                ->assertDontSeeText('account_marker')
                ->assertDontSeeText('query_token_marker')
                ->assertDontSeeText('authorization_marker')
                ->assertDontSeeText('session_cookie_marker')
                ->assertDontSeeText('request_password_marker');
            $this->assertSecurityHeaders($response, true);
        }
    }

    public function test_http_exception_messages_are_hidden_and_safe_headers_are_preserved(): void
    {
        Route::get('/security/http-forbidden', static function (): never {
            abort(403, 'forbidden_secret_marker');
        });
        Route::get('/security/http-server-error', static function (): never {
            abort(500, 'database_password_secret_marker');
        });
        Route::get('/security/http-throttled', static function (): never {
            throw new TooManyRequestsHttpException(37, 'throttle_secret_marker');
        });

        $this->useProductionEnvironment();
        config(['app.debug' => true]);

        foreach ([
            ['/security/http-forbidden', 403, 'forbidden_secret_marker'],
            ['/security/http-server-error', 500, 'database_password_secret_marker'],
            ['/security/http-throttled', 429, 'throttle_secret_marker'],
        ] as [$path, $status, $secretMarker]) {
            $response = $this->get($path)
                ->assertStatus($status)
                ->assertDontSeeText($secretMarker);

            $this->assertSecurityHeaders($response);
        }

        $this->get('/security/http-throttled')
            ->assertHeader('Retry-After', '37');
    }

    public function test_exception_responses_get_security_headers_when_failure_precedes_global_middleware(): void
    {
        $this->useProductionEnvironment();
        $request = Request::create('/admin/early-exception', 'GET');
        $this->app->instance('request', $request);

        $response = app(ExceptionHandlerContract::class)->render(
            $request,
            new HttpException(413, 'early_failure_secret_marker'),
        );

        $this->assertSecurityHeaders(
            TestResponse::fromBaseResponse($response)
                ->assertStatus(413)
                ->assertDontSeeText('early_failure_secret_marker'),
            true,
        );
    }

    public function test_redis_failures_return_generic_503_responses(): void
    {
        Route::get('/admin/security-redis-text', static function (): never {
            throw new RuntimeException('wrapper secret', 0, new \RedisException('redis endpoint secret'));
        });
        Route::get('/security-redis-json', static function (): never {
            throw new \RedisException('redis endpoint secret');
        });

        $this->useProductionEnvironment();

        $this->get('/admin/security-redis-text')
            ->assertStatus(503)
            ->assertSeeText('Service temporarily unavailable.')
            ->assertDontSeeText('redis endpoint secret')
            ->assertDontSeeText('wrapper secret');

        $this->withHeader('Accept', 'application/json')
            ->get('/security-redis-json')
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Service temporarily unavailable.']);

        app()->detectEnvironment(static fn (): string => 'local');
        config(['app.debug' => true]);

        $this->get('/admin/security-redis-text')
            ->assertStatus(503)
            ->assertSeeText('Service temporarily unavailable.')
            ->assertDontSeeText('redis endpoint secret');
    }

    public function test_error_logs_contain_only_safe_exception_and_route_metadata(): void
    {
        Log::spy();

        Route::get('/security-log/{account}', static function (): never {
            $driverException = new PDOException('database_detail_marker', 73);
            $driverException->errorInfo = ['23505', 73, 'key value violates unique constraint'];

            throw new QueryException(
                'pgsql',
                'select * from users where password = request_password_marker',
                ['password_hash_marker', 'binding_token_marker'],
                $driverException,
            );
        });

        $this->useProductionEnvironment();
        $this->withHeaders([
            'Authorization' => 'Bearer authorization_marker',
            'Cookie' => 'session=session_cookie_marker',
        ])->get('/security-log/account_marker?token=query_token_marker')
            ->assertStatus(500)
            ->assertDontSeeText('request_password_marker')
            ->assertDontSeeText('password_hash_marker')
            ->assertDontSeeText('binding_token_marker')
            ->assertDontSeeText('database_detail_marker');

        Log::shouldHaveReceived('error')->once()->with(
            'HTTP request failed.',
            Mockery::on(function (array $context): bool {
                $this->assertSame(QueryException::class, $context['exception_class']);
                $this->assertSame(73, $context['exception_code']);
                $this->assertSame('security-log/{account}', $context['route']);
                $this->assertSame([QueryException::class, PDOException::class], $context['exception_chain']);
                $this->assertSame('23505', $context['sqlstate']);

                $serializedContext = json_encode($context, JSON_THROW_ON_ERROR);

                foreach ([
                    'account_marker',
                    'query_token_marker',
                    'authorization_marker',
                    'session_cookie_marker',
                    'request_password_marker',
                    'password_hash_marker',
                    'binding_token_marker',
                    'database_detail_marker',
                ] as $secretMarker) {
                    $this->assertStringNotContainsString($secretMarker, $serializedContext);
                }

                return true;
            }),
        );
    }

    public function test_driver_level_failures_report_their_sqlstate_without_messages(): void
    {
        Log::spy();

        Route::get('/security-driver-failure', static function (): never {
            $driverException = new PDOException('driver_detail_marker', 0);
            $driverException->errorInfo = ['08006', 0, 'connection_failure_marker'];

            throw $driverException;
        });

        $this->useProductionEnvironment();
        $this->get('/security-driver-failure')
            ->assertStatus(500)
            ->assertDontSeeText('driver_detail_marker');

        Log::shouldHaveReceived('error')->once()->with(
            'HTTP request failed.',
            Mockery::on(function (array $context): bool {
                $this->assertSame(PDOException::class, $context['exception_class']);
                $this->assertSame('08006', $context['sqlstate']);

                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                foreach (['driver_detail_marker', 'connection_failure_marker'] as $marker) {
                    $this->assertStringNotContainsString($marker, $serialized);
                }

                return true;
            }),
        );
    }

    public function test_report_omits_request_path_when_no_route_is_matched(): void
    {
        Log::spy();

        app(SafeExceptionHandler::class)->report(new RuntimeException('private detail', 19));

        Log::shouldHaveReceived('error')->once()->with(
            'HTTP request failed.',
            Mockery::on(function (array $context): bool {
                $this->assertSame(RuntimeException::class, $context['exception_class']);
                $this->assertSame(19, $context['exception_code']);
                $this->assertArrayNotHasKey('route', $context);
                $this->assertArrayNotHasKey('exception', $context);
                $this->assertArrayNotHasKey('message', $context);

                return true;
            }),
        );
    }

    private function registerExpectedErrorRoutes(): void
    {
        Route::get('/security/unauthenticated', static function (): never {
            throw new AuthenticationException;
        });
        Route::get('/security/forbidden', static function (): never {
            throw new AuthorizationException;
        });
        Route::get('/security/csrf', static function (): never {
            throw new TokenMismatchException;
        });
        Route::get('/security/invalid', static function (): never {
            throw ValidationException::withMessages(['email' => 'Invalid email.']);
        });
        Route::get('/security/throttled', static function (): never {
            throw new TooManyRequestsHttpException;
        });
        Route::get('/security/redirect', static function (): never {
            throw new HttpResponseException(redirect('/admin/login'));
        });
    }

    private function useProductionEnvironment(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');
    }

    private function assertSecurityHeaders(TestResponse $response, bool $noStore = false): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
            );

        if ($noStore) {
            $response->assertHeader('Cache-Control', 'no-store, private');
        }
    }
}
