<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'transaction_id',
        'provider',
        'order_reference',
        'amount',
        'currency',
        'msisdn',
        'status',
        'occurred_at',
        'raw_payload',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'occurred_at' => 'datetime',
        'raw_payload' => 'array',
    ];
}
