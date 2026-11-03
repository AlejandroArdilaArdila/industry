-- Tablas del modulo integrosapk (submodulo negociacion) que faltan en esta
-- base (mercosto). Copiadas tal cual de
-- custom/integrosapk/sql/llx_integros_negociacion.sql

CREATE TABLE IF NOT EXISTS llx_integros_negociacion
(
    rowid            INTEGER      NOT NULL AUTO_INCREMENT,
    fk_soc           INTEGER      NOT NULL,
    proveedor_nombre VARCHAR(255) NOT NULL DEFAULT '',
    fecha            DATE,
    estado           VARCHAR(20)  NOT NULL DEFAULT 'borrador',
    notas            TEXT,
    total            DOUBLE(24,8)          DEFAULT 0,
    fk_user_create   INTEGER,
    fecha_creacion   DATETIME,
    tms              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rowid),
    INDEX idx_neg_fk_soc (fk_soc),
    INDEX idx_neg_estado (estado),
    INDEX idx_neg_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS llx_integros_negociacion_det
(
    rowid                INTEGER      NOT NULL AUTO_INCREMENT,
    fk_negociacion       INTEGER      NOT NULL,
    fk_product           INTEGER      NOT NULL,
    referencia           VARCHAR(30)           DEFAULT '',
    nombre               VARCHAR(255)          DEFAULT '',
    precio_ultima_compra DOUBLE(24,8)          DEFAULT 0,
    precio_negociado     DOUBLE(24,8)          DEFAULT 0,
    unidades             INTEGER               DEFAULT 0,
    PRIMARY KEY (rowid),
    INDEX idx_negdet_fk_negociacion (fk_negociacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE llx_integros_negociacion ADD COLUMN orden_compra_id INTEGER NULL;

CREATE TABLE IF NOT EXISTS llx_integros_neg_seguimiento
(
    rowid                INTEGER      NOT NULL AUTO_INCREMENT,
    fk_negociacion       INTEGER      NOT NULL,
    tipo                 VARCHAR(50)  NOT NULL DEFAULT 'llamada',
    descripcion          TEXT,
    proxima_fecha        DATETIME,
    proxima_descripcion  TEXT,
    fk_user_create       INTEGER,
    fecha_creacion       DATETIME,
    PRIMARY KEY (rowid),
    INDEX idx_negseg_fk_neg (fk_negociacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
