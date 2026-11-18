<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jornadas')) {
            Schema::create('jornadas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
                $table->date('date');
                $table->string('label', 1); // A, B, or C
                $table->string('code', 8)->unique();
                $table->boolean('allow_create')->default(true);
                $table->boolean('allow_edit')->default(true);
                $table->boolean('allow_delete')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['owner_id', 'date', 'label']);
                $table->index(['date', 'code']);
            });
        }

        if (! Schema::hasTable('jornada_driver_entries')) {
            Schema::create('jornada_driver_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('jornada_id')->constrained('jornadas')->cascadeOnDelete();
                $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['jornada_id', 'driver_id']);
            });
        }

        if (! Schema::hasTable('model_audits')) {
            Schema::create('model_audits', function (Blueprint $table) {
                $table->id();
                $table->morphs('auditable');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 32); // created|updated|deleted|restored
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
            });
        }

        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('active');
            }
            if (! Schema::hasColumn('vehicles', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Table was renamed from trips → rutas; MySQL may still use trips_* FK names.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('rutas', function (Blueprint $table) {
                $table->dropForeign(['vehicle_id']);
            });
        } else {
            $this->dropForeignKeyIfExists('rutas', 'vehicle_id');
        }

        if (! Schema::hasColumn('rutas', 'jornada_id') || ! Schema::hasColumn('rutas', 'deleted_at')) {
            Schema::table('rutas', function (Blueprint $table) {
                $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();

                if (! Schema::hasColumn('rutas', 'jornada_id')) {
                    $table->foreignId('jornada_id')->nullable()->after('vehicle_id')->constrained('jornadas')->nullOnDelete();
                }
                if (! Schema::hasColumn('rutas', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        } else {
            // Only recreate the vehicle FK when the rest of the table is already migrated.
            Schema::table('rutas', function (Blueprint $table) {
                $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
            });
        }

        // Promote each owner's earliest vehicle to default when none is set yet.
        $ownerIds = DB::table('vehicles')
            ->when(Schema::hasColumn('vehicles', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->distinct()
            ->pluck('owner_id');

        foreach ($ownerIds as $ownerId) {
            $hasDefault = DB::table('vehicles')
                ->where('owner_id', $ownerId)
                ->where('is_default', true)
                ->when(Schema::hasColumn('vehicles', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->exists();

            if ($hasDefault) {
                continue;
            }

            $firstId = DB::table('vehicles')
                ->where('owner_id', $ownerId)
                ->when(Schema::hasColumn('vehicles', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->orderBy('id')
                ->value('id');

            if ($firstId) {
                DB::table('vehicles')->where('id', $firstId)->update(['is_default' => true]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rutas', 'jornada_id')) {
            Schema::table('rutas', function (Blueprint $table) {
                $table->dropConstrainedForeignId('jornada_id');
            });
        }

        if (Schema::hasColumn('rutas', 'deleted_at')) {
            Schema::table('rutas', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        Schema::table('vehicles', function (Blueprint $table) {
            if (Schema::hasColumn('vehicles', 'is_default')) {
                $table->dropColumn('is_default');
            }
            if (Schema::hasColumn('vehicles', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::dropIfExists('model_audits');
        Schema::dropIfExists('jornada_driver_entries');
        Schema::dropIfExists('jornadas');
    }

    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $database = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, $table, $column]
        );

        foreach ($rows as $row) {
            $name = $row->CONSTRAINT_NAME;
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
        }
    }
};
