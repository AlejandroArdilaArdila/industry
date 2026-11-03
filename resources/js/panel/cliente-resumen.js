// Resumen por meses de un cliente (solicitudes, cotizaciones y órdenes): se abre al hacer clic en la tarjeta
// «Importancia del cliente», tanto en el seguimiento de actividades como en la ficha de solicitudes.
import { esc } from './dashboard-util.js';

/** @param {string} url endpoint que devuelve { cliente, solicitudes, cotizaciones, ordenes, anios } */
export async function abrirResumenMeses(url) {
    const dlg = document.createElement('dialog');
    dlg.className = 'cm-dlg';
    dlg.innerHTML = '<div class="cm-cab"><h3>Resumen por meses</h3><button type="button" data-cerrar aria-label="Cerrar">&times;</button></div><div class="cm-cuerpo"><p class="cm-vacio">Cargando…</p></div>';
    dlg.addEventListener('click', (e) => { if (e.target === dlg || e.target.closest('[data-cerrar]')) dlg.close(); });
    dlg.addEventListener('close', () => dlg.remove());
    document.body.appendChild(dlg);
    dlg.showModal();
    try {
        const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok) throw new Error(`Error ${res.status}`);
        const d = await res.json();
        const nf = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 });
        const mes = (k) => new Date(`${k}-01T00:00:00`).toLocaleDateString('es-CO', { month: 'short', year: 'numeric' });
        const COLS = [['solicitudes', 'Solicitudes', 'fa-inbox', '#3b82f6', false], ['cotizaciones', 'Cotizaciones', 'fa-file-invoice-dollar', '#f59e0b', true], ['ordenes', 'Órdenes de compra', 'fa-shopping-cart', '#10b981', true]];
        dlg.querySelector('h3').innerHTML = `Resumen por meses <small>${esc(d.cliente)} · últimos 24 meses</small>`;
        dlg.querySelector('.cm-cuerpo').innerHTML = `<div class="cm-cols">${COLS.map(([k, nombre, icono, color, dinero]) => {
            const filas = d[k];
            const total = filas.reduce((a, f) => a + f.n, 0);
            const monto = filas.reduce((a, f) => a + f.v, 0);
            const max = Math.max(1, ...filas.map((f) => f.n));
            const activos = filas.filter((f) => f.n > 0);
            // Promedio mensual: últimos 12 meses (los 12 primeros de la lista, que va del más reciente al más antiguo).
            const u12 = filas.slice(0, 12);
            const prom = u12.reduce((a, f) => a + f.n, 0) / 12;
            const promV = u12.reduce((a, f) => a + f.v, 0) / 12;
            const hoy = new Date();
            const anios = (d.anios?.[k] ?? []).map((a) => `<li><b>${a.anio}</b><span>${nf.format(a.n)}</span>${dinero ? `<em>$ ${nf.format(a.v)}</em>` : '<em></em>'}<small>${nf.format(Math.round(a.n / (a.anio === hoy.getFullYear() ? hoy.getMonth() + 1 : 12) * 10) / 10)}/mes</small></li>`).join('');
            return `<section class="cm-col" style="--c:${color}">
                <header><span class="cm-ico"><i class="fa-solid ${icono}"></i></span><div><b>${nombre}</b><small>${nf.format(total)} en total${dinero ? ` · $ ${nf.format(monto)}` : ''}</small></div></header>
                <div class="cm-resumen">
                    <div class="cm-prom"><small>Promedio mensual · últimos 12 meses</small><b>${nf.format(Math.round(prom * 10) / 10)} <span>docs/mes</span></b>${dinero ? `<em>$ ${nf.format(promV)} / mes</em>` : ''}</div>
                    <div class="cm-anios"><small>Resumen por año</small><ol>${anios || '<li class="cm-vacio">Sin datos</li>'}</ol></div>
                </div>
                <ul>${activos.length ? activos.map((f) => `<li><span class="cm-mes">${esc(mes(f.mes))}</span><span class="cm-barra"><i style="width:${Math.max(6, Math.round((f.n / max) * 100))}%"></i></span><b>${nf.format(f.n)}</b>${dinero ? `<em>$ ${nf.format(f.v)}</em>` : ''}</li>`).join('') : '<li class="cm-vacio">Sin movimiento en estos meses</li>'}</ul>
            </section>`;
        }).join('')}</div>`;
    } catch (err) {
        dlg.querySelector('.cm-cuerpo').innerHTML = `<p class="cm-vacio">No se pudo cargar: ${esc(err.message)}</p>`;
    }
}
