<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facture_extrafields', function (Blueprint $table) {
            // Historial de auditoria de la cotizacion (array de snapshots antes/despues).
            $table->json('auditoria')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('facture_extrafields', function (Blueprint $table) {
            $table->dropColumn('auditoria');
        });
    }
};
