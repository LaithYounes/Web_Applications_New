<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class StorefrontSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_login_stores_an_encrypted_jwt_and_enforces_roles_on_direct_routes(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $response = $this->postJson('/api/browser/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_type')
            ->assertHeader('Cache-Control', 'no-store, private');

        $payload = session('storefront.jwt');
        $this->assertIsString($payload);
        $this->assertCount(3, explode('.', Crypt::decryptString($payload)));
        $cookie = $response->getCookie(config('session.cookie'));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());

        $this->resetJwt();
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId())
            ->getJson('/api/browser/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->resetJwt();
        $this->get('/orders/42')->assertOk()->assertSee('lang="en" dir="ltr"', false);

        $this->resetJwt();
        $this->get('/admin/order-email-failures')->assertForbidden();

        $this->resetJwt();
        $this->getJson('/api/browser/admin/order-email-failures')->assertForbidden();

        $this->resetJwt();
        $this->postJson('/api/browser/auth/logout')->assertOk()->assertSessionMissing('storefront.jwt');
    }

    public function test_browser_routes_reject_a_client_bearer_and_keep_public_route_policies_separate(): void
    {
        $token = Auth::guard('api')->login(User::factory()->create(['role' => 'customer']));
        $this->resetJwt();

        $this->getJson('/api/browser/auth/me?token='.$token, ['Authorization' => 'Bearer '.$token])
            ->assertUnauthorized();
        $this->get('/admin/orders/42')->assertStatus(302)
            ->assertHeader('Location', '/login?returnTo=%2Fadmin%2Forders%2F42');

        foreach (['/', '/shop?category=1&page=2', '/products/42', '/login', '/register'] as $path) {
            $response = $this->get($path)->assertOk()->assertSee('lang="en" dir="ltr"', false);
            $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
        }

        $this->getJson('/api/v1/health')->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        $this->get('/up')->assertOk();
        $this->assertContains($this->get('/storage/nonexistent.png')->getStatusCode(), [403, 404]);
        $this->get('/build/nonexistent.js')->assertNotFound();
    }

    private function resetJwt(): void
    {
        Auth::forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
    }
}
