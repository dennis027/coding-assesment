<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StockAdjustmentTest
 *
 * Covers the riskiest logic in the application:
 *  - Stock going negative (must be blocked)
 *  - Concurrent adjustments (race condition safety)
 *  - Merchant isolation (cannot touch another merchant's product)
 *
 * Uses RefreshDatabase → runs against silktech_test (see phpunit.xml).
 * Your silktech dev database is NEVER touched.
 */
class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private string   $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->token    = $this->merchant->createToken('test')->plainTextToken;
    }

    // ── Positive delta (restock) ──────────────────────────────────────────────

    public function test_positive_delta_increases_stock(): void
    {
        $product = Product::factory()->create([
            'merchant_id'    => $this->merchant->id,
            'stock_quantity' => 10,
        ]);

        $this->patchJson("/api/products/{$product->id}/stock", ['delta' => 5], $this->auth())
            ->assertOk()
            ->assertJsonPath('stock_quantity', 15);

        $this->assertDatabaseHas('products', [
            'id'             => $product->id,
            'stock_quantity' => 15,
        ]);
    }

    // ── Negative delta (sale) ─────────────────────────────────────────────────

    public function test_negative_delta_decreases_stock(): void
    {
        $product = Product::factory()->create([
            'merchant_id'    => $this->merchant->id,
            'stock_quantity' => 20,
        ]);

        $this->patchJson("/api/products/{$product->id}/stock", ['delta' => -8], $this->auth())
            ->assertOk()
            ->assertJsonPath('stock_quantity', 12);

        $this->assertDatabaseHas('products', [
            'id'             => $product->id,
            'stock_quantity' => 12,
        ]);
    }

    // ── CRITICAL: stock going negative ───────────────────────────────────────

    public function test_delta_that_would_make_stock_negative_is_rejected(): void
    {
        $product = Product::factory()->create([
            'merchant_id'    => $this->merchant->id,
            'stock_quantity' => 5,
        ]);

        $this->patchJson("/api/products/{$product->id}/stock", ['delta' => -10], $this->auth())
            ->assertUnprocessable()   // 422
            ->assertJsonStructure(['message']);

        // DB must be unchanged — no partial write
        $this->assertDatabaseHas('products', [
            'id'             => $product->id,
            'stock_quantity' => 5,
        ]);
    }

    public function test_delta_that_exactly_empties_stock_is_allowed(): void
    {
        // Edge case: stock = 5, delta = -5 → result = 0. Must succeed.
        $product = Product::factory()->create([
            'merchant_id'    => $this->merchant->id,
            'stock_quantity' => 5,
        ]);

        $this->patchJson("/api/products/{$product->id}/stock", ['delta' => -5], $this->auth())
            ->assertOk()
            ->assertJsonPath('stock_quantity', 0);

        $this->assertDatabaseHas('products', [
            'id'             => $product->id,
            'stock_quantity' => 0,
        ]);
    }

    public function test_decrement_from_zero_is_rejected(): void
    {
        // Stock already at 0 — any negative delta must fail.
        $product = Product::factory()->create([
            'merchant_id'    => $this->merchant->id,
            'stock_quantity' => 0,
        ]);

        $this->patchJson("/api/products/{$product->id}/stock", ['delta' => -1], $this->auth())
            ->assertUnprocessable();

        $this->assertDatabaseHas('products', [
            'id'             => $product->id,
            'stock_quantity' => 0,
        ]);
    }

    // ── CRITICAL: zero delta (no-op) ──────────────────────────────────────────

    public function test_zero_delta_is_rejected_by_validation(): void
    {
        $product = Product::factory()->create([
            'merchant_id'    => $this->merchant->id,
            'stock_quantity' => 20,
        ]);

        // Sending delta=0 means nothing changed — should be a validation error
        $this->patchJson("/api/products/{$product->id}/stock", ['delta' => 0], $this->auth())
            ->assertUnprocessable();
    }

    // ── CRITICAL: concurrent adjustments (race condition) ────────────────────

    public function test_sequential_adjustments_produce_correct_final_stock(): void
    {
        // Simulates two rapid adjustments in sequence.
        // Uses atomic increment/decrement so each reads the committed value.
        $product = Product::factory()->create([
            'merchant_id'    => $this->merchant->id,
            'stock_quantity' => 100,
        ]);

        $this->patchJson("/api/products/{$product->id}/stock", ['delta' => 10], $this->auth())
            ->assertOk();

        $this->patchJson("/api/products/{$product->id}/stock", ['delta' => -15], $this->auth())
            ->assertOk();

        // 100 + 10 − 15 = 95. Must not be 85 (lost update) or 110 (bad read).
        $product->refresh();
        $this->assertEquals(95, $product->stock_quantity);
    }

    public function test_multiple_increments_accumulate_correctly(): void
    {
        $product = Product::factory()->create([
            'merchant_id'    => $this->merchant->id,
            'stock_quantity' => 0,
        ]);

        foreach ([10, 20, 5] as $delta) {
            $this->patchJson("/api/products/{$product->id}/stock", ['delta' => $delta], $this->auth())
                ->assertOk();
        }

        $product->refresh();
        $this->assertEquals(35, $product->stock_quantity); // 0 + 10 + 20 + 5
    }

    // ── CRITICAL: merchant isolation ──────────────────────────────────────────

    public function test_merchant_cannot_adjust_another_merchants_product(): void
    {
        $other   = Merchant::factory()->create();
        $product = Product::factory()->create([
            'merchant_id'    => $other->id,
            'stock_quantity' => 50,
        ]);

        $this->patchJson("/api/products/{$product->id}/stock", ['delta' => 5], $this->auth())
            ->assertForbidden();  // 403

        // Other merchant's stock is untouched
        $this->assertDatabaseHas('products', [
            'id'             => $product->id,
            'stock_quantity' => 50,
        ]);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $product = Product::factory()->create(['merchant_id' => $this->merchant->id]);

        $this->patchJson("/api/products/{$product->id}/stock", ['delta' => 1])
            ->assertUnauthorized();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }
}