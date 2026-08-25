<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserContextForwardingTest extends TestCase
{
    /**
     * Test authenticated user produces X-User-Id
     */
    public function test_authenticated_user_produces_x_user_id(): void
    {
        Http::fake([
            config('services.auth_service_url') . '/api/v1/auth/me' => Http::response([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => 15,
                        'name' => 'John Doe',
                        'email' => 'john@example.com',
                    ],
                ],
            ], 200),
            config('services.product_service_url') . '/api/v1/products' => Http::response([
                'success' => true,
                'data' => [],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/products', [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(200);

        // Verify the X-User-Id header was forwarded to Product Service
        Http::assertSent(function ($request) {
            return $request->url() === config('services.product_service_url') . '/api/v1/products' &&
                   $request->header('X-User-Id') === '15';
        });
    }

    /**
     * Test X-User-Id is forwarded to Order Service
     */
    public function test_x_user_id_forwarded_to_order_service(): void
    {
        Http::fake([
            config('services.auth_service_url') . '/api/v1/auth/me' => Http::response([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => 20,
                        'name' => 'Jane Doe',
                        'email' => 'jane@example.com',
                    ],
                ],
            ], 200),
            config('services.order_service_url') . '/api/v1/orders' => Http::response([
                'success' => true,
                'data' => [],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/orders', [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(200);

        // Verify the X-User-Id header was forwarded
        Http::assertSent(function ($request) {
            return $request->url() === config('services.order_service_url') . '/api/v1/orders' &&
                   $request->header('X-User-Id') === '20';
        });
    }

    /**
     * Test client-provided X-User-Id is ignored/replaced
     */
    public function test_client_provided_x_user_id_is_ignored(): void
    {
        Http::fake([
            config('services.auth_service_url') . '/api/v1/auth/me' => Http::response([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => 15,
                        'name' => 'John Doe',
                        'email' => 'john@example.com',
                    ],
                ],
            ], 200),
            config('services.order_service_url') . '/api/v1/orders' => Http::response([
                'success' => true,
                'data' => [],
            ], 200),
        ]);

        // Client tries to claim they are user 999
        $response = $this->getJson('/api/v1/orders', [
            'Authorization' => 'Bearer valid-token',
            'X-User-Id' => '999',
        ]);

        $response->assertStatus(200);

        // Verify the X-User-Id was replaced with the authenticated user's ID
        Http::assertSent(function ($request) {
            return $request->url() === config('services.order_service_url') . '/api/v1/orders' &&
                   $request->header('X-User-Id') === '15'; // Not 999
        });
    }

    /**
     * Test client-provided X-User-Email is ignored/replaced
     */
    public function test_client_provided_x_user_email_is_ignored(): void
    {
        Http::fake([
            config('services.auth_service_url') . '/api/v1/auth/me' => Http::response([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => 15,
                        'name' => 'John Doe',
                        'email' => 'john@example.com',
                    ],
                ],
            ], 200),
            config('services.product_service_url') . '/api/v1/products' => Http::response([
                'success' => true,
                'data' => [],
            ], 200),
        ]);

        // Client tries to claim they are another user
        $response = $this->getJson('/api/v1/products', [
            'Authorization' => 'Bearer valid-token',
            'X-User-Email' => 'attacker@example.com',
        ]);

        $response->assertStatus(200);

        // Verify the X-User-Email was replaced with the authenticated user's email
        Http::assertSent(function ($request) {
            return $request->url() === config('services.product_service_url') . '/api/v1/products' &&
                   $request->header('X-User-Email') === 'john@example.com';
        });
    }

    /**
     * Test X-User-Email is forwarded
     */
    public function test_x_user_email_forwarded(): void
    {
        Http::fake([
            config('services.auth_service_url') . '/api/v1/auth/me' => Http::response([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => 15,
                        'name' => 'John Doe',
                        'email' => 'john@example.com',
                    ],
                ],
            ], 200),
            config('services.order_service_url') . '/api/v1/orders' => Http::response([
                'success' => true,
                'data' => [],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/orders', [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(200);

        // Verify the X-User-Email header was forwarded
        Http::assertSent(function ($request) {
            return $request->url() === config('services.order_service_url') . '/api/v1/orders' &&
                   $request->header('X-User-Email') === 'john@example.com';
        });
    }
}
