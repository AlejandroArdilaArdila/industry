-- Tablas del modulo integrosapk (submodulo visita) que faltan en esta base
-- (mercosto). Copiadas tal cual de
-- custom/integrosapk/sql/llx_integros_visita.sql

CREATE TABLE IF NOT EXISTS llx_integros_visita
(
    rowid           INTEGER       NOT NULL AUTO_INCREMENT,
    fk_soc          INTEGER       NOT NULL,
    cliente_nombre  VARCHAR(255)  NOT NULL DEFAULT '',
    fecha           DATE,
    motivo          VARCHAR(255)           DEFAULT '',
    observaciones   TEXT,
    estado          VARCHAR(20)   NOT NULL DEFAULT 'borrador',
    fk_user_create  INTEGER,
    fecha_creacion  DATETIME,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rowid),
    INDEX idx_vis_fk_soc  (fk_soc),
    INDEX idx_vis_fecha   (fecha),
    INDEX idx_vis_estado  (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS llx_integros_visita_det
(
    rowid                INTEGER       NOT NULL AUTO_INCREMENT,
    fk_visita            INTEGER       NOT NULL,
    fk_product           INTEGER       NOT NULL,
    referencia           VARCHAR(30)            DEFAULT '',
    nombre               VARCHAR(255)           DEFAULT '',
    precio_ultima_venta  DOUBLE(24,8)           DEFAULT 0,
    stock_actual         DOUBLE(24,8)           DEFAULT 0,
    dias_ultima_venta    INTEGER,
    PRIMARY KEY (rowid),
    INDEX idx_visdet_fk_visita (fk_visita)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS llx_integros_visita_img
(
    rowid          INTEGER      NOT NULL AUTO_INCREMENT,
    fk_visita      INTEGER      NOT NULL,
    url            VARCHAR(500) NOT NULL DEFAULT '',
    fecha_creacion DATETIME,
    PRIMARY KEY (rowid),
    INDEX idx_visimg_fk_visita (fk_visita)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE llx_integros_visita ADD COLUMN tipo_visita VARCHAR(30) NOT NULL DEFAULT 'presencial';
ALTER TABLE llx_integros_visita ADD COLUMN fecha_realizada DATE;
ALTER TABLE llx_integros_visita ADD COLUMN fk_user_asignado INTEGER;
ALTER TABLE llx_integros_visita ADD COLUMN fk_socpeople INTEGER;
ALTER TABLE llx_integros_visita ADD COLUMN contacto_nombre VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE llx_integros_visita ADD COLUMN contacto_telefono VARCHAR(50) NOT NULL DEFAULT '';
ALTER TABLE llx_integros_visita ADD COLUMN contacto_email VARCHAR(100) NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS llx_integros_visita_seguimiento
(
    rowid                INTEGER      NOT NULL AUTO_INCREMENT,
    fk_visita            INTEGER      NOT NULL,
    tipo                 VARCHAR(50)  NOT NULL DEFAULT 'llamada',
    descripcion          TEXT,
    proxima_fecha        DATETIME,
    proxima_descripcion  TEXT,
    fk_user_create       INTEGER,
    fecha_creacion       DATETIME,
    PRIMARY KEY (rowid),
    INDEX idx_visseg_fk_visita (fk_visita)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
