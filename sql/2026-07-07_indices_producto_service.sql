-- Indices faltantes detectados al optimizar ProductoService::listarApp()
-- (app/Services/Producto/ProductoService.php).
--
-- Diagnostico (EXPLAIN sobre la BD real, ver conversacion):
--
-- 1) product_extrafields.producto_publicado no tiene indice. Casi todo el
--    catalogo esta publicado (5506 de 5507 productos), asi que el WHERE de
--    listarApp fuerza "Using temporary; Using filesort" escaneando toda la
--    tabla antes de poder aplicar el LIMIT. El codigo ya mitiga esto
--    resolviendo primero solo los rowid (fase 1) antes de correr las
--    subconsultas de enriquecimiento (fase 2), pero la fase 1 en si sigue
--    pagando ese scan completo.
--    NOTA: por la baja selectividad (~99.98% de las filas cumplen la
--    condicion), un indice simple en producto_publicado puede no cambiar el
--    plan del optimizador. Antes de aplicar en produccion, correr el EXPLAIN
--    de abajo en QA y comparar. Si no ayuda, la alternativa es forzar el
--    orden del JOIN (driving desde `product` por su PRIMARY KEY) en la query
--    en vez de agregar este indice.
--
-- 2) ecm_files no tiene indice en (src_object_type, src_object_id). La
--    imagen principal del producto se busca por esas dos columnas; sin
--    indice, cada busqueda hace table scan completo de ecm_files (tabla
--    compartida por TODOS los tipos de objeto de Dolibarr, no solo products).
--    Este si es un indice claramente selectivo y de bajo riesgo.

ALTER TABLE llx_product_extrafields
    ADD INDEX idx_product_extrafields_publicado (producto_publicado);

ALTER TABLE llx_ecm_files
    ADD INDEX idx_ecm_files_src_object (src_object_type, src_object_id);

-- Verificacion sugerida despues de aplicar (comparar "Extra"/"rows"/"key"
-- contra el EXPLAIN sin el indice):
--
-- EXPLAIN SELECT p.rowid
-- FROM llx_product p
-- INNER JOIN llx_product_extrafields pe ON pe.fk_object = p.rowid
-- WHERE pe.producto_publicado = 1
-- ORDER BY p.rowid ASC
-- LIMIT 30;
--
-- EXPLAIN SELECT filename, filepath
-- FROM llx_ecm_files
-- WHERE src_object_type = 'product' AND src_object_id IN (49, 1, 2, 3, 5)
-- ORDER BY rowid ASC;
