<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdjustStockRequest;
use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * GET /api/products
     *
     * Paginated list scoped to the authenticated merchant.
     * Optional query params:
     *   ?category=Electronics
     *   ?in_stock=true|false|1|0
     *   ?sort_by=price|created_at   (default: created_at)
     *   ?order=asc|desc             (default: desc)
     *   ?per_page=20                (default: 20, max: 100)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['sometimes', 'string', 'max:100'],
            // 'boolean' rule rejects the strings "true"/"false" from URL query strings.
            // 'in:' whitelists all four representations a query string can carry.
            'in_stock' => ['sometimes', 'in:true,false,1,0'],
            'sort_by'  => ['sometimes', 'in:price,created_at'],
            'order'    => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::forMerchant($request->user()->id);

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        if ($request->has('in_stock')) {
            // $request->boolean() correctly converts "true"/"1"/true → true
            $query->inStock($request->boolean('in_stock'));
        }

        $query->orderBy(
            $request->input('sort_by', 'created_at'),
            $request->input('order', 'desc')
        );

        return response()->json(
            $query->paginate($request->integer('per_page', 20))
        );
    }

    /**
     * POST /api/products
     *
     * Create a product for the authenticated merchant.
     * merchant_id is set automatically via the relationship.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $request->user()
            ->products()
            ->create($request->validated());

        return response()->json($product, 201);
    }

    /**
     * PATCH /api/products/{product}/stock
     *
     * Atomically adjust stock by `delta`.
     *   Positive delta → restock  (e.g. { "delta": 10 })
     *   Negative delta → sale     (e.g. { "delta": -5 })
     *
     * Protection layers:
     *   1. Ownership check before opening any transaction (fast 403 path).
     *   2. SELECT FOR UPDATE inside the transaction so concurrent PATCHes
     *      on the same product queue up rather than racing.
     *   3. Re-verify ownership inside the lock (belt-and-suspenders).
     *   4. DomainException rolls the transaction back if stock would go negative.
     */
    public function adjustStock(AdjustStockRequest $request, Product $product): JsonResponse
    {
        // ── Layer 1: fast ownership check ────────────────────────────────────
        if ((int) $product->merchant_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'This action is unauthorized. You do not own this product.',
            ], 403);
        }

        $delta = $request->integer('delta');

        try {
            DB::transaction(function () use ($product, $delta) {
                // ── Layer 2 & 3: row lock + re-verify ownership ───────────────
                $fresh = Product::lockForUpdate()
                    ->where('merchant_id', auth()->id())
                    ->findOrFail($product->id);

                $newStock = $fresh->stock_quantity + $delta;

                // ── Layer 4: negative stock guard ─────────────────────────────
                if ($newStock < 0) {
                    throw new \DomainException(
                        "Stock adjustment failed: current stock is {$fresh->stock_quantity}, " .
                        "delta is {$delta}, which would result in {$newStock}. " .
                        "Stock cannot be negative."
                    );
                }

                // Single atomic SQL UPDATE — no read-modify-write race possible
                if ($delta > 0) {
                    $fresh->increment('stock_quantity', $delta);
                } elseif ($delta < 0) {
                    $fresh->decrement('stock_quantity', abs($delta));
                }

                // Sync the in-memory model so the response below is accurate
                $product->stock_quantity = $newStock;
            });

        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id'             => $product->id,
            'name'           => $product->name,
            'stock_quantity' => $product->stock_quantity,
        ]);
    }
}