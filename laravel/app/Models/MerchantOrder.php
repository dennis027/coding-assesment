<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantOrder extends Model
{
    protected $fillable = ['merchant_id', 'order_reference', 'status', 'total_amount'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_reference', 'order_reference');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
