<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantOrder;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->product  = Product::factory()->create([
            'merchant_id'    => $this->merchant->id,
            'stock_quantity' => 10,
        ]);
    }

    /** @test */
    public function it_increases_stock_by_positive_delta(): void
    {
        $this->actingAs($this->merchant, 'sanctum')
            ->patchJson("/api/products/{$this->product->id}/stock", ['delta' => 5])
            ->assertOk()
            ->assertJsonFragment(['stock_quantity' => 15]);

        $this->assertDatabaseHas('products', [
            'id'             => $this->product->id,
            'stock_quantity' => 15,
        ]);
    }

    /** @test */
    public function it_decreases_stock_by_negative_delta(): void
    {
        $this->actingAs($this->merchant, 'sanctum')
            ->patchJson("/api/products/{$this->product->id}/stock", ['delta' => -3])
            ->assertOk()
            ->assertJsonFragment(['stock_quantity' => 7]);
    }

    /** @test */
    public function it_rejects_adjustment_that_would_take_stock_below_zero(): void
    {
        $this->actingAs($this->merchant, 'sanctum')
            ->patchJson("/api/products/{$this->product->id}/stock", ['delta' => -15])
            ->assertUnprocessable() // 422
            ->assertJsonStructure(['message']);

        // Stock must be unchanged in the DB
        $this->assertDatabaseHas('products', [
            'id'             => $this->product->id,
            'stock_quantity' => 10,
        ]);
    }

    /** @test */
    public function it_rejects_a_delta_of_zero(): void
    {
        $this->actingAs($this->merchant, 'sanctum')
            ->patchJson("/api/products/{$this->product->id}/stock", ['delta' => 0])
            ->assertUnprocessable();
    }

    /** @test */
    public function it_forbids_adjusting_another_merchants_product(): void
    {
        $otherMerchant = Merchant::factory()->create();
        $otherProduct  = Product::factory()->create([
            'merchant_id'    => $otherMerchant->id,
            'stock_quantity' => 20,
        ]);

        $this->actingAs($this->merchant, 'sanctum')
            ->patchJson("/api/products/{$otherProduct->id}/stock", ['delta' => 1])
            ->assertForbidden();
    }
}

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = Merchant::factory()->create();
        MerchantOrder::factory()->create([
            'merchant_id'     => $merchant->id,
            'order_reference' => 'SC-ORD-10456',
            'status'          => 'pending',
            'total_amount'    => 2500.00,  // payload amount must match this exactly
        ]);

        // This is the "perfect" payload — every test starts from this and overrides one thing.
        $this->payload = [
            'provider'        => 'mpesa',
            'transaction_id'  => 'QFL3X9Y2KP',
            'order_reference' => 'SC-ORD-10456',
            'amount'          => 2500.00,   // matches total_amount above ✓
            'currency'        => 'KES',
            'msisdn'          => '254712345678',
            'status'          => 'completed',
            'occurred_at'     => '2026-06-18T10:32:00Z',
        ];
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    /** @test */
    public function it_processes_a_valid_webhook_and_marks_order_paid(): void
    {
        $this->postJson('/api/webhooks/payment', $this->payload)
            ->assertCreated()
            ->assertJsonFragment(['message' => 'Processed'])
            ->assertJsonFragment(['note' => 'order_marked_paid']);

        $this->assertDatabaseHas('payments', [
            'transaction_id' => 'QFL3X9Y2KP',
            'status'         => 'completed',
        ]);
        $this->assertDatabaseHas('merchant_orders', [
            'order_reference' => 'SC-ORD-10456',
            'status'          => 'paid',
        ]);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    /** @test */
    public function it_is_idempotent_on_duplicate_delivery(): void
    {
        $this->postJson('/api/webhooks/payment', $this->payload)->assertCreated();

        $this->postJson('/api/webhooks/payment', $this->payload)
            ->assertOk()
            ->assertJsonFragment(['message' => 'Already processed']);

        $this->assertDatabaseCount('payments', 1);
    }

    // ── Order reference validation ────────────────────────────────────────────

    /** @test */
    public function it_rejects_an_unknown_order_reference_with_422(): void
    {
        $payload = array_merge($this->payload, ['order_reference' => 'SC-ORD-DOESNOTEXIST']);

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Order reference not found.'])
            ->assertJsonFragment(['field' => 'order_reference']);
    }

    /** @test */
    public function it_does_not_persist_a_payment_for_an_unknown_order_reference(): void
    {
        $payload = array_merge($this->payload, ['order_reference' => 'SC-ORD-DOESNOTEXIST']);

        $this->postJson('/api/webhooks/payment', $payload)->assertUnprocessable();

        // No payment row should exist — there was no order to attach it to.
        $this->assertDatabaseMissing('payments', ['transaction_id' => 'QFL3X9Y2KP']);
    }

    /** @test */
    public function it_rejects_a_case_sensitive_mismatch_on_order_reference(): void
    {
        // 'sc-ord-10456' is not the same as 'SC-ORD-10456' — must 422.
        $payload = array_merge($this->payload, ['order_reference' => 'sc-ord-10456']);

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertUnprocessable()
            ->assertJsonFragment(['field' => 'order_reference']);
    }

    // ── Amount validation ─────────────────────────────────────────────────────

    /** @test */
    public function it_rejects_a_completed_payment_where_amount_is_too_low(): void
    {
        // Order total is 2500.00 — paying 2000.00 is an underpayment.
        $payload = array_merge($this->payload, ['amount' => 2000.00]);

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Payment amount does not match order total.'])
            ->assertJsonFragment(['field'    => 'amount'])
            ->assertJsonFragment(['expected' => '2500.00'])
            ->assertJsonFragment(['received' => '2000.00']);
    }

    /** @test */
    public function it_rejects_a_completed_payment_where_amount_is_too_high(): void
    {
        // Overpayment is also a mismatch — could indicate a data entry error.
        $payload = array_merge($this->payload, ['amount' => 3000.00]);

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Payment amount does not match order total.'])
            ->assertJsonFragment(['expected' => '2500.00'])
            ->assertJsonFragment(['received' => '3000.00']);
    }

    /** @test */
    public function it_does_not_persist_a_payment_when_amount_mismatches(): void
    {
        $payload = array_merge($this->payload, ['amount' => 1.00]);

        $this->postJson('/api/webhooks/payment', $payload)->assertUnprocessable();

        $this->assertDatabaseMissing('payments', ['transaction_id' => 'QFL3X9Y2KP']);
        // Order must remain untouched.
        $this->assertDatabaseHas('merchant_orders', [
            'order_reference' => 'SC-ORD-10456',
            'status'          => 'pending',
        ]);
    }

    /** @test */
    public function it_accepts_amount_with_trailing_zero_decimal_difference(): void
    {
        // 2500.0 and 2500.00 must be treated as equal — floating-point formatting quirk.
        $payload = array_merge($this->payload, ['amount' => 2500.0]);

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertCreated()
            ->assertJsonFragment(['note' => 'order_marked_paid']);
    }

    /** @test */
    public function it_does_not_check_amount_for_failed_payments(): void
    {
        // A failed event may carry a different amount (e.g. partial attempt).
        // We record it without rejecting — there's nothing to pay.
        $payload = array_merge($this->payload, [
            'status' => 'failed',
            'amount' => 999.00,  // does NOT match 2500.00, but that's fine for failed
        ]);

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertCreated()
            ->assertJsonFragment(['note' => 'payment_failed_unpaid_order']);

        $this->assertDatabaseHas('payments', ['transaction_id' => 'QFL3X9Y2KP', 'status' => 'failed']);
        $this->assertDatabaseHas('merchant_orders', ['order_reference' => 'SC-ORD-10456', 'status' => 'pending']);
    }

    // ── Order uniqueness (already-paid rejection) ─────────────────────────────

    /** @test */
    public function it_rejects_a_second_completed_payment_for_the_same_order(): void
    {
        // Pay the order successfully first.
        $this->postJson('/api/webhooks/payment', $this->payload)->assertCreated();

        // A brand-new transaction_id tries to pay the same already-settled order.
        $secondPayload = array_merge($this->payload, ['transaction_id' => 'SECOND_TX_001']);

        $this->postJson('/api/webhooks/payment', $secondPayload)
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Order has already been paid.'])
            ->assertJsonFragment(['field'   => 'order_reference']);
    }

    /** @test */
    public function it_does_not_persist_the_second_completed_payment_row(): void
    {
        // First payment succeeds.
        $this->postJson('/api/webhooks/payment', $this->payload)->assertCreated();

        // Second attempt with a different transaction_id — must be rejected AND not written.
        $secondPayload = array_merge($this->payload, ['transaction_id' => 'SECOND_TX_002']);
        $this->postJson('/api/webhooks/payment', $secondPayload)->assertUnprocessable();

        // Only the original payment row should exist.
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseMissing('payments', ['transaction_id' => 'SECOND_TX_002']);
    }

    // ── Failed / reversed payments ────────────────────────────────────────────

    /** @test */
    public function it_persists_a_failed_payment_without_changing_order_status(): void
    {
        $failedPayload = array_merge($this->payload, ['status' => 'failed']);

        $this->postJson('/api/webhooks/payment', $failedPayload)->assertCreated();

        $this->assertDatabaseHas('payments', ['transaction_id' => 'QFL3X9Y2KP', 'status' => 'failed']);
        $this->assertDatabaseHas('merchant_orders', ['order_reference' => 'SC-ORD-10456', 'status' => 'pending']);
    }

    /** @test */
    public function it_marks_a_paid_order_as_pending_refund_on_reversal(): void
    {
        // Pay the order first.
        $this->postJson('/api/webhooks/payment', $this->payload)->assertCreated();

        // Reversal arrives as a separate transaction.
        $reversalPayload = array_merge($this->payload, [
            'transaction_id' => 'REVERSAL_TX_001',
            'status'         => 'reversed',
        ]);

        $this->postJson('/api/webhooks/payment', $reversalPayload)
            ->assertCreated()
            ->assertJsonFragment(['note' => 'reversal_pending_refund']);

        $this->assertDatabaseHas('merchant_orders', [
            'order_reference' => 'SC-ORD-10456',
            'status'          => 'pending_refund',
        ]);
    }
}