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
    /**
     * GET /api/products
     * Paginated, filterable by category and in_stock, sortable by price|created_at.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['sometimes', 'string', 'max:100'],
            'in_stock' => ['sometimes', 'boolean'],
            'sort_by'  => ['sometimes', 'in:price,created_at'],
            'order'    => ['sometimes', 'in:asc,desc'],
        ]);

        $merchant = $request->user(); // Sanctum / session auth

        $query = Product::forMerchant($merchant->id);

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('in_stock')) {
            $query->inStock($request->boolean('in_stock'));
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $order  = $request->input('order', 'desc');
        $query->orderBy($sortBy, $order);

        return response()->json(
            $query->paginate(20)
        );
    }

    /**
     * POST /api/products
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
     * Uses a DB-level atomic increment/decrement to avoid race conditions
     * when two requests adjust stock simultaneously.  The CHECK constraint
     * (or application guard below) prevents going negative.
     */
    public function adjustStock(AdjustStockRequest $request, Product $product): JsonResponse
    {
        // Route model binding + policy: confirm this product belongs to the authed merchant.
        if ($product->merchant_id !== $request->user()->id) {
            abort(403, 'This product does not belong to you.');
        }

        $delta = $request->integer('delta');

        // Atomically update and re-read inside a transaction with row lock.
        DB::transaction(function () use ($product, $delta) {
            // Lock the row to prevent concurrent adjustments reading a stale value.
            $product->refresh(); // re-fetch in case of any prior in-request changes
            $fresh = Product::lockForUpdate()->find($product->id);

            $newStock = $fresh->stock_quantity + $delta;

            if ($newStock < 0) {
                // Throw here so the transaction rolls back cleanly.
                throw new \DomainException(
                    "Adjustment would result in negative stock ({$fresh->stock_quantity} + {$delta} = {$newStock})."
                );
            }

            // Use DB increment/decrement for a single atomic UPDATE statement,
            // avoiding a read-modify-write race between concurrent requests.
            if ($delta > 0) {
                $fresh->increment('stock_quantity', $delta);
            } else {
                $fresh->decrement('stock_quantity', abs($delta));
            }

            $product->stock_quantity = $fresh->stock_quantity + ($delta > 0 ? $delta : -abs($delta));
        });

        $product->refresh(); // get the authoritative value post-update

        return response()->json([
            'id'             => $product->id,
            'stock_quantity' => $product->stock_quantity,
        ]);
    }
}
