<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    public function test_the_health_endpoint_returns_success(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_service_does_not_expose_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
