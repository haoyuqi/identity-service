<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class RequestCorrelationTest extends TestCase
{
    public function test_every_response_carries_a_generated_request_identifier(): void
    {
        $response = $this->get('/up');

        $requestId = $response->headers->get('X-Request-Id');

        self::assertIsString($requestId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $requestId);
    }

    public function test_a_well_formed_client_identifier_is_echoed_back(): void
    {
        $this->withHeader('X-Request-Id', 'caller-supplied-1234')
            ->get('/up')
            ->assertHeader('X-Request-Id', 'caller-supplied-1234');
    }

    public function test_a_malformed_client_identifier_is_replaced(): void
    {
        $response = $this->withHeader('X-Request-Id', 'bad value with spaces')->get('/up');

        $requestId = $response->headers->get('X-Request-Id');

        self::assertIsString($requestId);
        self::assertNotSame('bad value with spaces', $requestId);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $requestId);
    }

    public function test_error_reports_include_the_identifier_chain_and_sqlstate(): void
    {
        Log::spy();

        Route::get('/correlation-failure/{account}', static function (): never {
            throw new RuntimeException('outer secret', 0, new RuntimeException('inner secret'));
        });

        $this->withHeader('X-Request-Id', 'correlated-request-1')
            ->get('/correlation-failure/account_marker');

        Log::shouldHaveReceived('error')->once()->with(
            'HTTP request failed.',
            Mockery::on(function (array $context): bool {
                $this->assertSame('correlated-request-1', $context['request_id']);
                $this->assertSame([RuntimeException::class, RuntimeException::class], $context['exception_chain']);
                $this->assertArrayNotHasKey('sqlstate', $context);
                $this->assertArrayNotHasKey('message', $context);

                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                foreach (['outer secret', 'inner secret', 'account_marker'] as $marker) {
                    $this->assertStringNotContainsString($marker, $serialized);
                }

                return true;
            }),
        );
    }

    public function test_error_headers_are_present_when_the_failure_precedes_the_middleware_stack(): void
    {
        $response = $this->withHeader('X-Request-Id', 'fallback-request-9')->get('/missing-route');

        $response->assertNotFound()->assertHeader('X-Request-Id', 'fallback-request-9');
    }
}
