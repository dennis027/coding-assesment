<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Authenticatable
{
    protected $fillable = ['business_name', 'email', 'password'];

    protected $hidden = ['password'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(MerchantOrder::class);
    }
}
