-- Trackea que proveedor (DOLAPIKEY de llx_integros_usuario_proveedor) creo
-- cada preorden, para poder autorizar despues quien puede pedir su PDF
-- (POST /api.php/integrosone/proveedores/{idProveedor}/preordenes/pdf).
-- Queda NULL en las preordenes creadas por un usuario interno (flujo normal).

ALTER TABLE llx_integros_precommande_extrafields
ADD COLUMN fk_proveedor INT NULL AFTER contact_id;
