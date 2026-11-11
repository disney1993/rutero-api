<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos: si son deducibles (las multas no lo son) y gastos que se repiten
 * cada mes (nóminas, seguros, cuota de autónomos). Un gasto con
 * recurring_monthly es la "plantilla"; el comando expenses:generate-recurring
 * crea cada mes una copia que apunta a ella (recurring_parent_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('deductible')->default(true)->after('vat_amount');
            $table->boolean('recurring_monthly')->default(false)->after('deductible');
            $table->foreignId('recurring_parent_id')->nullable()->after('recurring_monthly')
                ->constrained('expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_parent_id');
            $table->dropColumn(['deductible', 'recurring_monthly']);
        });
    }
};
