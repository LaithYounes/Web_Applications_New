<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InventoryRejectedException;
use App\Modules\Inventory\Exceptions\InventoryServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class UnifiedErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    private function assertUnifiedError(
        TestResponse $response,
        int $status
    ): void {
        $response->assertStatus($status)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'errors',
            ])
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);
    }

    public function test_unauthenticated_request_returns_unified_error(): void
    {
        $response = $this->getJson('/api/v1/orders');

        $this->assertUnifiedError($response, 401);

        $response->assertJsonPath(
            'message',
            'Authentication is required'
        )->assertJsonPath('errors', null);
    }

    public function test_invalid_login_returns_unified_error(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ]);

        $this->assertUnifiedError($response, 401);
        $response->assertJsonPath('message', 'Invalid email or password')
            ->assertJsonPath('errors', null);
    }

    public function test_login_rate_limit_returns_unified_error_with_retry_after(): void
    {
        $credentials = [
            'email' => 'unknown@example.com',
            'password' => 'incorrect-password',
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', $credentials)->assertUnauthorized();
        }

        $response = $this->postJson('/api/v1/auth/login', $credentials);

        $this->assertUnifiedError($response, 429);
        $response->assertJsonPath('message', 'Too many requests. Please try again later.')
            ->assertJsonPath('errors', null)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_forbidden_request_returns_unified_error(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $response = $this->actingAs($customer, 'api')
            ->patchJson('/api/v1/orders/1/status', [
                'status' => 'confirmed',
            ]);

        $this->assertUnifiedError($response, 403);

        $response->assertJsonPath(
            'message',
            'You are not authorized to perform this action'
        )->assertJsonPath('errors', null);
    }

    public function test_not_found_returns_unified_error(): void
    {
        $response = $this->getJson('/api/v1/non-existing-resource');

        $this->assertUnifiedError($response, 404);

        $response->assertJsonPath(
            'message',
            'The requested resource was not found.'
        );
    }

    public function test_method_not_allowed_keeps_the_allow_header(): void
    {
        $response = $this->getJson('/api/v1/auth/login');

        $this->assertUnifiedError($response, 405);
        $response->assertHeader('Allow', 'POST');
    }

    public function test_invalid_registration_returns_field_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Customer',
            'email' => 'invalid-email',
            'password' => 'DemoPassword123!',
            'password_confirmation' => 'different-password',
        ]);

        $this->assertUnifiedError($response, 422);
        $response->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_validation_error_returns_unified_error(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/orders/1/status', [
                'status' => 'invalid-status',
            ]);

        $this->assertUnifiedError($response, 422);

        $response->assertJsonPath(
            'message',
            'The provided data is invalid.'
        )->assertJsonStructure([
            'errors' => ['status'],
        ]);
    }

    public function test_inventory_rejected_returns_unified_error(): void
    {
        Route::get('/api/v1/testing/inventory-rejected', function () {
            throw new InventoryRejectedException(
                'Insufficient stock for product.'
            );
        });

        $response = $this->getJson(
            '/api/v1/testing/inventory-rejected'
        );

        $this->assertUnifiedError($response, 409);

        $response->assertJsonPath(
            'message',
            'Insufficient stock for product.'
        )->assertJsonPath('errors', null);
    }

    public function test_inventory_service_exception_returns_unified_error(): void
    {
        Route::get('/api/v1/testing/inventory-unavailable', function () {
            throw new InventoryServiceException(
                'Internal inventory connection details.'
            );
        });

        $response = $this->getJson(
            '/api/v1/testing/inventory-unavailable'
        );

        $this->assertUnifiedError($response, 503);

        $response->assertJsonPath(
            'message',
            'The inventory service is temporarily unavailable.'
        )->assertJsonPath('errors', null);

        $this->assertStringNotContainsString(
            'Internal inventory connection details.',
            $response->getContent()
        );
    }

    public function test_unhandled_exception_returns_safe_unified_error(): void
    {
        Route::get('/api/v1/testing/internal-error', function () {
            throw new RuntimeException(
                'Sensitive database connection details.'
            );
        });

        $response = $this->getJson(
            '/api/v1/testing/internal-error'
        );

        $this->assertUnifiedError($response, 500);

        $response->assertJsonPath(
            'message',
            'An unexpected server error occurred.'
        )->assertJsonPath('errors', null);

        $this->assertStringNotContainsString(
            'Sensitive database connection details.',
            $response->getContent()
        );
    }
}
