// Tarjetas del listado de solicitudes por etapa: activas, en proceso, con cotización enviada y en órdenes.
// Cada una filtra el grid con los mismos estados que cuenta.
import { iniciarFranja } from '../panel/stat-strip.js';

export function iniciarSolicitudesStats({ url, onFiltro }) {
    return iniciarFranja({
        raizId: 'solicitudes-stats', filaId: 'solicitudes-stats-fila', claveCerrado: 'solicitudes-stats-cerrado', url, onFiltro,
        construir(d, { nf, chip, chipCambio, spark }) {
            const t = d.tarjetas;
            const nuevos = (x) => chipCambio(x.creados30, x.creados30Previo, (p) => `${nf.format(x.creados30)} en 30 días${p ? ` (${p}%)` : ''}`);
            const comun = (x) => chip('', 'fa-building', `${nf.format(x.clientes)} clientes`) + nuevos(x);
            const atrasadas = (x) => (x.atrasadas ? chip('down', 'fa-clock', `${nf.format(x.atrasadas)} con más de 30 días`) : '');

            return {
                hora: `Actualizado ${d.generado} · ${d.alcance === 'mios' ? 'tus solicitudes' : 'todo el equipo'} · desde ${d.desdeAnio}`,
                defs: [
                    { k: 'activas', c: d.colores.activas, ico: 'fa-inbox', lbl: 'Solicitudes activas', v: t.activas.total, etiqueta: 'Solicitudes activas',
                        sub: comun(t.activas) + atrasadas(t.activas), extra: spark(t.activas.serie) },
                    { k: 'proceso', c: d.colores.proceso, ico: 'fa-spinner', lbl: 'En proceso', v: t.proceso.total, etiqueta: 'Solicitudes en proceso (pedidas a proveedor o en importación)',
                        sub: comun(t.proceso) + atrasadas(t.proceso), extra: spark(t.proceso.serie) },
                    { k: 'cotizaciones', c: d.colores.cotizaciones, ico: 'fa-file-invoice-dollar', lbl: 'En cotización', v: t.cotizaciones.total, etiqueta: 'Solicitudes en cotización',
                        sub: comun(t.cotizaciones) + atrasadas(t.cotizaciones), extra: spark(t.cotizaciones.serie) },
                    { k: 'ordenes', c: d.colores.ordenes, ico: 'fa-shopping-cart', lbl: 'En órdenes de compra', v: t.ordenes.total, etiqueta: 'Solicitudes con orden de compra vigente',
                        sub: comun(t.ordenes), extra: spark(t.ordenes.serie) },
                ],
            };
        },
    });
}
