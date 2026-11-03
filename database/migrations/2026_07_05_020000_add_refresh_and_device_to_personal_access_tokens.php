<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Hash SHA-256 del refresh token (no se guarda en claro).
            $table->string('refresh_token', 64)->nullable()->unique()->after('token');
            $table->timestamp('refresh_expires_at')->nullable()->after('expires_at');
            // Identificador de dispositivo generado en el login.
            $table->string('device_id', 64)->nullable()->index()->after('refresh_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['refresh_token', 'refresh_expires_at', 'device_id']);
        });
    }
};
