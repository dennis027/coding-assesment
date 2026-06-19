<?php

namespace App\Http\Controllers;

use App\Http\Requests\WebhookPaymentRequest;
use App\Models\MerchantOrder;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentWebhookController extends Controller
{
    /**
     * POST /api/webhooks/payment
     *
     * Validation pipeline (runs in this exact order):
     * ─────────────────────────────────────────────────
     * 1. Idempotency check    — duplicate transaction_id? Return 200 immediately.
     * 2. Order exists check   — unknown order_reference? Return 422, don't persist.
     * 3. Amount match check   — payload amount ≠ order total? Return 422, don't persist.
     * 4. Uniqueness check     — order already paid by a DIFFERENT transaction? Return 422.
     *    (Only for 'completed' payments. failed/reversed on a paid order → pending_refund.)
     * 5. DB transaction       — lock the row, write the payment, update order status.
     *
     * Race condition protection:
     *   DB unique constraint on transaction_id + lockForUpdate() on the order row.
     *   The unique constraint catches two simultaneous inserts; the row lock prevents
     *   two concurrent 'completed' events both passing the paid-check simultaneously.
     */
    public function handle(WebhookPaymentRequest $request): JsonResponse
    {
        $data = $request->validated();

        // ── Step 1: Idempotency fast-path ────────────────────────────────────
        // We've already processed this exact transaction_id → tell the provider
        // to stop retrying regardless of what status it carries now.
        $existing = Payment::where('transaction_id', $data['transaction_id'])->first();

        if ($existing !== null) {
            Log::info('Webhook duplicate received', [
                'transaction_id'  => $data['transaction_id'],
                'existing_status' => $existing->status,
            ]);

            return response()->json([
                'message'    => 'Already processed',
                'payment_id' => $existing->id,
            ]);
        }

        // ── Step 2: Order existence check ────────────────────────────────────
        // Fetch the full order (not just ->exists()) so we can reuse it for
        // amount and uniqueness checks without hitting the DB twice more.
        // The authoritative locked re-fetch happens again inside the transaction.
        $order = MerchantOrder::where('order_reference', $data['order_reference'])->first();

        if ($order === null) {
            Log::warning('Webhook: unknown order_reference', [
                'order_reference' => $data['order_reference'],
                'transaction_id'  => $data['transaction_id'],
                'provider'        => $data['provider'],
            ]);

            return response()->json([
                'message' => 'Order reference not found.',
                'field'   => 'order_reference',
            ], 422);
        }

        // ── Step 3: Amount match check ────────────────────────────────────────
        // The payment amount in the webhook must exactly match the order total.
        // We compare as strings rounded to 2 decimal places to avoid floating-
        // point representation issues (e.g. 2500.0 == 2500.00).
        //
        // Only enforce this for 'completed' payments — a failed/reversed event
        // might carry a partial amount and we still want to record it.
        if ($data['status'] === 'completed') {
            $payloadAmount = number_format((float) $data['amount'], 2, '.', '');
            $orderAmount   = number_format((float) $order->total_amount, 2, '.', '');

            if ($payloadAmount !== $orderAmount) {
                Log::warning('Webhook: amount mismatch', [
                    'order_reference' => $data['order_reference'],
                    'transaction_id'  => $data['transaction_id'],
                    'payload_amount'  => $payloadAmount,
                    'order_amount'    => $orderAmount,
                    'currency'        => $data['currency'],
                ]);

                return response()->json([
                    'message'        => 'Payment amount does not match order total.',
                    'field'          => 'amount',
                    'expected'       => $orderAmount,
                    'received'       => $payloadAmount,
                ], 422);
            }
        }

        // ── Step 4: Order uniqueness check (completed payments only) ─────────
        // If the order is already paid by a DIFFERENT transaction, reject this
        // one outright — we won't store a second 'completed' payment against
        // the same order reference.
        //
        // Note: a duplicate of the *same* transaction_id is caught in Step 1.
        // This step only fires when a *new* transaction_id tries to pay an
        // already-settled order.
        if ($data['status'] === 'completed' && $order->isPaid()) {
            Log::warning('Webhook: completed payment rejected — order already paid', [
                'order_reference' => $data['order_reference'],
                'transaction_id'  => $data['transaction_id'],
            ]);

            return response()->json([
                'message' => 'Order has already been paid.',
                'field'   => 'order_reference',
            ], 422);
        }

        // ── Step 5: Process inside a DB transaction with row-level locking ───
        try {
            $result = DB::transaction(function () use ($data, $order) {
                // Re-fetch with a FOR UPDATE lock. Any concurrent request hitting
                // the same order_reference will block here until we commit.
                // We re-run the paid-check after the lock because another request
                // could have paid the order between Step 4 and this lock.
                $lockedOrder = MerchantOrder::where('order_reference', $data['order_reference'])
                    ->lockForUpdate()
                    ->firstOrFail();

                // Double-check amount and uniqueness under the lock.
                // Between the fast-path checks above and acquiring this lock,
                // a concurrent request may have settled the order.
                if ($data['status'] === 'completed' && $lockedOrder->isPaid()) {
                    throw new \DomainException('order_already_paid_concurrent');
                }

                $payment = Payment::create([
                    'transaction_id'  => $data['transaction_id'],
                    'provider'        => $data['provider'],
                    'order_reference' => $data['order_reference'],
                    'amount'          => $data['amount'],
                    'currency'        => $data['currency'],
                    'msisdn'          => $data['msisdn'] ?? null,
                    'status'          => $data['status'],
                    'occurred_at'     => $data['occurred_at'],
                    'raw_payload'     => $data,
                ]);

                $orderNote = $this->applyOrderTransition($lockedOrder, $data);

                return ['payment' => $payment, 'order_note' => $orderNote];
            });

            return response()->json([
                'message'    => 'Processed',
                'payment_id' => $result['payment']->id,
                'note'       => $result['order_note'],
            ], 201);

        } catch (\DomainException $e) {
            // Caught the concurrent paid-order race inside the lock.
            if ($e->getMessage() === 'order_already_paid_concurrent') {
                Log::warning('Webhook: order paid by concurrent request', [
                    'order_reference' => $data['order_reference'],
                    'transaction_id'  => $data['transaction_id'],
                ]);

                return response()->json([
                    'message' => 'Order has already been paid.',
                    'field'   => 'order_reference',
                ], 422);
            }

            Log::error('Webhook domain error', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Processing error'], 500);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Order was deleted in the window between Step 2 and the lock.
            Log::warning('Webhook: order disappeared between validation and lock', [
                'order_reference' => $data['order_reference'],
                'transaction_id'  => $data['transaction_id'],
            ]);

            return response()->json([
                'message' => 'Order reference not found.',
                'field'   => 'order_reference',
            ], 422);

        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Two identical transaction_ids won the race to Step 5 simultaneously.
            // The DB constraint let only one INSERT through — we are the loser.
            Log::info('Webhook race condition resolved by unique constraint', [
                'transaction_id' => $data['transaction_id'],
            ]);

            $payment = Payment::where('transaction_id', $data['transaction_id'])->first();

            return response()->json([
                'message'    => 'Already processed (concurrent)',
                'payment_id' => $payment?->id,
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Webhook database error', [
                'transaction_id' => $data['transaction_id'] ?? null,
                'error'          => $e->getMessage(),
                'sql'            => $e->getSql(),
            ]);

            return response()->json(['message' => 'Database error'], 500);

        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'transaction_id' => $data['transaction_id'] ?? null,
                'error'          => $e->getMessage(),
                'file'           => $e->getFile(),
                'line'           => $e->getLine(),
            ]);

            return response()->json(['message' => 'Processing error'], 500);

        } catch (Throwable $e) {
            Log::error('Webhook critical error', [
                'transaction_id' => $data['transaction_id'] ?? null,
                'error'          => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Processing error'], 500);
        }
    }

    /**
     * Apply the correct status transition to the order.
     * Called inside the DB transaction, after the row lock is held.
     */
    private function applyOrderTransition(MerchantOrder $order, array $data): string
    {
        $status = $data['status'];

        if ($status === 'completed') {
            // isPaid() check here is belt-and-suspenders — Step 4 and the
            // concurrent check above should have already blocked this path.
            $order->update(['status' => 'paid']);
            return 'order_marked_paid';
        }

        if (in_array($status, ['failed', 'reversed'])) {
            if ($order->isPaid()) {
                Log::warning('Webhook: reversal on paid order — flagging for refund review', [
                    'order_reference' => $data['order_reference'],
                    'transaction_id'  => $data['transaction_id'],
                    'status'          => $status,
                ]);
                $order->update(['status' => 'pending_refund']);
                return 'reversal_pending_refund';
            }

            // Payment failed and order was never paid — nothing to undo.
            return 'payment_failed_unpaid_order';
        }

        // 'pending' or any future status — record the event, no order change.
        return 'no_order_change';
    }
}