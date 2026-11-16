<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_email_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('outbox_id')
                ->unique()
                ->constrained('order_email_outbox')
                ->restrictOnDelete();

            $table->uuid('message_id')->unique();

            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);

            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until', 6)->nullable();

            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('accepted_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();

            $table->text('last_error')->nullable();

            $table->timestamps(6);

            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_email_deliveries');
    }
};
