<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_200_when_database_is_available(): void
    {
        $response = $this->getJson('/api/health');

        $this->assertEquals(200, $response->status());
        $this->assertTrue($response->json('success'));
        $this->assertEquals('product-service', $response->json('service'));
        $this->assertEquals('healthy', $response->json('status'));
    }
}
