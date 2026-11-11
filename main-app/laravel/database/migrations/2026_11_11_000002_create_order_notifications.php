<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_sessions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('revoked_at', 6)->nullable();
        });
        Schema::create('notification_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('installation_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 64);
            $table->foreign('session_id')->references('id')->on('notification_sessions')->restrictOnDelete();
            $table->string('platform', 16);
            $table->text('token')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps(6);
            $table->index(['user_id', 'active']);
        });
        Schema::create('order_notifications', function (Blueprint $table) {
            $table->id(); // Stable ordering and a read-all watermark; UUID is the public ID.
            $table->uuid('notification_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('order_status_histories')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->string('order_status', 24);
            $table->string('title');
            $table->text('body');
            $table->string('push_mode', 24);
            $table->timestamp('created_at', 6);
            $table->timestamp('read_at', 6)->nullable();
            $table->unique(['event_id', 'user_id']);
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['user_id', 'read_at', 'id']);
        });
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('order_notifications')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('notification_devices')->cascadeOnDelete();
            $table->unsignedInteger('device_revision');
            $table->string('driver', 24);
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('lease_until', 6)->nullable();
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('accepted_at', 6)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamps(6);
            $table->unique(['notification_id', 'device_id']);
            $table->index(['status', 'next_attempt_at']);
        });
        Schema::create('notification_push_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('notification_deliveries')->cascadeOnDelete();
            $table->json('payload'); // Fake provider receipts contain no device tokens.
            $table->string('result', 32);
            $table->timestamp('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_push_attempts');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('order_notifications');
        Schema::dropIfExists('notification_devices');
        Schema::dropIfExists('notification_sessions');
    }
};
