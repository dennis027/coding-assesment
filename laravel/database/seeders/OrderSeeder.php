<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\MerchantOrder;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Create 5 sample orders across merchants for webhook testing.
     * Orders reference products available in ProductSeeder.
     */
    public function run(): void
    {
        $orders = [
            'admin@silktech.com' => [
                [
                    'order_reference' => 'SC-ORD-10001',
                    'status'          => 'pending',
                    'total_amount'    => 4700.00,  // Silk Tote (1500) + Ankara Dress (3200)
                ],
                [
                    'order_reference' => 'SC-ORD-10002',
                    'status'          => 'paid',
                    'total_amount'    => 7800.00,  // Silk Tote (1500) + Beaded Sandals (2800) + Ankara Dress (3200) + tax
                ],
            ],

            'alpha@silkcommerce.com' => [
                [
                    'order_reference' => 'SC-ORD-10003',
                    'status'          => 'pending',
                    'total_amount'    => 9799.00,  // Wireless Earbuds (8999) + Phone Case (450) + tax
                ],
                [
                    'order_reference' => 'SC-ORD-10004',
                    'status'          => 'paid',
                    'total_amount'    => 800.00,   // Phone Case (450) + USB-C Cable (350)
                ],
            ],

            'omega@silkcommerce.com' => [
                [
                    'order_reference' => 'SC-ORD-10005',
                    'status'          => 'pending',
                    'total_amount'    => 25300.00,  // Office Chair (24500) + Standing Desk Mat (3100) - discount
                ],
            ],
        ];

        foreach ($orders as $email => $orderList) {
            $merchant = Merchant::where('email', $email)->first();

            if (! $merchant) {
                $this->command->warn("Merchant {$email} not found — run MerchantSeeder first.");
                continue;
            }

            foreach ($orderList as $data) {
                MerchantOrder::updateOrCreate(
                    ['order_reference' => $data['order_reference']],
                    [
                        'merchant_id'  => $merchant->id,
                        'status'       => $data['status'],
                        'total_amount' => $data['total_amount'],
                    ]
                );
            }

            $this->command->info("Seeded {$merchant->business_name} ({$email}) with orders");
        }
    }
}