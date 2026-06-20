<?php

namespace Database\Seeders;

use App\Models\Merchant;
use Illuminate\Database\Seeder;

class MerchantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $merchants = [
            [
                'email' => 'admin@silktech.com',
                'name' => 'Silktech Admin',
                'business_name' => 'Silktech Retail',
                'password' => 'password123', // Automatically hashed by Merchant model casting
            ],
            [
                'email' => 'alpha@silkcommerce.com',
                'name' => 'Alpha Seller',
                'business_name' => 'Alpha Logistics',
                'password' => 'password123',
            ],
            [
                'email' => 'omega@silkcommerce.com',
                'name' => 'Omega Vendor',
                'business_name' => 'Omega Digital Solutions',
                'password' => 'password123',
            ],
        ];

        foreach ($merchants as $merchantData) {
            Merchant::updateOrCreate(
                ['email' => $merchantData['email']], // Unique lookup key
                [
                    'name' => $merchantData['name'],
                    'business_name' => $merchantData['business_name'],
                    'password' => $merchantData['password'],
                ]
            );
        }
    }
}

