<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_checkouts', function (Blueprint $table) {
            $table->uuid('reservation_id')->primary();
            $table->foreignId('cart_id')->unique()->constrained()->restrictOnDelete();
            $table->json('order_items');
            $table->decimal('total_amount', 12, 2);
            $table->timestamps();
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_checkouts');
    }
};
