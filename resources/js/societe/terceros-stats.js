// Tarjetas del listado de terceros según su actividad comercial (solicitudes, cotizaciones, órdenes de compra y
// seguimientos pendientes). Cada una filtra el grid con los terceros que cuenta.
import { iniciarFranja } from '../panel/stat-strip.js';

export function iniciarTercerosStats({ url, onFiltro }) {
    return iniciarFranja({
        raizId: 'terceros-stats', filaId: 'terceros-stats-fila', claveCerrado: 'terceros-stats-cerrado', url, onFiltro,
        construir(d, { nf, chip, chipCambio, spark, pct, compacto }) {
            const t = d.tarjetas;
            const dinero = (v) => `$${compacto.format(v)}`;
            const nuevos = (x) => chipCambio(x.creados30, x.creados30Previo, (p) => `${nf.format(x.creados30)} en 30 días${p ? ` (${p}%)` : ''}`);
            const seg = t.seguimientos;

            return {
                hora: `Actualizado ${d.generado} · ${d.alcance === 'mios' ? 'tus documentos' : 'todo el equipo'} · documentos desde ${d.desdeAnio}`,
                defs: [
                    { k: 'solicitudes', c: d.colores.solicitudes, ico: 'fa-inbox', lbl: 'Solicitudes activas', v: t.solicitudes.activos, etiqueta: 'Con solicitudes activas',
                        sub: chip('', 'fa-building', `${nf.format(t.solicitudes.terceros)} terceros`) + nuevos(t.solicitudes), extra: spark(t.solicitudes.serie) },
                    { k: 'cotizaciones', c: d.colores.cotizaciones, ico: 'fa-file-invoice-dollar', lbl: 'Cotizaciones activas', v: t.cotizaciones.activos, etiqueta: 'Con cotizaciones activas',
                        sub: chip('', 'fa-building', `${nf.format(t.cotizaciones.terceros)} terceros`) + chip('', 'fa-coins', dinero(t.cotizaciones.valor)) + nuevos(t.cotizaciones), extra: spark(t.cotizaciones.serie) },
                    { k: 'ordenes', c: d.colores.ordenes, ico: 'fa-shopping-cart', lbl: 'Órdenes de compra', v: t.ordenes.activos, etiqueta: 'Con órdenes de compra activas',
                        sub: chip('', 'fa-building', `${nf.format(t.ordenes.terceros)} terceros`) + chip('', 'fa-coins', dinero(t.ordenes.valor)) + nuevos(t.ordenes), extra: spark(t.ordenes.serie) },
                    { k: 'seguimientos', c: '#f59e0b', ico: 'fa-bell', lbl: 'Seguimientos pendientes', v: seg.activos, etiqueta: 'Con seguimientos pendientes',
                        sub: seg.activos
                            ? (seg.atrasados ? chip('down', 'fa-clock', `${nf.format(seg.atrasados)} atrasados`) : '') + (seg.hoy ? chip('', 'fa-calendar-day', `${nf.format(seg.hoy)} para hoy`) : '') + chip('', 'fa-building', `${nf.format(seg.terceros)} terceros`)
                            : `${chip('up', 'fa-check', 'al día')}<span>Se programan desde el Dashboard</span>` },
                    { k: 'actividad', c: '#7c3aed', ico: 'fa-diagram-project', lbl: 'Terceros con actividad', special: 'anillo', pct: pct(d.conActividad, d.totalTerceros), v: d.conActividad, etiqueta: 'Con actividad comercial',
                        leyendaAnillo: 'de los terceros activos', sub: chip('', '', `${nf.format(d.conActividad)} de ${nf.format(d.totalTerceros)} activos`) },
                ],
            };
        },
    });
}
