<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantOrder;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MerchantOrderIntegrityTest
 *
 * Tests the state machine and data integrity rules on merchant_orders and payments:
 *  - Order transitions: pending → paid → pending_refund
 *  - Payments table always has a raw_payload for audit replay
 *  - A failed payment never changes order status
 *  - Multiple payments can exist for one order (audit trail), but only one completes it
 *  - order_reference must be unique in merchant_orders
 *
 * These tests exercise the database schema and model relationships directly,
 * not just the HTTP layer.
 */
class MerchantOrderIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Merchant      $merchant;
    private MerchantOrder $order;
    private array         $basePayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();

        $this->order = MerchantOrder::create([
            'merchant_id'     => $this->merchant->id,
            'order_reference' => 'SC-ORD-99001',
            'status'          => 'pending',
            'total_amount'    => 5000.00,
        ]);

        $this->basePayload = [
            'provider'        => 'mpesa',
            'transaction_id'  => 'TXN-INTEGRITY-001',
            'order_reference' => 'SC-ORD-99001',
            'amount'          => 5000.00,
            'currency'        => 'KES',
            'msisdn'          => '254700000001',
            'status'          => 'completed',
            'occurred_at'     => '2026-06-20T10:00:00Z',
        ];
    }

    // ── Order state machine ───────────────────────────────────────────────────

    public function test_order_starts_as_pending(): void
    {
        $this->assertEquals('pending', $this->order->fresh()->status);
    }

    public function test_completed_webhook_transitions_order_to_paid(): void
    {
        $this->postJson('/api/webhooks/payment', $this->basePayload);

        $this->assertEquals('paid', $this->order->fresh()->status);
    }

    public function test_failed_webhook_does_not_transition_order_from_pending(): void
    {
        $payload = array_merge($this->basePayload, ['status' => 'failed']);

        $this->postJson('/api/webhooks/payment', $payload);

        $this->assertEquals('pending', $this->order->fresh()->status);
    }

    public function test_reversal_transitions_paid_order_to_pending_refund(): void
    {
        // First pay it
        $this->postJson('/api/webhooks/payment', $this->basePayload);
        $this->assertEquals('paid', $this->order->fresh()->status);

        // Then reverse it
        $reversal = array_merge($this->basePayload, [
            'transaction_id' => 'TXN-REVERSAL-001',
            'status'         => 'reversed',
        ]);
        $this->postJson('/api/webhooks/payment', $reversal);

        $this->assertEquals('pending_refund', $this->order->fresh()->status);
    }

    public function test_order_cannot_skip_from_pending_directly_to_pending_refund(): void
    {
        // A reversal on a pending (unpaid) order should not set pending_refund
        $reversal = array_merge($this->basePayload, ['status' => 'reversed']);

        $this->postJson('/api/webhooks/payment', $reversal);

        // Order was never paid, so no refund state — remains pending
        $this->assertEquals('pending', $this->order->fresh()->status);
    }

    // ── Payments audit trail ──────────────────────────────────────────────────

    public function test_payment_row_stores_raw_payload_for_audit_replay(): void
    {
        $this->postJson('/api/webhooks/payment', $this->basePayload);

        $payment = Payment::where('transaction_id', 'TXN-INTEGRITY-001')->first();

        $this->assertNotNull($payment);
        $this->assertNotNull($payment->raw_payload);

        // raw_payload must contain the original transaction_id for replay
        $payload = $payment->raw_payload;
        $this->assertEquals('TXN-INTEGRITY-001', $payload['transaction_id']);
        $this->assertEquals('SC-ORD-99001', $payload['order_reference']);
    }

    public function test_failed_payment_is_still_stored_for_audit(): void
    {
        $payload = array_merge($this->basePayload, ['status' => 'failed']);

        $this->postJson('/api/webhooks/payment', $payload);

        $this->assertDatabaseHas('payments', [
            'transaction_id' => 'TXN-INTEGRITY-001',
            'status'         => 'failed',
        ]);
    }

    public function test_reversal_creates_a_second_payment_row(): void
    {
        // Pay
        $this->postJson('/api/webhooks/payment', $this->basePayload);

        // Reverse
        $reversal = array_merge($this->basePayload, [
            'transaction_id' => 'TXN-REVERSAL-AUDIT',
            'status'         => 'reversed',
        ]);
        $this->postJson('/api/webhooks/payment', $reversal);

        // Both rows exist — original payment + reversal record
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['transaction_id' => 'TXN-INTEGRITY-001', 'status' => 'completed']);
        $this->assertDatabaseHas('payments', ['transaction_id' => 'TXN-REVERSAL-AUDIT', 'status' => 'reversed']);
    }

    // ── Order–payment relationship ─────────────────────────────────────────────

    public function test_order_payments_relationship_returns_associated_payments(): void
    {
        $this->postJson('/api/webhooks/payment', $this->basePayload);

        $this->order->refresh();
        $payments = $this->order->payments;

        $this->assertCount(1, $payments);
        $this->assertEquals('TXN-INTEGRITY-001', $payments->first()->transaction_id);
    }

    public function test_isPaid_returns_true_only_after_completed_payment(): void
    {
        $this->assertFalse($this->order->isPaid());

        $this->postJson('/api/webhooks/payment', $this->basePayload);

        $this->assertTrue($this->order->fresh()->isPaid());
    }

    // ── DB schema constraints ─────────────────────────────────────────────────

    public function test_order_reference_must_be_unique_in_merchant_orders(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Try to insert a duplicate order_reference — must throw unique constraint violation
        MerchantOrder::create([
            'merchant_id'     => $this->merchant->id,
            'order_reference' => 'SC-ORD-99001', // already exists in setUp()
            'status'          => 'pending',
            'total_amount'    => 1000.00,
        ]);
    }

    public function test_transaction_id_must_be_unique_in_payments(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Insert same transaction_id twice directly — must throw
        $data = [
            'transaction_id'  => 'DUPE-TX-001',
            'provider'        => 'mpesa',
            'order_reference' => 'SC-ORD-99001',
            'amount'          => 5000.00,
            'currency'        => 'KES',
            'status'          => 'completed',
            'occurred_at'     => now(),
            'raw_payload'     => json_encode([]),
        ];

        Payment::create($data);
        Payment::create($data); // must throw UniqueConstraintViolation
    }

    public function test_payment_belongs_to_correct_order_via_order_reference(): void
    {
        // Create a second order with a different reference
        $otherOrder = MerchantOrder::create([
            'merchant_id'     => $this->merchant->id,
            'order_reference' => 'SC-ORD-99002',
            'status'          => 'pending',
            'total_amount'    => 1000.00,
        ]);

        $this->postJson('/api/webhooks/payment', $this->basePayload); // pays SC-ORD-99001

        // Only order 99001 should be paid
        $this->assertEquals('paid',    $this->order->fresh()->status);
        $this->assertEquals('pending', $otherOrder->fresh()->status);
    }
}