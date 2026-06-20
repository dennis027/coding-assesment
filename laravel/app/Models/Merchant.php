<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // Required for Sanctum issueTokens/createToken

class Merchant extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',          // Matches $request->name in your AuthController registration method
        'business_name', 
        'email', 
        'password'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed', // Automatically handles Hash::make() on save/create
    ];

    /**
     * Get the products owned by this merchant.
     */
    public function products(): HasMany
    {
        // Explicitly defining 'merchant_id' matching your product schema setup
        return $this->hasMany(Product::class, 'merchant_id');
    }

    /**
     * Get the orders belonging to this merchant.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(MerchantOrder::class, 'merchant_id');
    }
}