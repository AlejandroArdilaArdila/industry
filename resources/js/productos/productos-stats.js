// Tarjetas del listado de productos según su actividad comercial: en cuántas solicitudes, cotizaciones y órdenes de
// compra activas aparecen y cuáles están en documentos con seguimientos pendientes. Cada una filtra el grid.
import { iniciarFranja } from '../panel/stat-strip.js';

export function iniciarProductosStats({ url, onFiltro }) {
    return iniciarFranja({
        raizId: 'productos-stats', filaId: 'productos-stats-fila', claveCerrado: 'productos-stats-cerrado', url, onFiltro,
        construir(d, { nf, chip, chipCambio, spark, pct }) {
            const t = d.tarjetas;
            const detalle = (x) => chip('', 'fa-cubes', `${nf.format(x.unidades)} unidades`) + chip('', 'fa-file-lines', `${nf.format(x.documentos)} documento${x.documentos === 1 ? '' : 's'}`)
                + chipCambio(x.nuevos30, x.nuevos30Previo, (p) => `${nf.format(x.nuevos30)} nuevos 30 d${p ? ` (${p}%)` : ''}`);
            const seg = t.seguimientos;

            return {
                hora: `Actualizado ${d.generado} · ${d.alcance === 'mios' ? 'tus documentos' : 'todo el equipo'} · documentos desde ${d.desdeAnio}`,
                defs: [
                    { k: 'solicitudes', c: d.colores.solicitudes, ico: 'fa-inbox', lbl: 'En solicitudes activas', v: t.solicitudes.productos, etiqueta: 'En solicitudes activas',
                        sub: detalle(t.solicitudes), extra: spark(t.solicitudes.serie) },
                    { k: 'cotizaciones', c: d.colores.cotizaciones, ico: 'fa-file-invoice-dollar', lbl: 'En cotizaciones activas', v: t.cotizaciones.productos, etiqueta: 'En cotizaciones activas',
                        sub: detalle(t.cotizaciones), extra: spark(t.cotizaciones.serie) },
                    { k: 'ordenes', c: d.colores.ordenes, ico: 'fa-shopping-cart', lbl: 'En órdenes de compra', v: t.ordenes.productos, etiqueta: 'En órdenes de compra activas',
                        sub: detalle(t.ordenes), extra: spark(t.ordenes.serie) },
                    { k: 'seguimientos', c: '#f59e0b', ico: 'fa-bell', lbl: 'Con seguimientos pendientes', v: seg.productos, etiqueta: 'En documentos con seguimientos pendientes',
                        sub: seg.recordatorios
                            ? (seg.atrasados ? chip('down', 'fa-clock', `${nf.format(seg.atrasados)} atrasados`) : '') + (seg.hoy ? chip('', 'fa-calendar-day', `${nf.format(seg.hoy)} para hoy`) : '') + chip('', 'fa-bell', `${nf.format(seg.recordatorios)} recordatorios`)
                            : `${chip('up', 'fa-check', 'al día')}<span>Se programan desde el Dashboard</span>` },
                    { k: 'actividad', c: '#7c3aed', ico: 'fa-diagram-project', lbl: 'Productos con actividad', special: 'anillo', pct: pct(d.conActividad, d.totalProductos), v: d.conActividad, etiqueta: 'Con actividad comercial',
                        leyendaAnillo: 'del catálogo', sub: chip('', '', `${nf.format(d.conActividad)} de ${nf.format(d.totalProductos)} productos`) },
                ],
            };
        },
    });
}
