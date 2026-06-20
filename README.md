# Silktech Full-Stack Assessment — Submission

**Candidate:** [Your Name]
**Stack:** PHP 8.1+ / Laravel 10, MySQL, Vue 3 (Composition API), Vitest

---

## UI Screenshots

### Login Screen
![Login screen](docs/screenshots/login.svg)

### Register Screen
![Register screen](docs/screenshots/login.svg)

### Dashboard — Product Grid
![Dashboard](docs/screenshots/dashboard.svg)

### Stock Adjustment — Interaction States
![Stock adjustment states](docs/screenshots/stock-adjustment.svg)

---

## API Documentation

Full interactive API documentation (Postman):
**https://documenter.getpostman.com/view/55427973/2sBXwvJooR**

### Endpoints at a glance

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `POST` | `/api/register` | — | Create merchant account |
| `POST` | `/api/login` | — | Login, returns Bearer token |
| `POST` | `/api/logout` | Bearer | Revoke token |
| `GET` | `/api/products` | Bearer | Paginated product list |
| `POST` | `/api/products` | Bearer | Create product |
| `PATCH` | `/api/products/{id}/stock` | Bearer | Adjust stock by delta |
| `POST` | `/api/webhooks/payment` | Signature | Payment callback handler |

### Authentication

All product endpoints require the `Authorization: Bearer {token}` header. Obtain the token from `/api/login` or `/api/register`. Without it, the API returns:

```json
{ "message": "You need to log in." }
```

### Register — `POST /api/register`

**Request body:**
```json
{
  "name": "test user",
  "business_name": "test business",
  "email": "test@silktech.com",
  "password": "password123"
}
```

**Success `201`:**
```json
{
  "access_token": "4|VKAFn9BuNrZvkeK364k76sNhrzoKFtXaMcjZEwze75b2209b",
  "token_type": "Bearer",
  "merchant": {
    "id": 5,
    "name": "test user",
    "business_name": "test business",
    "email": "test@silktech.com"
  }
}
```

**Error `422` (validation):**
```json
{
  "message": "Validation failed.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```

### Login — `POST /api/login`

**Request body:**
```json
{
  "email": "admin@silktech.com",
  "password": "password123"
}
```

**Success `200`:**
```json
{
  "access_token": "2|YcTZF684ynmCQaapLSiaXwY2TrjG9B0pkU5aDnesaa92e347",
  "token_type": "Bearer"
}
```

**Error `401` (wrong credentials):**
```json
{ "message": "Invalid credentials." }
```

### Logout — `POST /api/logout`

Include the Bearer token in the `Authorization` header. Clears the token server-side. The Vue app also removes it from `localStorage` immediately.

### Get Products — `GET /api/products`

Returns paginated list scoped to the authenticated merchant. Supports `?page=N`.

**Response `200`:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 3,
      "merchant_id": 1,
      "name": "Mach",
      "price": "99.99",
      "category": "Electronics",
      "stock_quantity": 50,
      "created_at": "2026-06-20T01:08:32.000000Z",
      "updated_at": "2026-06-20T01:08:32.000000Z",
      "deleted_at": null
    }
  ],
  "last_page": 1,
  "per_page": 20,
  "total": 1
}
```

### Create Product — `POST /api/products`

**Request body:**
```json
{
  "name": "Mach",
  "price": 99.99,
  "category": "Electronics",
  "stock_quantity": 50
}
```

**Success `201`:**
```json
{
  "id": 3,
  "merchant_id": 1,
  "name": "Mach",
  "price": "99.99",
  "category": "Electronics",
  "stock_quantity": 50,
  "created_at": "2026-06-20T01:08:32.000000Z",
  "updated_at": "2026-06-20T01:08:32.000000Z"
}
```

### Adjust Stock — `PATCH /api/products/{id}/stock`

**Request body:**
```json
{ "delta": 10 }
```

Use a positive number to add stock (`10`), negative to remove (`-5`). The API rejects any delta that would take stock below zero.

**Success `200`:**
```json
{
  "id": 3,
  "name": "Mach",
  "stock_quantity": 60
}
```

**Error `422` (insufficient stock):**
```json
{ "message": "Insufficient stock. Current: 3, adjustment: -10." }
```

### Payment Webhook — `POST /api/webhooks/payment`

See the [Webhook section](#part-1--idempotent-payment-webhook) below for full details.

---

## Setup

### Laravel (Parts 1–3)

```bash
cd laravel
composer install
cp .env.example .env          # fill in DB_* vars
php artisan key:generate
php artisan migrate
php artisan db:seed           # creates demo@silktech.io / password
php artisan test              # runs all PHPUnit tests
```

### Vue (Part 4)

```bash
cd vue
npm install
npm run dev                   # Vite dev server → http://localhost:5173
npm run test                  # Vitest unit tests
```

### Docker (recommended — no local PHP/Node install needed)

```bash
# First time setup
make setup

# Start everything
make up

# Run tests
make test-backend
make test-frontend

# Open a shell inside Laravel container
make shell
```

Services started by Docker:

| Service | URL |
|---------|-----|
| Vue dev server | http://localhost:5173 |
| Laravel API | http://localhost:8000 |
| MySQL | localhost:3306 |
| Redis | localhost:6379 |

---

## Assumptions

- Auth uses Laravel Sanctum (token-based). The `auth:sanctum` guard is referenced in routes.
- Webhook provider authentication (HMAC signature verification) is handled in a `webhook.signature` middleware registered separately. The hook point is clearly marked in `routes/api.php`.
- `merchants` and `merchant_orders` tables are pre-existing per the spec; a migration for them is included for local dev/test convenience, clearly commented.
- The `order_reference` field on `payments` matches `merchant_orders.order_reference` directly (no join through a numeric FK), which keeps the webhook handler simpler and avoids one extra query.

---

## Part 1 — Idempotent Payment Webhook

### Idempotency strategy

Two layers of protection work together:

1. **Application check (fast path):** Before opening a transaction, we check whether `payments.transaction_id` already exists. If it does, return `200` immediately — no DB write, no lock contention.

2. **DB unique constraint + `lockForUpdate` (race condition path):** Two simultaneous duplicate deliveries can both pass the application check in the same millisecond. Inside the DB transaction, `lockForUpdate()` on the order row serialises concurrent updates. The `UNIQUE` constraint on `payments.transaction_id` catches the second inserter and raises a `UniqueConstraintViolationException`, which we convert to a `200` response.

### Race condition — two retries at the same instant

Both pass the initial check simultaneously. Both attempt `INSERT INTO payments`. The DB unique constraint lets only one succeed. The loser gets a `UniqueConstraintViolation` exception — caught and returned as `200 Already processed (concurrent)`. No duplicate row, no double order update.

### Unknown or already-paid order

- **Order not found:** Return `422` — tells the provider the payload is bad, stop retrying. No payment row is created (nothing to attach it to).
- **Amount mismatch:** Return `422` with `expected` and `received` fields — prevents partial payments being accepted as settled.
- **Order already paid by a different transaction:** Return `422` — second completed payment for same order is rejected outright. No extra row written.

### `failed` or `reversed` arriving after `completed`

Payment events are persisted for audit. A `reversed` event on a paid order flags it as `pending_refund` for ops review — we never auto-refund, that has accounting side effects that deserve their own workflow.

---

## Testing the Webhook Locally with ngrok

ngrok creates a public HTTPS URL that tunnels to your local server, letting M-Pesa (or any provider) send real callbacks to `localhost:8000` during development.

```bash
# Install: https://ngrok.com/download
# Then:
php artisan serve          # terminal 1
./ngrok http 8000          # terminal 2
```

Copy the generated URL (e.g. `https://abc123.ngrok.io`) and register it with the provider as:
```
https://abc123.ngrok.io/api/webhooks/payment
```

The ngrok dashboard at `http://127.0.0.1:4040` shows every request and response in real time, making it easy to debug webhook payloads without touching production.

---

## Webhook Request Documentation (Postman)

### Sample Payload

```json
{
  "provider": "mpesa",
  "transaction_id": "QFL3X9Y2KP",
  "order_reference": "SC-ORD-10456",
  "amount": 2500.00,
  "currency": "KES",
  "msisdn": "254712345678",
  "status": "completed",
  "occurred_at": "2026-06-18T10:32:00Z"
}
```

| Field | Description |
|-------|-------------|
| `provider` | Payment network: `mpesa`, `airtel`, `stripe`, etc. |
| `transaction_id` | Provider's unique ID — the idempotency key |
| `order_reference` | Links to `merchant_orders.order_reference` |
| `amount` | Must exactly match `merchant_orders.total_amount` |
| `currency` | ISO 4217 code (e.g. `KES`) |
| `msisdn` | Payer's phone number (M-Pesa specific, nullable) |
| `status` | One of `completed`, `failed`, `reversed` |
| `occurred_at` | ISO 8601 timestamp of the payment event |

### Response Codes

| Status | Scenario |
|--------|----------|
| `200 OK` | Duplicate — already processed, no action taken |
| `201 Created` | New payment processed, order updated to `paid` |
| `422 Unprocessable` | Bad reference, amount mismatch, or order already paid |
| `500 Internal Server Error` | DB failure — provider should retry |

### Idempotency Testing (Postman)

1. `POST` the sample payload → expect `201`, order is `paid`
2. `POST` the exact same payload again → expect `200 Already processed`
3. Change `transaction_id`, keep everything else → expect `422 Order already paid`
4. Use Postman **Collection Runner** with 5 concurrent requests to prove only 1 payment row is created

---

## Database Schema

```
payments(
  id,
  transaction_id    UNIQUE,   ← DB-level duplicate guard
  provider,
  order_reference,
  amount,
  currency,
  msisdn,
  status,
  occurred_at,
  raw_payload       JSON,     ← full payload stored for audit/replay
  created_at,
  updated_at
)

merchant_orders(
  id,
  merchant_id,
  order_reference   UNIQUE,
  status            ENUM(pending, paid, pending_refund, cancelled),
  total_amount,
  created_at,
  updated_at
)

products(
  id,
  merchant_id,
  name,
  price             DECIMAL(12,2),
  category,
  stock_quantity    UNSIGNED INT,
  deleted_at,       ← soft delete
  created_at,
  updated_at
)
```

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
Order::create(['cart_id' => $cart->id, 'status' => 'confirmed']);
```

### Bug 1 — Race condition (read-modify-write)

`Product::find()` reads stock into PHP memory. Between `find()` and `save()`, a concurrent request reads the same stale value. Both deduct and save — overselling.

**Fix:** Single atomic SQL UPDATE inside a transaction:
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

### Bug 2 — Order confirmed even when stock check silently fails

If any item fails `stock_quantity >= $item->quantity`, the `if` block is skipped silently, but `Order::create()` runs anyway — confirmed order for unreserved items.

**Fix:** Throw inside the loop on failure; wrap everything in a transaction so `Order::create()` only runs if all items succeed.

### Bug 3 — No transaction wrapping

A crash mid-loop leaves partially deducted stock with no order created — inconsistent state with no way to recover.

**Fix:** Entire loop + `Order::create()` must be inside `DB::transaction()`.

---

## Part 5 — Short Answers

### M-Pesa paid but order still shows "pending" after 20 minutes

1. Check `payments` table for the `transaction_id` — did the webhook arrive at all?
2. Check application logs for the webhook endpoint — validation errors, `order_not_found` warnings?
3. Check the M-Pesa dashboard — did they record a successful delivery? Non-200 from us = they'll retry.
4. Check `order_reference` format exactly — `SC-ORD-10456` vs `SCORD10456` would miss.
5. Check if our server returned `500` — retries may still be in-flight.
6. If M-Pesa confirms payment but we have no row — manually replay from the raw payload in their dashboard.

---

### Adding Airtel Money without rewriting the webhook handler

Use a **normaliser/adapter pattern**:

```
IncomingRequest → ProviderNormaliser → NormalisedPaymentDTO → WebhookHandler
```

- `PaymentNormaliserInterface` defines `normalise(array $raw): NormalisedPaymentDTO`
- `MpesaNormaliser`, `AirtelNormaliser` each implement it
- A factory resolves the right normaliser from the `provider` field in the URL/header
- The core handler only ever sees `NormalisedPaymentDTO` — no provider-specific code

Adding Airtel means one new `AirtelNormaliser` class. The handler, idempotency logic, and order-update logic are untouched.

---

### Two merchants click "+5 stock" from different browser tabs simultaneously

**Risk:** Both tabs read `currentStock: 10`, both compute `15` locally, both succeed — but one tab now shows a stale value.

**How the component avoids it:**
- No optimistic updates — displayed stock only changes *after* the server responds with `stock_quantity`
- `stock-updated` event passes the server's authoritative value, not local arithmetic
- Backend uses atomic `increment()`/`decrement()` — `UPDATE stock = stock + ?` — so both requests apply correctly at the DB level regardless of order
- Real-world improvement: WebSocket/SSE channel to push stock changes to all open tabs

---

### One thing to know before touching payments infrastructure in production

**What is the provider's retry policy and deduplication window?**

How long do they retry on non-200, at what intervals, and do they deduplicate on their side? This determines how long our idempotency window must cover and whether we need a dead-letter queue for events that fall off the retry window. Without knowing this, a handler change could silently drop payments that fail and are never replayed.

---

