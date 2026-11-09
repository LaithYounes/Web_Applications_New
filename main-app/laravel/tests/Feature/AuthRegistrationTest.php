<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_returns_the_stored_customer_role(): void
    {
        config([
            'jwt.algo' => 'HS256',
            'jwt.secret' => str_repeat('test', 8),
        ]);

        $email = 'new-customer@example.com';
        $password = 'DemoPassword123!';

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Customer',
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertCreated()
            ->assertJsonPath('data.user.email', $email)
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'role' => 'customer',
        ]);
    }

    #[DataProvider('submittedRoles')]
    public function test_registration_rejects_a_submitted_role(?string $role): void
    {
        $email = 'new-customer@example.com';
        $password = 'DemoPassword123!';

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Customer',
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
            'role' => $role,
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public static function submittedRoles(): array
    {
        return [
            'admin role' => ['admin'],
            'customer role' => ['customer'],
            'null role' => [null],
        ];
    }
}
