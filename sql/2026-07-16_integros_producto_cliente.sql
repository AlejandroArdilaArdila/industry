-- Tabla requerida por GET /api.php/integrosone/productos/clientes
-- (replica de GET /api.php/integrosapk/productos/clientes/{id}, modulo
-- integrosapk). Copiada tal cual de
-- custom/integrosapk/sql/llx_integros_producto_cliente.sql - todavia no
-- estaba aplicada en esta base (mercosto).

CREATE TABLE IF NOT EXISTS llx_integros_producto_cliente
(
    rowid           INTEGER       NOT NULL AUTO_INCREMENT,
    fk_soc          INTEGER       NOT NULL,
    fk_product      INTEGER       NOT NULL,
    referencia      VARCHAR(30)            DEFAULT '',
    nombre          VARCHAR(255)           DEFAULT '',
    cantidad_tipica DOUBLE(24,8)           DEFAULT 1,
    fk_user_create  INTEGER,
    fecha_creacion  DATETIME,
    PRIMARY KEY (rowid),
    UNIQUE KEY uk_soc_product (fk_soc, fk_product),
    INDEX idx_pc_fk_soc     (fk_soc),
    INDEX idx_pc_fk_product (fk_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
