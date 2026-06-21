<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProductApiTest
 *
 * Covers:
 *  - Merchant data isolation (never see another merchant's products)
 *  - Pagination, filtering, sorting
 *  - Validation boundaries (price > 0, stock >= 0)
 *  - Soft-delete behaviour
 *  - Unauthenticated access
 */
class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private Merchant $otherMerchant;
    private string   $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant      = Merchant::factory()->create();
        $this->otherMerchant = Merchant::factory()->create();
        $this->token         = $this->merchant->createToken('test')->plainTextToken;
    }

    // ── CRITICAL: merchant isolation ──────────────────────────────────────────

    public function test_merchant_only_sees_their_own_products(): void
    {
        Product::factory(3)->create(['merchant_id' => $this->merchant->id]);
        Product::factory(5)->create(['merchant_id' => $this->otherMerchant->id]);

        $response = $this->getJson('/api/products', $this->auth())->assertOk();

        $this->assertCount(3, $response->json('data'));
        foreach ($response->json('data') as $product) {
            $this->assertEquals($this->merchant->id, $product['merchant_id']);
        }
    }

    public function test_total_count_only_reflects_own_products(): void
    {
        Product::factory(3)->create(['merchant_id' => $this->merchant->id]);
        Product::factory(10)->create(['merchant_id' => $this->otherMerchant->id]);

        $this->getJson('/api/products', $this->auth())
            ->assertOk()
            ->assertJsonPath('total', 3);
    }

    // ── Pagination ────────────────────────────────────────────────────────────

    public function test_default_page_size_is_20(): void
    {
        Product::factory(25)->create(['merchant_id' => $this->merchant->id]);

        $response = $this->getJson('/api/products', $this->auth())->assertOk();

        $this->assertCount(20, $response->json('data'));
        $this->assertEquals(25, $response->json('total'));
    }

    public function test_custom_per_page_is_respected(): void
    {
        Product::factory(15)->create(['merchant_id' => $this->merchant->id]);

        $response = $this->getJson('/api/products?per_page=5', $this->auth())->assertOk();

        $this->assertCount(5, $response->json('data'));
    }

    // ── Filters ───────────────────────────────────────────────────────────────

    public function test_filter_by_category(): void
    {
        Product::factory(4)->create(['merchant_id' => $this->merchant->id, 'category' => 'Electronics']);
        Product::factory(3)->create(['merchant_id' => $this->merchant->id, 'category' => 'Furniture']);

        $response = $this->getJson('/api/products?category=Electronics', $this->auth())->assertOk();

        $this->assertCount(4, $response->json('data'));
        foreach ($response->json('data') as $p) {
            $this->assertEquals('Electronics', $p['category']);
        }
    }

    public function test_filter_in_stock_true(): void
    {
        Product::factory(6)->create(['merchant_id' => $this->merchant->id, 'stock_quantity' => 10]);
        Product::factory(3)->create(['merchant_id' => $this->merchant->id, 'stock_quantity' => 0]);

        $response = $this->getJson('/api/products?in_stock=true', $this->auth())->assertOk();

        $this->assertCount(6, $response->json('data'));
        foreach ($response->json('data') as $p) {
            $this->assertGreaterThan(0, $p['stock_quantity']);
        }
    }

    public function test_filter_in_stock_false_returns_only_out_of_stock(): void
    {
        Product::factory(6)->create(['merchant_id' => $this->merchant->id, 'stock_quantity' => 10]);
        Product::factory(3)->create(['merchant_id' => $this->merchant->id, 'stock_quantity' => 0]);

        $response = $this->getJson('/api/products?in_stock=false', $this->auth())->assertOk();

        $this->assertCount(3, $response->json('data'));
        foreach ($response->json('data') as $p) {
            $this->assertEquals(0, $p['stock_quantity']);
        }
    }

    public function test_sort_by_price_ascending(): void
    {
        Product::factory()->create(['merchant_id' => $this->merchant->id, 'price' => 500]);
        Product::factory()->create(['merchant_id' => $this->merchant->id, 'price' => 100]);
        Product::factory()->create(['merchant_id' => $this->merchant->id, 'price' => 300]);

        $response = $this->getJson('/api/products?sort_by=price&order=asc', $this->auth())->assertOk();

        $prices = array_column($response->json('data'), 'price');
        $this->assertEquals(['100.00', '300.00', '500.00'], $prices);
    }

    public function test_sort_by_price_descending(): void
    {
        Product::factory()->create(['merchant_id' => $this->merchant->id, 'price' => 500]);
        Product::factory()->create(['merchant_id' => $this->merchant->id, 'price' => 100]);
        Product::factory()->create(['merchant_id' => $this->merchant->id, 'price' => 300]);

        $response = $this->getJson('/api/products?sort_by=price&order=desc', $this->auth())->assertOk();

        $prices = array_column($response->json('data'), 'price');
        $this->assertEquals(['500.00', '300.00', '100.00'], $prices);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_create_product_success(): void
    {
        $this->postJson('/api/products', [
            'name'           => 'Ankara Tote Bag',
            'price'          => 1500.00,
            'category'       => 'Accessories',
            'stock_quantity' => 20,
        ], $this->auth())
            ->assertCreated()
            ->assertJsonPath('name', 'Ankara Tote Bag')
            ->assertJsonPath('merchant_id', $this->merchant->id);

        $this->assertDatabaseHas('products', [
            'merchant_id' => $this->merchant->id,
            'name'        => 'Ankara Tote Bag',
        ]);
    }

    public function test_created_product_is_scoped_to_the_authenticated_merchant(): void
    {
        $this->postJson('/api/products', [
            'name' => 'Scoped Product', 'price' => 100,
            'category' => 'Test', 'stock_quantity' => 5,
        ], $this->auth())->assertCreated();

        $this->assertDatabaseHas('products', ['merchant_id' => $this->merchant->id, 'name' => 'Scoped Product']);
        $this->assertDatabaseMissing('products', ['merchant_id' => $this->otherMerchant->id, 'name' => 'Scoped Product']);
    }

    // ── Validation boundaries ─────────────────────────────────────────────────

    public function test_price_must_be_greater_than_zero(): void
    {
        $this->postJson('/api/products', [
            'name' => 'Free Product', 'price' => 0,
            'category' => 'Test', 'stock_quantity' => 5,
        ], $this->auth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');
    }

    public function test_negative_price_is_rejected(): void
    {
        $this->postJson('/api/products', [
            'name' => 'Bad Price', 'price' => -10,
            'category' => 'Test', 'stock_quantity' => 5,
        ], $this->auth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');
    }

    public function test_negative_stock_quantity_is_rejected(): void
    {
        $this->postJson('/api/products', [
            'name' => 'Bad Stock', 'price' => 100,
            'category' => 'Test', 'stock_quantity' => -1,
        ], $this->auth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock_quantity');
    }

    public function test_zero_stock_quantity_is_allowed(): void
    {
        $this->postJson('/api/products', [
            'name' => 'Coming Soon', 'price' => 999,
            'category' => 'Electronics', 'stock_quantity' => 0,
        ], $this->auth())
            ->assertCreated()
            ->assertJsonPath('stock_quantity', 0);
    }

    public function test_all_required_fields_must_be_present(): void
    {
        $this->postJson('/api/products', [], $this->auth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price', 'category', 'stock_quantity']);
    }

    // ── Authentication ────────────────────────────────────────────────────────

    public function test_unauthenticated_list_request_is_rejected(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
    }

    public function test_unauthenticated_create_request_is_rejected(): void
    {
        $this->postJson('/api/products', [
            'name' => 'X', 'price' => 1, 'category' => 'Y', 'stock_quantity' => 1,
        ])->assertUnauthorized();
    }

    // ── Soft delete ───────────────────────────────────────────────────────────

    public function test_soft_deleted_products_do_not_appear_in_list(): void
    {
        $active  = Product::factory()->create(['merchant_id' => $this->merchant->id]);
        $deleted = Product::factory()->create(['merchant_id' => $this->merchant->id]);
        $deleted->delete();

        $response = $this->getJson('/api/products', $this->auth())->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($deleted->id, $ids);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }
}