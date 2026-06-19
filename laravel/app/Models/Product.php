<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Added import for strong type hinting
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'price',
        'category',
        'stock_quantity',
        'merchant_id', // <-- Added to allow mass assignment inside your controllers
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
    ];

    /**
     * Get the merchant (User) that owns this product.
     */
    public function merchant(): BelongsTo
    {
        // Points to the User model, explicitly tracking the custom foreign key 'merchant_id'
        return $this->belongsTo(User::class, 'merchant_id');
    }

    /**
     * Scope: filter by merchant.
     */
    public function scopeForMerchant(Builder $query, int $merchantId): Builder
    {
        return $query->where('merchant_id', $merchantId);
    }

    /**
     * Scope: filter by in-stock status.
     */
    public function scopeInStock(Builder $query, bool $inStock = true): Builder
    {
        return $inStock
            ? $query->where('stock_quantity', '>', 0)
            : $query->where('stock_quantity', '=', 0);
    }

    /**
     * Scope: filter by category.
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}