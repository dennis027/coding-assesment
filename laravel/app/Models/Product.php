<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = ['merchant_id', 'name', 'price', 'category', 'stock_quantity'];

    protected $casts = [
        'price'          => 'decimal:2',
        'stock_quantity' => 'integer',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function scopeForMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeInStock($query, bool $inStock = true)
    {
        return $inStock
            ? $query->where('stock_quantity', '>', 0)
            : $query->where('stock_quantity', 0);
    }
}
