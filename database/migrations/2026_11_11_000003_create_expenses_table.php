<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos del negocio del propietario (nóminas, combustible, taller, multas,
 * etc.), para que los informes muestren ingresos, gastos y beneficio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('category', 24);
            $table->string('description', 120)->nullable();
            // Importe total pagado (IVA incluido).
            $table->decimal('amount', 10, 2);
            // IVA soportado, informativo (opcional).
            $table->decimal('vat_amount', 10, 2)->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['owner_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
