<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'merchant_id'    => Merchant::factory(), // creates a merchant if none provided
            'name'           => $this->faker->words(3, true),
            'price'          => $this->faker->randomFloat(2, 50, 10000),
            'category'       => $this->faker->randomElement(['clothing', 'electronics', 'food', 'accessories']),
            'stock_quantity' => $this->faker->numberBetween(0, 100),
        ];
    }

    /** Shortcut: Product::factory()->outOfStock()->create() */
    public function outOfStock(): static
    {
        return $this->state(['stock_quantity' => 0]);
    }

    public function inStock(int $qty = 10): static
    {
        return $this->state(['stock_quantity' => $qty]);
    }
}
