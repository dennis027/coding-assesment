<?php

namespace App\Policies;

use App\Models\Merchant;
use App\Models\Product;

class ProductPolicy
{
    /**
     * Determine if the merchant can view this product.
     */
    public function view(Merchant $merchant, Product $product): bool
    {
        return $product->merchant_id === $merchant->id;
    }

    /**
     * Determine if the merchant can update (edit) this product.
     */
    public function update(Merchant $merchant, Product $product): bool
    {
        return $product->merchant_id === $merchant->id;
    }

    /**
     * Determine if the merchant can delete this product.
     */
    public function delete(Merchant $merchant, Product $product): bool
    {
        return $product->merchant_id === $merchant->id;
    }
}