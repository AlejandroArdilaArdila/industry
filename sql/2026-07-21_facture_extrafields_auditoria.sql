-- Columna requerida por FacturaExtrafields::auditoria (historial de snapshots
-- antes/despues de la cotizacion). Coincide con la migracion de Laravel
-- database/migrations/2026_07_20_010000_add_auditoria_to_facture_extrafields.php,
-- que nunca se habia aplicado contra esta base (mercosto).

ALTER TABLE llx_facture_extrafields ADD COLUMN auditoria JSON NULL;
