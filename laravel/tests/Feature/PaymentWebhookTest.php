<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantOrder;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PaymentWebhookTest
 *
 * Covers the riskiest webhook scenarios:
 *  - Duplicate transaction_id delivered twice (idempotency)
 *  - Two different transactions trying to pay the same order
 *  - Amount mismatch rejection
 *  - Reversal on a paid order → pending_refund
 *  - Unknown order reference → 422, no payment row saved
 *
 * All against silktech_test — dev DB untouched.
 */
class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private MerchantOrder $order;
    private array         $payload;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = Merchant::factory()->create();

        $this->order = MerchantOrder::create([
            'merchant_id'     => $merchant->id,
            'order_reference' => 'SC-ORD-10456',
            'status'          => 'pending',
            'total_amount'    => 2500.00,
        ]);

        $this->payload = [
            'provider'        => 'mpesa',
            'transaction_id'  => 'QFL3X9Y2KP',
            'order_reference' => 'SC-ORD-10456',
            'amount'          => 2500.00,
            'currency'        => 'KES',
            'msisdn'          => '254712345678',
            'status'          => 'completed',
            'occurred_at'     => '2026-06-18T10:32:00Z',
        ];
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_valid_webhook_creates_payment_and_marks_order_paid(): void
    {
        $this->postJson('/api/webhooks/payment', $this->payload)
            ->assertCreated()
            ->assertJsonPath('note', 'order_marked_paid');

        $this->assertDatabaseHas('payments', [
            'transaction_id' => 'QFL3X9Y2KP',
            'status'         => 'completed',
        ]);

        $this->assertDatabaseHas('merchant_orders', [
            'order_reference' => 'SC-ORD-10456',
            'status'          => 'paid',
        ]);
    }

    // ── CRITICAL: duplicate transaction (idempotency) ─────────────────────────

    public function test_duplicate_transaction_id_returns_200_without_writing(): void
    {
        // First delivery
        $this->postJson('/api/webhooks/payment', $this->payload)->assertCreated();

        // Exact same payload again (provider retry)
        $this->postJson('/api/webhooks/payment', $this->payload)
            ->assertOk()
            ->assertJsonPath('message', 'Already processed');

        // Only ONE payment row must exist
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_order_is_not_updated_on_duplicate_delivery(): void
    {
        $this->postJson('/api/webhooks/payment', $this->payload)->assertCreated();

        // Sanity: order is paid after first delivery
        $this->assertDatabaseHas('merchant_orders', ['status' => 'paid']);

        // Duplicate
        $this->postJson('/api/webhooks/payment', $this->payload)->assertOk();

        // Still paid — not toggled or double-processed
        $this->assertDatabaseCount('merchant_orders', 1);
        $this->assertDatabaseHas('merchant_orders', ['status' => 'paid']);
    }

    // ── CRITICAL: second distinct transaction on same order ───────────────────

    public function test_second_completed_transaction_for_paid_order_is_rejected(): void
    {
        // First transaction pays the order
        $this->postJson('/api/webhooks/payment', $this->payload)->assertCreated();

        // Different transaction_id, same order_reference
        $second = array_merge($this->payload, ['transaction_id' => 'SECOND_TX_999']);

        $this->postJson('/api/webhooks/payment', $second)
            ->assertUnprocessable()  // 422
            ->assertJsonPath('message', 'Order has already been paid.');
    }

    public function test_second_transaction_does_not_create_a_payment_row(): void
    {
        $this->postJson('/api/webhooks/payment', $this->payload)->assertCreated();

        $second = array_merge($this->payload, ['transaction_id' => 'SECOND_TX_999']);
        $this->postJson('/api/webhooks/payment', $second)->assertUnprocessable();

        // Only the original payment should exist
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseMissing('payments', ['transaction_id' => 'SECOND_TX_999']);
    }

    // ── CRITICAL: amount mismatch ─────────────────────────────────────────────

    public function test_amount_less_than_order_total_is_rejected(): void
    {
        $payload = array_merge($this->payload, ['amount' => 1000.00]); // order is 2500

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Payment amount does not match order total.')
            ->assertJsonPath('expected', '2500.00')
            ->assertJsonPath('received', '1000.00');
    }

    public function test_amount_greater_than_order_total_is_rejected(): void
    {
        $payload = array_merge($this->payload, ['amount' => 5000.00]);

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('expected', '2500.00')
            ->assertJsonPath('received', '5000.00');
    }

    public function test_amount_mismatch_does_not_persist_payment_or_update_order(): void
    {
        $payload = array_merge($this->payload, ['amount' => 999.00]);

        $this->postJson('/api/webhooks/payment', $payload)->assertUnprocessable();

        $this->assertDatabaseMissing('payments', ['transaction_id' => 'QFL3X9Y2KP']);
        $this->assertDatabaseHas('merchant_orders', ['status' => 'pending']); // untouched
    }

    public function test_amount_formatting_difference_is_not_treated_as_mismatch(): void
    {
        // 2500.0 and 2500.00 must compare as equal
        $payload = array_merge($this->payload, ['amount' => 2500.0]);

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertCreated()
            ->assertJsonPath('note', 'order_marked_paid');
    }

    // ── CRITICAL: unknown order reference ─────────────────────────────────────

    public function test_unknown_order_reference_returns_422(): void
    {
        $payload = array_merge($this->payload, ['order_reference' => 'SC-ORD-DOESNOTEXIST']);

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Order reference not found.');
    }

    public function test_unknown_order_reference_does_not_persist_payment(): void
    {
        $payload = array_merge($this->payload, ['order_reference' => 'SC-ORD-DOESNOTEXIST']);

        $this->postJson('/api/webhooks/payment', $payload)->assertUnprocessable();

        $this->assertDatabaseMissing('payments', ['transaction_id' => 'QFL3X9Y2KP']);
    }

    // ── CRITICAL: reversal after payment ─────────────────────────────────────

    public function test_reversal_on_paid_order_sets_pending_refund(): void
    {
        // Pay the order first
        $this->postJson('/api/webhooks/payment', $this->payload)->assertCreated();

        // Reversal arrives as a new transaction
        $reversal = array_merge($this->payload, [
            'transaction_id' => 'REVERSAL_TX_001',
            'status'         => 'reversed',
        ]);

        $this->postJson('/api/webhooks/payment', $reversal)
            ->assertCreated()
            ->assertJsonPath('note', 'reversal_pending_refund');

        $this->assertDatabaseHas('merchant_orders', [
            'order_reference' => 'SC-ORD-10456',
            'status'          => 'pending_refund',
        ]);
    }

    // ── Failed status — no order update ───────────────────────────────────────

    public function test_failed_payment_is_stored_but_order_stays_pending(): void
    {
        $payload = array_merge($this->payload, ['status' => 'failed']);

        $this->postJson('/api/webhooks/payment', $payload)->assertCreated();

        $this->assertDatabaseHas('payments', [
            'transaction_id' => 'QFL3X9Y2KP',
            'status'         => 'failed',
        ]);

        $this->assertDatabaseHas('merchant_orders', [
            'order_reference' => 'SC-ORD-10456',
            'status'          => 'pending', // not changed
        ]);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_missing_transaction_id_fails_validation(): void
    {
        $payload = $this->payload;
        unset($payload['transaction_id']);

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction_id');
    }

    public function test_invalid_status_fails_validation(): void
    {
        $payload = array_merge($this->payload, ['status' => 'unknown_status']);

        $this->postJson('/api/webhooks/payment', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}