# Silktech Full-Stack Assessment — Submission

**Candidate:** [Your Name]  
**Stack:** PHP 8.1+ / Laravel 10, MySQL, Vue 3 (Composition API), Vitest  

---

## Setup

### Laravel (Parts 1–3)

```bash
cd laravel
composer install
cp .env.example .env          # configure DB_* vars
php artisan key:generate
php artisan migrate
php artisan test              # runs all PHPUnit/Pest tests
```

### Vue (Part 4)

```bash
cd vue
npm install
npm run dev                   # Vite dev server
npm run test                  # Vitest unit tests
```

---

## Time breakdown

| Part | Time |
|------|------|
| Part 1 — Webhook handler | ~60 min |
| Part 2 — Product API | ~45 min |
| Part 3 — SQL & bug | ~20 min |
| Part 4 — Vue component | ~55 min |
| Part 4b — Unit tests | ~30 min |
| Part 5 — Short answers & README | ~30 min |

---

## Assumptions

- Auth uses Laravel Sanctum (token-based). The `auth:sanctum` guard is referenced in routes; a standard Sanctum install suffices.
- Webhook provider authentication (HMAC signature verification) is handled in a `webhook.signature` middleware registered separately. The skeleton for that middleware is not included here—real implementations vary per provider—but the hook point is clearly marked in `routes/api.php`.
- `merchants` and `merchant_orders` tables are pre-existing per the spec; I've included a migration for them for local dev/test convenience, clearly commented.
- The `order_reference` field on `payments` matches `merchant_orders.order_reference` directly (no join through a numeric FK), which keeps the webhook handler simpler and avoids one query. A FK-based design is equally valid.

---

## Part 1 — Idempotent Payment Webhook

### Idempotency strategy

Two layers of protection work together:

1. **Application check (fast path):** Before opening a transaction, we check whether `payments.transaction_id` already exists. If it does, we return `200` immediately—no DB write, no lock contention.

2. **DB unique constraint + `lockForUpdate` (race condition path):** Two simultaneous duplicate deliveries can both pass the application check in the same millisecond. Inside the DB transaction, `lockForUpdate()` on the order row serialises concurrent updates to that order. The `UNIQUE` constraint on `payments.transaction_id` catches the second inserter and raises a `UniqueConstraintViolationException`, which we catch and convert to a `200` response.

### Race condition — two retries at the same instant

> What happens if two retries hit your server at the same instant?

Both pass the initial "does this transaction_id exist?" check simultaneously. They both attempt `INSERT INTO payments`. The DB unique constraint lets only one succeed. The loser gets a `UniqueConstraintViolation` exception, which is caught and turned into a `200 Already processed (concurrent)` response. No duplicate row, no double order update.

### Unknown or already-paid order

- **Order not found:** We persist the payment record (for audit/replay) and log a warning. We still return `201` so the provider stops retrying. The ops team can investigate via logs.
- **Order already paid by a different transaction:** We record the new payment row (audit trail) but do not re-process or modify the order. The response includes a `note: order_already_paid` for observability.

### `failed` or `reversed` arriving after `completed`

We persist every event. A `failed` event for a `transaction_id` that never previously completed has no effect on the order. A `reversed` event arriving after a `completed` event is recorded, but deliberately does **not** automatically re-open the order—reversals involve accounting side effects (refunding a balance, notifying the merchant) that deserve their own workflow, not a webhook side-effect. An ops alert on `reversed` events is the right next step.

---

## Part 3b — Bug Analysis

```php
foreach ($cart->items as $item) {
    $product = Product::find($item->product_id);
    if ($product->stock_quantity >= $item->quantity) {
        $product->stock_quantity = $product->stock_quantity - $item->quantity;
        $product->save();
    }
}

Order::create([
    'cart_id' => $cart->id,
    'status' => 'confirmed',
]);
```

### Bug 1 — Race condition / no atomic update

`Product::find()` reads stock into PHP memory. Between the `find()` and `save()`, another concurrent request can read the same stale value and both deduct. A cart with 5 items also processes them one by one, so if stock is 3 and a concurrent request runs in between item 2 and item 3, you can oversell.

**Fix:** Use `lockForUpdate()` inside a DB transaction, or use an atomic `UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?`. Only update if the WHERE matches; check `affected rows` to detect failure.

```php
DB::transaction(function () use ($cart) {
    foreach ($cart->items as $item) {
        $affected = DB::table('products')
            ->where('id', $item->product_id)
            ->where('stock_quantity', '>=', $item->quantity)
            ->update(['stock_quantity' => DB::raw("stock_quantity - {$item->quantity}")]);

        if ($affected === 0) {
            throw new \RuntimeException("Insufficient stock for product {$item->product_id}");
        }
    }

    Order::create(['cart_id' => $cart->id, 'status' => 'confirmed']);
});
```

### Bug 2 — Order created even when stock check fails

If `$product->stock_quantity < $item->quantity` for any item, the `if` block is silently skipped — stock is not deducted — but the `Order::create()` runs anyway, creating a confirmed order for items that were never actually reserved. The customer gets an order confirmation for stock that wasn't committed.

**Fix:** Throw an exception (or return an error) inside the loop when stock is insufficient, and wrap everything in a transaction so the order is only created when all stock deductions succeed.

### Bug 3 (bonus) — No transaction wrapping

If the process crashes mid-loop (e.g. after deducting items 1–3 but before item 4), you end up with partially deducted stock and no order. All stock changes and the order creation must be wrapped in a single DB transaction.

---

## Part 5 — Short Answers

### Part 5: M-Pesa paid but order still shows "pending" after 20 minutes

1. **Check `payments` for the `transaction_id`.** Did the webhook arrive at all? If there's no row, the callback never reached us—or it arrived and failed validation silently.

2. **Check application logs** around the timestamp for the webhook endpoint. Look for errors, validation failures, or the `order_not_found` note that gets logged if the `order_reference` didn't match.

3. **Check the provider dashboard.** Did M-Pesa record a successful callback delivery? If they show a non-200 response from us, or no delivery attempt, the callback never left their side (timing delay, their outage).

4. **Check the `merchant_orders` table.** Is the order's `order_reference` exactly what's in the payload? A formatting mismatch (e.g. `SC-ORD-10456` vs `SCORD10456`) would trigger `order_not_found`.

5. **Check whether our server returned 500** at any point, causing M-Pesa to retry. If retries are still in-flight, the order might be updated imminently.

6. **If the payment is confirmed by M-Pesa but we have no record,** manually replay the webhook using the raw payload from the provider dashboard and reconcile the order.

---

### Part 6: Adding a new provider (Airtel Money) without rewriting the handler

The ingestion layer should use a **normaliser/adapter pattern**:

```
IncomingRequest → ProviderNormaliser (per-provider) → NormalisedPaymentDTO → WebhookHandler
```

- A `PaymentNormaliserInterface` defines `normalise(array $rawPayload): NormalisedPaymentDTO`.
- Each provider (`MpesaNormaliser`, `AirtelNormaliser`) implements it, mapping their shape to the common DTO.
- A `NormaliserFactory` (or Laravel's service container) resolves the right normaliser from a `provider` field in the URL or a signature header.
- The core `WebhookHandler` only ever sees `NormalisedPaymentDTO` — it never knows which provider sent the event.

Adding Airtel Money means writing one new `AirtelNormaliser` class and registering it in the factory. The handler, idempotency logic, and order-update logic are untouched.

---

### Part 7: Two merchants click "+5 stock" within a second from different tabs

**What could go wrong on the frontend:**

If both tabs read `currentStock: 10` from the initial page load, apply `delta: +5` locally, and both succeed, the displayed stock in each tab will show 15 — but the server has the correct value (20, from two atomic increments). The UX is inconsistent; one tab is stale.

More critically: if the same *product* is shown in both tabs and the component holds local state, both tabs could independently display an optimistic count that diverges from the server truth.

**How the component design avoids it:**

- We **don't do optimistic updates**. The displayed `stock` value is only updated *after* a successful server response, using the server's returned `stock_quantity` as the authoritative value.
- The `stock-updated` event carries the server's value. A parent list/table that listens to this event re-renders from the server response, not from local arithmetic.
- The backend uses `increment()`/`decrement()` (atomic SQL `UPDATE ... SET stock = stock + ?`) rather than a read-modify-write, so concurrent requests from both tabs each apply correctly at the DB level.
- For multi-tab consistency, a real-world improvement would be a WebSocket/SSE channel that pushes stock updates to all open sessions for a product.

---

### Part 8: One thing I'd want to know before touching the payments infrastructure in production

**Are there existing retry/replay mechanisms and what's their retry policy?**

Specifically: if a webhook fails, how long does the provider retry, at what intervals, and is there any deduplication on their side? This determines how long our idempotency window needs to be and whether we need a dead-letter queue for failed events that fall off the retry window. Touching the handler without knowing this could introduce silent payment loss—events that failed and were never replayed, with no alert and no way to reconcile them.
# coding-assesment
