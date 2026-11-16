<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_email_outbox', function (Blueprint $table) {
            $table->id();

            $table->uuid('message_id')->unique();

            $table->foreignId('event_id')
                ->unique()
                ->constrained('order_status_histories')
                ->restrictOnDelete();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            $table->string('recipient_email', 254);

            $table->unsignedInteger('publish_attempts')->default(0);
            $table->timestamp('next_publish_at', 6)->nullable();
            $table->timestamp('published_at', 6)->nullable();
            $table->text('last_publish_error')->nullable();

            $table->timestamps(6);

            $table->index(
                ['published_at', 'next_publish_at', 'id'],
                'order_email_outbox_pending_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_email_outbox');
    }
};
