<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    /**
     * Test public login endpoint works without token
     */
    public function test_login_works_without_token(): void
    {
        Http::fake([
            config('services.auth_service_url') . '/api/v1/auth/login' => Http::response([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => 1,
                        'name' => 'Test User',
                        'email' => 'test@example.com',
                    ],
                    'token' => 'test-token-123',
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.token', 'test-token-123');
    }

    /**
     * Test public registration endpoint works without token
     */
    public function test_registration_works_without_token(): void
    {
        Http::fake([
            config('services.auth_service_url') . '/api/v1/auth/register' => Http::response([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => 2,
                        'name' => 'New User',
                        'email' => 'new@example.com',
                    ],
                ],
            ], 201),
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
    }

    /**
     * Test protected route without token returns 401
     */
    public function test_protected_route_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Unauthenticated');
    }

    /**
     * Test invalid token returns 401
     */
    public function test_invalid_token_returns_401(): void
    {
        Http::fake([
            config('services.auth_service_url') . '/api/v1/auth/me' => Http::response([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401),
        ]);

        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer invalid-token',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
    }

    /**
     * Test Auth Service unavailable returns 503
     */
    public function test_auth_service_unavailable_returns_503(): void
    {
        Http::fake([
            config('services.auth_service_url') . '/api/v1/auth/me' => Http::response(null, 0),
        ]);

        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(503);
    }

    /**
     * Test valid token allows protected request
     */
    public function test_valid_token_allows_protected_request(): void
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
        ]);

        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }
}
