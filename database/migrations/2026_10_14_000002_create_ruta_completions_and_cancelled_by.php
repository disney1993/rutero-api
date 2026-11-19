<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rutas', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->after('completed_by')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('ruta_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ruta_id')->constrained('rutas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('completed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['ruta_id', 'user_id']);
        });

        // Migra el completed_by histórico a la tabla multi-conductor.
        if (Schema::hasColumn('rutas', 'completed_by')) {
            $rows = DB::table('rutas')
                ->whereNotNull('completed_by')
                ->select('id', 'completed_by', 'updated_at')
                ->get();

            foreach ($rows as $row) {
                DB::table('ruta_completions')->insertOrIgnore([
                    'ruta_id' => $row->id,
                    'user_id' => $row->completed_by,
                    'completed_at' => $row->updated_at ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ruta_completions');

        Schema::table('rutas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
        });
    }
};
