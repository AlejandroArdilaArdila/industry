-- Tablas de ingesta de la cola de sincronizacion local del POS (sync_service.dart /
-- LoggingClient), usadas por LogErrorEndpointService. Ninguna de las dos existia
-- todavia en esta base (mercosto) aunque el codigo que las usa ya estaba en produccion.
--
-- pos_logs_errores: errores de API (status >= 400) que el POS reporta.
-- pos_auditoria: eventos de auditoria genericos (entidad_tipo es lista abierta:
--   cotizacion, pedido_en_curso, nota_credito, caja, etc.). id_local es el id de
--   SQLite de la terminal, solo referencia externa: se repite entre terminales
--   distintas, nunca se usa como PK del servidor.

CREATE TABLE IF NOT EXISTS llx_pos_logs_errores
(
    rowid          INTEGER      NOT NULL AUTO_INCREMENT,
    deviceid       VARCHAR(255)          DEFAULT NULL,
    fk_user        INTEGER               DEFAULT NULL,
    metodo         VARCHAR(10)  NOT NULL,
    url            TEXT         NOT NULL,
    status_code    INTEGER      NOT NULL,
    tiempo_ms      INTEGER               DEFAULT NULL,
    request_body   LONGTEXT              DEFAULT NULL,
    response_body  LONGTEXT              DEFAULT NULL,
    fecha_cliente  DATETIME     NOT NULL,
    date_creation  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rowid),
    INDEX idx_fk_user (fk_user),
    INDEX idx_status_code (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_pos_auditoria
(
    rowid               INTEGER       NOT NULL AUTO_INCREMENT,
    id_local            INTEGER                DEFAULT NULL,
    fecha_evento        DATETIME(6)   NOT NULL,
    modulo              VARCHAR(100)  NOT NULL,
    accion              VARCHAR(100)  NOT NULL,
    usuario_id          INTEGER                DEFAULT NULL,
    usuario_nombre      VARCHAR(255)           DEFAULT NULL,
    entidad_tipo        VARCHAR(100)  NOT NULL,
    entidad_id          VARCHAR(255)           DEFAULT NULL,
    tipo_documento      VARCHAR(20)            DEFAULT NULL,
    datos_antes_json    LONGTEXT               DEFAULT NULL,
    datos_despues_json  LONGTEXT               DEFAULT NULL,
    descripcion         TEXT                   DEFAULT NULL,
    fk_caja_local       VARCHAR(255)           DEFAULT NULL,
    total               DECIMAL(24,8)          DEFAULT NULL,
    subtotal            DECIMAL(24,8)          DEFAULT NULL,
    iva                 DECIMAL(24,8)          DEFAULT NULL,
    items               INTEGER                DEFAULT NULL,
    fecha_recibido      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rowid),
    INDEX idx_modulo (modulo),
    INDEX idx_accion (accion),
    INDEX idx_entidad (entidad_tipo, entidad_id),
    INDEX idx_usuario (usuario_id),
    INDEX idx_fecha_evento (fecha_evento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
