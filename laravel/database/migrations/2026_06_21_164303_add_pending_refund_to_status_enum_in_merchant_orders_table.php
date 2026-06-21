<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ONLY execute if running on MySQL. SQLite will safely skip this.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE merchant_orders
                MODIFY COLUMN status
                ENUM('pending', 'paid', 'cancelled', 'refunded', 'pending_refund')
                NOT NULL DEFAULT 'pending'
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE merchant_orders
                MODIFY COLUMN status
                ENUM('pending', 'paid', 'cancelled', 'refunded')
                NOT NULL DEFAULT 'pending'
            ");
        }
    }
};