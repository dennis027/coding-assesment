<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Alter the enum to include pending_refund.
        // Laravel's Schema builder can't modify enums directly,
        // so we use a raw SQL ALTER TABLE instead.
        DB::statement("
            ALTER TABLE merchant_orders
            MODIFY COLUMN status
            ENUM('pending', 'paid', 'cancelled', 'refunded', 'pending_refund')
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE merchant_orders
            MODIFY COLUMN status
            ENUM('pending', 'paid', 'cancelled', 'refunded')
            NOT NULL DEFAULT 'pending'
        ");
    }
};