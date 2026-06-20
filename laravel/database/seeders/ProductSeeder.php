<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * 3 products per merchant, each with a different stock state so the
     * dashboard immediately shows all three badge colours (in stock,
     * low stock, out of stock).
     */
    public function run(): void
    {
        // Products indexed by the merchant email defined in MerchantSeeder
        $catalogue = [
            'admin@silktech.com' => [
                [
                    'name'           => 'Silk Premium Tote Bag',
                    'price'          => 1500.00,
                    'category'       => 'Accessories',
                    'stock_quantity' => 40,   // healthy — green badge
                ],
                [
                    'name'           => 'Ankara Print Dress',
                    'price'          => 3200.00,
                    'category'       => 'Clothing',
                    'stock_quantity' => 4,    // low stock — amber badge
                ],
                [
                    'name'           => 'Beaded Leather Sandals',
                    'price'          => 2800.00,
                    'category'       => 'Footwear',
                    'stock_quantity' => 0,    // out of stock — red badge
                ],
            ],

            'alpha@silkcommerce.com' => [
                [
                    'name'           => 'Wireless Earbuds Pro',
                    'price'          => 8999.00,
                    'category'       => 'Electronics',
                    'stock_quantity' => 25,
                ],
                [
                    'name'           => 'Phone Case (Universal)',
                    'price'          => 450.00,
                    'category'       => 'Electronics',
                    'stock_quantity' => 3,    // low stock
                ],
                [
                    'name'           => 'USB-C Charging Cable',
                    'price'          => 350.00,
                    'category'       => 'Electronics',
                    'stock_quantity' => 0,    // out of stock
                ],
            ],

            'omega@silkcommerce.com' => [
                [
                    'name'           => 'Office Ergonomic Chair',
                    'price'          => 24500.00,
                    'category'       => 'Furniture',
                    'stock_quantity' => 12,
                ],
                [
                    'name'           => 'Standing Desk Mat',
                    'price'          => 3100.00,
                    'category'       => 'Furniture',
                    'stock_quantity' => 5,    // exactly at threshold — amber
                ],
                [
                    'name'           => 'Monitor Riser (Bamboo)',
                    'price'          => 1800.00,
                    'category'       => 'Furniture',
                    'stock_quantity' => 0,    // out of stock
                ],
            ],
        ];

        foreach ($catalogue as $email => $products) {
            $merchant = Merchant::where('email', $email)->first();

            if (! $merchant) {
                $this->command->warn("Merchant {$email} not found — run MerchantSeeder first.");
                continue;
            }

            foreach ($products as $data) {
                Product::updateOrCreate(
                    // Unique lookup: same merchant + same product name
                    [
                        'merchant_id' => $merchant->id,
                        'name'        => $data['name'],
                    ],
                    [
                        'price'          => $data['price'],
                        'category'       => $data['category'],
                        'stock_quantity' => $data['stock_quantity'],
                    ]
                );
            }

            $this->command->info("Seeded 3 products for {$merchant->business_name} ({$email})");
        }
    }
}