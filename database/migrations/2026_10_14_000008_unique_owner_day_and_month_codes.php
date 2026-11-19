<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One day code per owner per calendar day, and one month code per owner per YYYY-MM.
 * scope_key is always non-null so the unique index works for both types (MySQL
 * allows multiple NULLs in UNIQUE columns, so valid_on alone is not enough).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_month_codes', function (Blueprint $table) {
            $table->string('scope_key', 32)->nullable()->after('valid_on');
        });

        $rows = DB::table('owner_month_codes')->orderByDesc('id')->get();
        $seen = [];
        foreach ($rows as $row) {
            $type = $row->type ?: 'month';
            $scope = $type === 'day'
                ? substr((string) $row->valid_on, 0, 10)
                : (string) $row->month;

            if ($scope === '') {
                $scope = $type === 'day'
                    ? substr((string) ($row->created_at ?? now()), 0, 10)
                    : (string) ($row->month ?: now()->format('Y-m'));
            }

            $fingerprint = $row->owner_id.'|'.$type.'|'.$scope;
            if (isset($seen[$fingerprint])) {
                DB::table('driver_code_entries')->where('owner_month_code_id', $row->id)->delete();
                DB::table('owner_month_codes')->where('id', $row->id)->delete();
                continue;
            }
            $seen[$fingerprint] = true;
            DB::table('owner_month_codes')->where('id', $row->id)->update([
                'type' => $type,
                'scope_key' => $scope,
            ]);
        }

        Schema::table('owner_month_codes', function (Blueprint $table) {
            $table->unique(['owner_id', 'type', 'scope_key'], 'owner_month_codes_owner_type_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('owner_month_codes', function (Blueprint $table) {
            $table->dropUnique('owner_month_codes_owner_type_scope_unique');
            $table->dropColumn('scope_key');
        });
    }
};
