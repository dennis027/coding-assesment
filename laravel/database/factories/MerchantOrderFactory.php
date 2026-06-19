<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class MerchantOrderFactory extends Factory
{
    protected $model = MerchantOrder::class;

    public function definition(): array
    {
        return [
            'merchant_id'     => Merchant::factory(),
            'order_reference' => 'SC-ORD-' . $this->faker->unique()->numberBetween(10000, 99999),
            'status'          => 'pending',
            'total_amount'    => $this->faker->randomFloat(2, 100, 50000),
        ];
    }

    public function paid(): static
    {
        return $this->state(['status' => 'paid']);
    }
}
