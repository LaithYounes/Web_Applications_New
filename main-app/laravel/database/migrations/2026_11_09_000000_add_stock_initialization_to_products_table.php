<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('pending_initial_quantity')->nullable();
            $table->string('creation_key', 64)->nullable()->unique();
            $table->string('creation_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['creation_key']);
            $table->dropColumn(['pending_initial_quantity', 'creation_key', 'creation_fingerprint']);
        });
    }
};
