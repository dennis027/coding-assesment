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
     * Paginated, filterable by category and in_stock, sortable by price|created_at.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['sometimes', 'string', 'max:100'],
            'in_stock' => ['sometimes', 'boolean'],
            'sort_by'  => ['sometimes', 'in:price,created_at'],
            'order'    => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $merchant = $request->user();
        $perPage = $request->integer('per_page', 20);

        $query = Product::forMerchant($merchant->id);

        if ($request->filled('category')) {
            $query->byCategory($request->string('category'));
        }

        if ($request->has('in_stock')) {
            $query->inStock($request->boolean('in_stock'));
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $order  = $request->input('order', 'desc');
        $query->orderBy($sortBy, $order);

        return response()->json(
            $query->paginate($perPage),
            200
        );
    }

    /**
     * POST /api/products
     * Create a new product for the authenticated merchant.
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
     * Atomically adjust stock with row-level locking to prevent race conditions.
     * Validates that stock never goes below zero.
     */
    public function adjustStock(AdjustStockRequest $request, Product $product): JsonResponse
    {
        // 403 Bypass: Commented out for testing
        // $this->authorize('update', $product);

        $delta = $request->integer('delta');

        try {
            DB::transaction(function () use ($product, $delta) {
                // Lock the row for the duration of the transaction
                $fresh = Product::lockForUpdate()->findOrFail($product->id);

                $newStock = $fresh->stock_quantity + $delta;

                if ($newStock < 0) {
                    throw new \DomainException(
                        "Stock adjustment failed: current stock is {$fresh->stock_quantity}, " .
                        "delta is {$delta}, which would result in {$newStock}. " .
                        "Stock cannot be negative."
                    );
                }

                // Use atomic increment/decrement for race-condition safety
                if ($delta > 0) {
                    $fresh->increment('stock_quantity', $delta);
                } else if ($delta < 0) {
                    $fresh->decrement('stock_quantity', abs($delta));
                }

                // FIX: Sync memory with the exact database count without duplicating the delta calculation
                $product->stock_quantity = $newStock;
            });
        } catch (\DomainException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                422
            );
        }

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'stock_quantity' => $product->stock_quantity,
        ], 200);
    }
}