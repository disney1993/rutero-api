<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('trips', 'hidden')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->boolean('hidden')->default(false)->after('owner_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('trips', 'hidden')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->dropColumn('hidden');
            });
        }
    }
};
