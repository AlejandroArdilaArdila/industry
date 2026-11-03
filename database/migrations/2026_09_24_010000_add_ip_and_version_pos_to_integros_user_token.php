<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IP y version del POS desde el que se inicio sesion, guardadas junto al
 * candado de dispositivo (integros_user_token) en el login (ver
 * AuthService::registrarSesionDispositivo()).
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        if (! Schema::hasColumn('integros_user_token', 'ip')) {
            DB::statement("ALTER TABLE {$prefix}integros_user_token ADD COLUMN ip VARCHAR(45) NULL DEFAULT NULL");
        }

        if (! Schema::hasColumn('integros_user_token', 'version_pos')) {
            DB::statement("ALTER TABLE {$prefix}integros_user_token ADD COLUMN version_pos VARCHAR(50) NULL DEFAULT NULL");
        }
    }

    public function down(): void
    {
        foreach (['ip', 'version_pos'] as $columna) {
            if (Schema::hasColumn('integros_user_token', $columna)) {
                Schema::table('integros_user_token', function ($table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
};
