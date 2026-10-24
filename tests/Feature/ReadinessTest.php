<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class ReadinessTest extends TestCase
{
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
            'database.redis.readiness_test' => $connection,
            'session.connection' => 'readiness_test',
            // Production runs Redis-backed sessions, and a readiness probe must not
            // depend on that stateful middleware stack.
            'session.driver' => 'redis',
            'session.store' => 'redis',
            'session.prefix' => 'identity-service-readiness-test:'.bin2hex(random_bytes(8)).':',
        ]);

        app('session')->forgetDrivers();
    }

    public function test_readiness_does_not_create_a_session(): void
    {
        $prefix = config('session.prefix');
        $redis = Redis::connection('readiness_test');
        $before = count($redis->keys($prefix.'*'));

        $response = $this->get(route('ready'));

        self::assertSame($before, count($redis->keys($prefix.'*')));
        self::assertNull($response->headers->get('Set-Cookie'));

        foreach ($redis->keys($prefix.'*') as $key) {
            $redis->del($key);
        }
    }

    public function test_readiness_reports_available_dependencies(): void
    {
        $this->withHeader('Accept', 'application/json')
            ->get(route('ready'))
            ->assertOk()
            ->assertExactJson(['status' => 'ready', 'dependencies' => []]);
    }

    public function test_readiness_reports_a_redis_outage_without_disclosing_details(): void
    {
        $this->disableRedis();

        $this->withHeader('Accept', 'application/json')
            ->get(route('ready'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable', 'dependencies' => ['redis']])
            ->assertDontSeeText('127.0.0.1');
    }

    public function test_readiness_reports_a_database_outage_without_disclosing_details(): void
    {
        config([
            'database.connections.readiness_probe' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'unreachable',
                'username' => 'unreachable',
                'password' => '',
                'sslmode' => 'prefer',
            ],
            'database.default' => 'readiness_probe',
        ]);

        DB::purge('readiness_probe');

        $response = $this->withHeader('Accept', 'application/json')->get(route('ready'));

        $response->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable', 'dependencies' => ['database']])
            ->assertDontSeeText('unreachable');

        DB::purge('readiness_probe');
        config(['database.default' => 'testing']);
    }

    public function test_readiness_returns_plain_text_for_browser_clients(): void
    {
        $this->get(route('ready'))
            ->assertOk()
            ->assertSeeText('Service ready.');
    }

    public function test_liveness_probe_ignores_unavailable_dependencies(): void
    {
        $this->disableRedis();

        config([
            'database.connections.readiness_probe' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'unreachable',
                'username' => 'unreachable',
                'password' => '',
                'sslmode' => 'prefer',
            ],
            'database.default' => 'readiness_probe',
        ]);

        DB::purge('readiness_probe');

        $this->get('/up')->assertOk();

        DB::purge('readiness_probe');
        config(['database.default' => 'testing']);
    }

    private function disableRedis(): void
    {
        config(['database.redis.session_probe' => [
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 0,
            'password' => null,
            'username' => null,
            'url' => null,
            'max_retries' => 0,
        ]]);
        config(['session.connection' => 'session_probe']);
    }
}
