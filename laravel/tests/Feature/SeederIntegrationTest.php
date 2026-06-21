<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Product;
use Database\Seeders\MerchantSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SeederIntegrationTest
 *
 * Verifies that the MerchantSeeder + ProductSeeder produce exactly the
 * data the application depends on:
 *  - All 3 merchants are created with the right credentials
 *  - Each merchant has exactly 3 products
 *  - Products are correctly scoped — no cross-merchant leakage
 *  - Stock states cover all three badge states (healthy / low / out-of-stock)
 *  - Authenticated product list only returns the logged-in merchant's products
 *
 * Safe: uses RefreshDatabase → silktech_test only.
 */
class SeederIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Run both seeders before each test
        $this->seed(MerchantSeeder::class);
        $this->seed(ProductSeeder::class);
    }

    // ── Merchant seeder ───────────────────────────────────────────────────────

    public function test_all_three_merchants_are_seeded(): void
    {
        $this->assertDatabaseCount('merchants', 3);

        foreach (['admin@silktech.com', 'alpha@silkcommerce.com', 'omega@silkcommerce.com'] as $email) {
            $this->assertDatabaseHas('merchants', ['email' => $email]);
        }
    }

    public function test_admin_merchant_can_login_with_seeded_credentials(): void
    {
        $this->postJson('/api/login', [
            'email'    => 'admin@silktech.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type']);
    }

    public function test_alpha_merchant_can_login_with_seeded_credentials(): void
    {
        $this->postJson('/api/login', [
            'email'    => 'alpha@silkcommerce.com',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_omega_merchant_can_login_with_seeded_credentials(): void
    {
        $this->postJson('/api/login', [
            'email'    => 'omega@silkcommerce.com',
            'password' => 'password123',
        ])->assertOk();
    }

    // ── Product seeder ────────────────────────────────────────────────────────

    public function test_total_of_nine_products_are_seeded(): void
    {
        $this->assertDatabaseCount('products', 9); // 3 merchants × 3 products
    }

    public function test_each_merchant_has_exactly_three_products(): void
    {
        foreach (['admin@silktech.com', 'alpha@silkcommerce.com', 'omega@silkcommerce.com'] as $email) {
            $merchant = Merchant::where('email', $email)->first();
            $this->assertEquals(3, Product::where('merchant_id', $merchant->id)->count(),
                "Expected 3 products for {$email}");
        }
    }

    public function test_each_merchant_has_one_healthy_stock_product(): void
    {
        // The seeder intentionally creates one product per merchant with stock > 5
        foreach (['admin@silktech.com', 'alpha@silkcommerce.com', 'omega@silkcommerce.com'] as $email) {
            $merchant = Merchant::where('email', $email)->first();
            $healthy  = Product::where('merchant_id', $merchant->id)
                ->where('stock_quantity', '>', 5)
                ->count();

            $this->assertGreaterThanOrEqual(1, $healthy,
                "No healthy-stock product found for {$email}");
        }
    }

    public function test_each_merchant_has_one_out_of_stock_product(): void
    {
        foreach (['admin@silktech.com', 'alpha@silkcommerce.com', 'omega@silkcommerce.com'] as $email) {
            $merchant   = Merchant::where('email', $email)->first();
            $outOfStock = Product::where('merchant_id', $merchant->id)
                ->where('stock_quantity', 0)
                ->count();

            $this->assertEquals(1, $outOfStock,
                "Expected exactly 1 out-of-stock product for {$email}");
        }
    }

    // ── Merchant isolation via the API ────────────────────────────────────────

    public function test_admin_merchant_api_only_returns_admin_products(): void
    {
        $token = $this->loginAndGetToken('admin@silktech.com');

        $response = $this->getJson('/api/products', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $this->assertEquals(3, $response->json('total'));

        $admin = Merchant::where('email', 'admin@silktech.com')->first();
        foreach ($response->json('data') as $product) {
            $this->assertEquals($admin->id, $product['merchant_id'],
                'Admin API returned a product belonging to another merchant');
        }
    }

    public function test_alpha_merchant_cannot_see_admin_products(): void
    {
        $alphaToken = $this->loginAndGetToken('alpha@silkcommerce.com');
        $admin      = Merchant::where('email', 'admin@silktech.com')->first();

        $response = $this->getJson('/api/products', [
            'Authorization' => "Bearer {$alphaToken}",
        ])->assertOk();

        foreach ($response->json('data') as $product) {
            $this->assertNotEquals($admin->id, $product['merchant_id'],
                'Alpha merchant can see admin products — isolation failure');
        }
    }


    // ── Idempotent re-seeding ─────────────────────────────────────────────────

    public function test_running_seeder_twice_does_not_duplicate_merchants(): void
    {
        // Run a second time (setUp already ran it once)
        $this->seed(MerchantSeeder::class);

        $this->assertDatabaseCount('merchants', 3); // still 3, not 6
    }

    public function test_running_seeder_twice_does_not_duplicate_products(): void
    {
        $this->seed(ProductSeeder::class);

        $this->assertDatabaseCount('products', 9); // still 9, not 18
    }

    // ── Stock adjustment on seeded data ───────────────────────────────────────

    public function test_can_adjust_stock_on_a_seeded_product(): void
    {
        $token    = $this->loginAndGetToken('admin@silktech.com');
        $merchant = Merchant::where('email', 'admin@silktech.com')->first();
        $product  = Product::where('merchant_id', $merchant->id)
            ->where('stock_quantity', '>', 5)
            ->first();

        $originalStock = $product->stock_quantity;

        $this->patchJson("/api/products/{$product->id}/stock", ['delta' => 10], [
            'Authorization' => "Bearer {$token}",
        ])
            ->assertOk()
            ->assertJsonPath('stock_quantity', $originalStock + 10);
    }

    public function test_cannot_adjust_another_merchants_seeded_product(): void
    {
        $alphaToken = $this->loginAndGetToken('alpha@silkcommerce.com');
        $admin      = Merchant::where('email', 'admin@silktech.com')->first();
        $adminProd  = Product::where('merchant_id', $admin->id)->first();

        $this->patchJson("/api/products/{$adminProd->id}/stock", ['delta' => 5], [
            'Authorization' => "Bearer {$alphaToken}",
        ])->assertForbidden();

        // Admin's product stock is unchanged
        $this->assertDatabaseHas('products', [
            'id'             => $adminProd->id,
            'stock_quantity' => $adminProd->stock_quantity,
        ]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/login', [
            'email'    => $email,
            'password' => 'password123',
        ]);

        return $response->json('access_token');
    }
}