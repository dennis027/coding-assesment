<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique(); // provider's transaction ID — the idempotency key
            $table->string('provider');                 // mpesa, airtel, card, etc.
            $table->string('order_reference');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('KES');
            $table->string('msisdn')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'reversed'])->default('pending');
            $table->timestamp('occurred_at');
            $table->json('raw_payload');                // store original for auditing / replay
            $table->timestamps();

            $table->index(['order_reference', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
