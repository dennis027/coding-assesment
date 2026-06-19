<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        return [
            'business_name' => $this->faker->company(),
            'email'         => $this->faker->unique()->safeEmail(),
            'password'      => Hash::make('password'), // use 'password' in tests
        ];
    }
}
