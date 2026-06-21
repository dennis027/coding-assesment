<?php

namespace Tests\Feature;

use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * MerchantAuthTest
 *
 * Covers:
 *  - Register: success, duplicate email, validation
 *  - Login: success, wrong password, wrong email
 *  - Logout: token revoked, cannot reuse after logout
 *  - Token required on protected routes
 */
class MerchantAuthTest extends TestCase
{
    use RefreshDatabase;

    // ── Register ──────────────────────────────────────────────────────────────

    public function test_merchant_can_register_successfully(): void
    {
        $this->postJson('/api/register', [
            'name'          => 'Test User',
            'business_name' => 'Test Business',
            'email'         => 'test@silktech.com',
            'password'      => 'password123',
        ])
            ->assertCreated()
            ->assertJsonStructure(['access_token', 'token_type', 'merchant']);

        $this->assertDatabaseHas('merchants', ['email' => 'test@silktech.com']);
    }

    public function test_register_returns_bearer_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name'          => 'Token Test',
            'business_name' => 'Biz',
            'email'         => 'token@test.com',
            'password'      => 'password123',
        ])->assertCreated();

        $this->assertNotEmpty($response->json('access_token'));
        $this->assertEquals('Bearer', $response->json('token_type'));
    }

    public function test_register_hashes_password(): void
    {
        $this->postJson('/api/register', [
            'name'          => 'Hash Test',
            'business_name' => 'Biz',
            'email'         => 'hash@test.com',
            'password'      => 'plaintext123',
        ])->assertCreated();

        $merchant = Merchant::where('email', 'hash@test.com')->first();
        $this->assertTrue(Hash::check('plaintext123', $merchant->password));
        $this->assertNotEquals('plaintext123', $merchant->password);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        Merchant::factory()->create(['email' => 'exists@test.com']);

        $this->postJson('/api/register', [
            'name'          => 'Duplicate',
            'business_name' => 'Biz',
            'email'         => 'exists@test.com',
            'password'      => 'password123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_register_requires_all_fields(): void
    {
        $this->postJson('/api/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'business_name', 'email', 'password']);
    }

    public function test_register_requires_valid_email_format(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Bad Email', 'business_name' => 'Biz',
            'email' => 'not-an-email', 'password' => 'password123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    public function test_merchant_can_login_with_correct_credentials(): void
    {
        Merchant::factory()->create([
            'email'    => 'login@test.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', [
            'email'    => 'login@test.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type']);
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        Merchant::factory()->create([
            'email'    => 'wrong@test.com',
            'password' => Hash::make('correct_password'),
        ]);

        $this->postJson('/api/login', [
            'email'    => 'wrong@test.com',
            'password' => 'wrong_password',
        ])->assertUnauthorized();
    }

    public function test_login_with_unknown_email_returns_401(): void
    {
        $this->postJson('/api/login', [
            'email'    => 'nobody@test.com',
            'password' => 'password123',
        ])->assertUnauthorized();
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    public function test_merchant_can_logout(): void
    {
        $merchant = Merchant::factory()->create();
        $token    = $merchant->createToken('test')->plainTextToken;

        $this->postJson('/api/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();
    }

    public function test_token_cannot_be_reused_after_logout(): void
    {
        $merchant = Merchant::factory()->create();
        $token    = $merchant->createToken('test')->plainTextToken;

        $this->postJson('/api/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        // Boot a fresh application instance so Sanctum's token cache is cleared
        // and the next request re-reads from the DB (where the token no longer exists)
        $this->refreshApplication();

        $this->getJson('/api/products', ['Authorization' => "Bearer {$token}"])
            ->assertUnauthorized();
    }

    public function test_logout_without_token_is_rejected(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    // ── Token protection on routes ─────────────────────────────────────────────

    public function test_products_route_requires_authentication(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->getJson('/api/products', ['Authorization' => 'Bearer completely-fake-token'])
            ->assertUnauthorized();
    }
}