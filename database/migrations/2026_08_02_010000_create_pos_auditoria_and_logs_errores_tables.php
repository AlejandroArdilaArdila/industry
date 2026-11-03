<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos tablas de ingesta de la cola de sincronizacion local del POS
 * (sync_service.dart / LoggingClient), ninguna de las dos existia todavia
 * en esta base aunque el codigo que las usa (LogErrorEndpointService) ya
 * estaba en produccion:
 *
 * - pos_logs_errores: errores de API (status >= 400) que el POS reporta.
 * - pos_auditoria: eventos de auditoria genericos (no solo cotizaciones;
 *   entidad_tipo es lista abierta: cotizacion, pedido_en_curso, nota_credito,
 *   caja, etc.) subidos desde la cola local de la terminal. id_local es el
 *   id de SQLite de la terminal, solo referencia externa: se repite entre
 *   terminales distintas, nunca se usa como PK del servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        DB::statement("CREATE TABLE IF NOT EXISTS {$prefix}pos_logs_errores (
            rowid INT NOT NULL AUTO_INCREMENT,
            deviceid VARCHAR(255) DEFAULT NULL,
            fk_user INT DEFAULT NULL,
            metodo VARCHAR(10) NOT NULL,
            url TEXT NOT NULL,
            status_code INT NOT NULL,
            tiempo_ms INT DEFAULT NULL,
            request_body LONGTEXT DEFAULT NULL,
            response_body LONGTEXT DEFAULT NULL,
            fecha_cliente DATETIME NOT NULL,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (rowid),
            INDEX idx_fk_user (fk_user),
            INDEX idx_status_code (status_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        DB::statement("CREATE TABLE IF NOT EXISTS {$prefix}pos_auditoria (
            rowid INT NOT NULL AUTO_INCREMENT,
            id_local INT DEFAULT NULL,
            fecha_evento DATETIME(6) NOT NULL,
            modulo VARCHAR(100) NOT NULL,
            accion VARCHAR(100) NOT NULL,
            usuario_id INT DEFAULT NULL,
            usuario_nombre VARCHAR(255) DEFAULT NULL,
            entidad_tipo VARCHAR(100) NOT NULL,
            entidad_id VARCHAR(255) DEFAULT NULL,
            tipo_documento VARCHAR(20) DEFAULT NULL,
            datos_antes_json LONGTEXT DEFAULT NULL,
            datos_despues_json LONGTEXT DEFAULT NULL,
            descripcion TEXT DEFAULT NULL,
            fk_caja_local VARCHAR(255) DEFAULT NULL,
            total DECIMAL(24,8) DEFAULT NULL,
            subtotal DECIMAL(24,8) DEFAULT NULL,
            iva DECIMAL(24,8) DEFAULT NULL,
            items INT DEFAULT NULL,
            fecha_recibido DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (rowid),
            INDEX idx_modulo (modulo),
            INDEX idx_accion (accion),
            INDEX idx_entidad (entidad_tipo, entidad_id),
            INDEX idx_usuario (usuario_id),
            INDEX idx_fecha_evento (fecha_evento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        // No se dropean: son tablas de ingesta con datos historicos de sync,
        // un rollback no deberia borrarlas.
    }
};
