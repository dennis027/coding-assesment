<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->string('category');
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['merchant_id', 'category']);
            $table->index(['merchant_id', 'stock_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
