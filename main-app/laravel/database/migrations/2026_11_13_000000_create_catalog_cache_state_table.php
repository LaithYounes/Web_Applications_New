<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_cache_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('generation');
        });
        DB::table('catalog_cache_state')->insert(['id' => 1, 'generation' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_cache_state');
    }
};
