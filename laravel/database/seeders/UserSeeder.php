<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create an authoritative admin/merchant testing user
        User::updateOrCreate(
            ['email' => 'admin@silktech.com'], // Prevents duplication on subsequent runs
            [
                'name'     => 'Silktech Admin',
                'password' => 'password123', // Automatically hashed by the model cast
            ]
        );
    }
}