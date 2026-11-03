-- Indices faltantes detectados al investigar reportes de lentitud en la
-- confirmacion de pedidos POS (validarFacturaPos() -> sp_pos_validar_factura_pos).
--
-- Diagnostico (EXPLAIN sobre la BD real, ver conversacion):
--
-- 1) facture_extrafields.ref_local NO tiene indice. sp_pos_validar_factura_pos
--    (y antes, sp_pos_crear_ticket_v1) buscan por ref_local exacto EN CADA
--    confirmacion (idempotencia + resolucion de fk_facture cuando el cliente
--    manda refLocal sin fk_facture). Sin indice, esto es un full table scan
--    de la tabla completa (38451 filas al momento de este diagnostico, type=ALL
--    en el EXPLAIN) en absolutamente todas las llamadas, no solo en carritos
--    grandes -- coincide con el reporte de que el tiempo PROMEDIO de
--    confirmacion es alto, no solo el de los pedidos con muchos productos.
--    No se agrega como UNIQUE porque existen duplicados reales de ref_local
--    en los datos actuales (al menos 5 valores repetidos encontrados).
--
-- 2) pos_puntos_fidelizacion.fk_client y pos_puntos_fidelizacion_detalle.fk_facture
--    no tienen indice. Se consultan en CADA confirmacion exitosa en modo normal
--    (sp_pos_armar_json_factura / sp_pos_datos_adicionales_cliente). Ambas tablas
--    estan practicamente vacias hoy, asi que el impacto actual es bajo, pero
--    quedarian haciendo table scan a medida que crecen con el uso real del
--    programa de fidelizacion.

ALTER TABLE llx_facture_extrafields
    ADD INDEX idx_facture_extrafields_ref_local (ref_local);

ALTER TABLE llx_pos_puntos_fidelizacion
    ADD INDEX idx_puntos_fidelizacion_fk_client (fk_client);

ALTER TABLE llx_pos_puntos_fidelizacion_detalle
    ADD INDEX idx_puntos_fidelizacion_detalle_fk_facture (fk_facture);

-- Verificacion sugerida despues de aplicar (comparar "type"/"rows"/"key"
-- contra el EXPLAIN sin el indice, deberia pasar de type=ALL,rows=~38000
-- a type=ref,rows=~1-3):
--
-- EXPLAIN SELECT fk_object, ref_local, en_pedido
-- FROM llx_facture_extrafields
-- WHERE ref_local = 'algun-ref-local-real';
