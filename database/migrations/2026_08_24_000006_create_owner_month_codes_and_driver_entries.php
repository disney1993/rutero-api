<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_month_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('code')->index();
            $table->string('month')->comment('YYYY-MM');
            $table->timestamps();
        });

        Schema::create('driver_code_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_month_code_id')->constrained('owner_month_codes')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['owner_month_code_id', 'driver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_code_entries');
        Schema::dropIfExists('owner_month_codes');
    }
};
