-- Tablas de respaldo/bitacora para el sync offline-first del POS (Flutter).
-- Llamada adicional en paralelo al sync de negocio existente (ticket/payment,
-- cancelarfactura, entradasalidas/create) - NO lo reemplaza ni depende de el.
--
-- Idempotencia: ref_local es PRIMARY KEY: el backend hace upsert (INSERT ...
-- ON DUPLICATE KEY UPDATE) por ese campo, porque el reintento en background
-- del cliente puede reenviar el mismo registro si la respuesta se perdio
-- aunque el POST si llego (caida de red justo despues del 200).
--
-- No validan contra la factura/NC real del ERP: son solo bitacora, no deben
-- bloquear ni depender de que el objeto ya exista en Dolibarr.

CREATE TABLE IF NOT EXISTS llx_pos_backup_pedidos (
    ref_local VARCHAR(100) NOT NULL,
    numero_servidor VARCHAR(50) NULL,
    fecha_confirmacion DATETIME NULL,
    tipo_factura TINYINT NULL,
    cotizacion TINYINT(1) NULL,
    sincronizado_erp TINYINT(1) NULL,
    datos_pedido_json LONGTEXT NULL,
    pedido_request_json LONGTEXT NULL,
    datos_server_json LONGTEXT NULL,
    fecha_recibido DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ref_local)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS llx_pos_backup_notas_credito (
    ref_local VARCHAR(100) NOT NULL,
    numero_servidor VARCHAR(50) NULL,
    fecha DATETIME NULL,
    cliente_nombre VARCHAR(255) NULL,
    total_con_iva DECIMAL(18,2) NULL,
    payload_json LONGTEXT NULL,
    datos_server_json LONGTEXT NULL,
    fecha_recibido DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ref_local)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS llx_pos_backup_entradas_salidas (
    ref_local VARCHAR(100) NOT NULL,
    monto DECIMAL(18,2) NULL,
    tipo TINYINT NULL,
    fk_usuario INT NULL,
    fk_client INT NULL,
    nombre_cliente VARCHAR(255) NULL,
    descripcion VARCHAR(255) NULL,
    facturas_afectadas_json LONGTEXT NULL,
    fecha DATETIME NULL,
    id_servicio INT NULL,
    metodo_pago_json LONGTEXT NULL,
    datos_server_json LONGTEXT NULL,
    fecha_recibido DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ref_local)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
