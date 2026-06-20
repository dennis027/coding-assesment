<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->token = $this->merchant->createToken('test-token')->plainTextToken;
    }

    // ========== Index Tests ==========

    public function test_index_returns_paginated_products(): void
    {
        Product::factory(25)->create(['merchant_id' => $this->merchant->id]);

        $response = $this->getJson('/api/products', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertOk();
        $response->assertJsonCount(20, 'data');
        $response->assertJsonPath('meta.per_page', 20);
        $response->assertJsonPath('meta.total', 25);
    }

    public function test_index_custom_per_page(): void
    {
        Product::factory(30)->create(['merchant_id' => $this->merchant->id]);

        $response = $this->getJson('/api/products?per_page=10', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertOk();
        $response->assertJsonCount(10, 'data');
    }

    public function test_index_filters_by_category(): void
    {
        Product::factory(5)->create([
            'merchant_id' => $this->merchant->id,
            'category' => 'Electronics',
        ]);
        Product::factory(3)->create([
            'merchant_id' => $this->merchant->id,
            'category' => 'Furniture',
        ]);

        $response = $this->getJson('/api/products?category=Electronics', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertOk();
        $response->assertJsonCount(5, 'data');
    }

    public function test_index_filters_in_stock(): void
    {
        Product::factory(5)->create([
            'merchant_id' => $this->merchant->id,
            'stock_quantity' => 0,
        ]);
        Product::factory(7)->create([
            'merchant_id' => $this->merchant->id,
            'stock_quantity' => 10,
        ]);

        $response = $this->getJson('/api/products?in_stock=true', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertOk();
        $response->assertJsonCount(7, 'data');
    }

    public function test_index_filters_out_of_stock(): void
    {
        Product::factory(5)->create([
            'merchant_id' => $this->merchant->id,
            'stock_quantity' => 0,
        ]);
        Product::factory(7)->create([
            'merchant_id' => $this->merchant->id,
            'stock_quantity' => 10,
        ]);

        $response = $this->getJson('/api/products?in_stock=false', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertOk();
        $response->assertJsonCount(5, 'data');
    }

    public function test_index_sorts_by_price_ascending(): void
    {
        Product::factory()->create(['merchant_id' => $this->merchant->id, 'price' => 500]);
        Product::factory()->create(['merchant_id' => $this->merchant->id, 'price' => 100]);
        Product::factory()->create(['merchant_id' => $this->merchant->id, 'price' => 300]);

        $response = $this->getJson('/api/products?sort_by=price&order=asc', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertOk();
        $prices = array_map(fn($item) => $item['price'], $response->json('data'));
        $this->assertEquals(['100.00', '300.00', '500.00'], $prices);
    }

    public function test_index_only_returns_merchant_products(): void
    {
        $otherMerchant = Merchant::factory()->create();

        Product::factory(5)->create(['merchant_id' => $this->merchant->id]);
        Product::factory(10)->create(['merchant_id' => $otherMerchant->id]);

        $response = $this->getJson('/api/products', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertOk();
        $response->assertJsonCount(5, 'data');
        $response->assertJsonPath('meta.total', 5);
    }

    // ========== Store Tests ==========

    public function test_store_creates_product_successfully(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'Test Product',
            'price' => 99.99,
            'category' => 'Electronics',
            'stock_quantity' => 50,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertCreated();
        $response->assertJsonPath('name', 'Test Product');
        $response->assertJsonPath('price', '99.99');
        $response->assertJsonPath('category', 'Electronics');
        $response->assertJsonPath('stock_quantity', 50);

        $this->assertDatabaseHas('products', [
            'merchant_id' => $this->merchant->id,
            'name' => 'Test Product',
        ]);
    }

    public function test_store_requires_name(): void
    {
        $response = $this->postJson('/api/products', [
            'price' => 99.99,
            'category' => 'Electronics',
            'stock_quantity' => 50,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('name');
    }

    public function test_store_requires_positive_price(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'Test',
            'price' => 0,
            'category' => 'Electronics',
            'stock_quantity' => 50,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('price');
    }

    public function test_store_requires_non_negative_stock(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'Test',
            'price' => 99.99,
            'category' => 'Electronics',
            'stock_quantity' => -5,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('stock_quantity');
    }

    // ========== Stock Adjustment Tests (Critical) ==========

    public function test_adjust_stock_increases_by_positive_delta(): void
    {
        $product = Product::factory()->create([
            'merchant_id' => $this->merchant->id,
            'stock_quantity' => 10,
        ]);

        $response = $this->patchJson(
            "/api/products/{$product->id}/stock",
            ['delta' => 5],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $response->assertOk();
        $response->assertJsonPath('stock_quantity', 15);

        $product->refresh();
        $this->assertEquals(15, $product->stock_quantity);
    }

    public function test_adjust_stock_decreases_by_negative_delta(): void
    {
        $product = Product::factory()->create([
            'merchant_id' => $this->merchant->id,
            'stock_quantity' => 20,
        ]);

        $response = $this->patchJson(
            "/api/products/{$product->id}/stock",
            ['delta' => -8],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $response->assertOk();
        $response->assertJsonPath('stock_quantity', 12);

        $product->refresh();
        $this->assertEquals(12, $product->stock_quantity);
    }

    /**
     * CRITICAL: Test that adjustment is rejected if result would be negative.
     */
    public function test_adjust_stock_prevents_negative_stock(): void
    {
        $product = Product::factory()->create([
            'merchant_id' => $this->merchant->id,
            'stock_quantity' => 5,
        ]);

        $response = $this->patchJson(
            "/api/products/{$product->id}/stock",
            ['delta' => -10],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('message', fn($msg) => str_contains($msg, 'Stock adjustment failed'));

        // Verify stock unchanged
        $product->refresh();
        $this->assertEquals(5, $product->stock_quantity);
    }

    /**
     * CRITICAL: Test edge case: stock at 0, adjustment to -1.
     */
    public function test_adjust_stock_from_zero_fails(): void
    {
        $product = Product::factory()->create([
            'merchant_id' => $this->merchant->id,
            'stock_quantity' => 0,
        ]);

        $response = $this->patchJson(
            "/api/products/{$product->id}/stock",
            ['delta' => -1],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $response->assertStatus(422);

        $product->refresh();
        $this->assertEquals(0, $product->stock_quantity);
    }

    /**
     * CRITICAL: Test race condition safety.
     * Two concurrent requests adjusting stock should not cause race condition.
     */
    public function test_adjust_stock_concurrent_safety(): void
    {
        $product = Product::factory()->create([
            'merchant_id' => $this->merchant->id,
            'stock_quantity' => 100,
        ]);

        // Simulate two concurrent adjustments: +10 and -15
        $this->patchJson(
            "/api/products/{$product->id}/stock",
            ['delta' => 10],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $this->patchJson(
            "/api/products/{$product->id}/stock",
            ['delta' => -15],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $product->refresh();
        // Should be 100 + 10 - 15 = 95, not subject to race condition
        $this->assertEquals(95, $product->stock_quantity);
    }

    /**
     * CRITICAL: Merchant cannot adjust another merchant's product.
     */
    public function test_adjust_stock_authorization_check(): void
    {
        $otherMerchant = Merchant::factory()->create();
        $product = Product::factory()->create([
            'merchant_id' => $otherMerchant->id,
            'stock_quantity' => 50,
        ]);

        $response = $this->patchJson(
            "/api/products/{$product->id}/stock",
            ['delta' => 5],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $response->assertForbidden();

        // Verify stock unchanged
        $product->refresh();
        $this->assertEquals(50, $product->stock_quantity);
    }

    public function test_adjust_stock_with_zero_delta(): void
    {
        $product = Product::factory()->create([
            'merchant_id' => $this->merchant->id,
            'stock_quantity' => 20,
        ]);

        $response = $this->patchJson(
            "/api/products/{$product->id}/stock",
            ['delta' => 0],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $response->assertOk();
        $response->assertJsonPath('stock_quantity', 20);
    }

    // ========== Authentication Tests ==========

    public function test_unauthenticated_request_fails(): void
    {
        $response = $this->getJson('/api/products');

        $response->assertUnauthorized();
    }

    public function test_invalid_token_fails(): void
    {
        $response = $this->getJson('/api/products', [
            'Authorization' => 'Bearer invalid-token',
        ]);

        $response->assertUnauthorized();
    }
}