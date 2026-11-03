<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servidor local del POS, reportado por integrosTokenOpen junto con la IP y
 * la version (ver AuthService::registrarActividadDispositivo()).
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        if (! Schema::hasColumn('integros_user_token', 'servidor_local')) {
            DB::statement("ALTER TABLE {$prefix}integros_user_token ADD COLUMN servidor_local VARCHAR(255) NULL DEFAULT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('integros_user_token', 'servidor_local')) {
            Schema::table('integros_user_token', function ($table) {
                $table->dropColumn('servidor_local');
            });
        }
    }
};
