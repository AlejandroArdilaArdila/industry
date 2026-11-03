// Franja de tarjetas estadísticas sobre un listado (Terceros, Productos…). Cada listado aporta `construir(datos, h)` con
// sus tarjetas; aquí vive lo común: carga, animación, plegado, tarjeta activa y chip de filtro.
//
//   construir(d, h) -> { hora: 'texto', defs: [{ k, c, ico, lbl, v, sub, extra, etiqueta, special?: 'anillo', pct? }] }
//   onFiltro({ actividad })  se llama al elegir una tarjeta ('' al quitar el filtro).
import '../../css/stat-cards.css';
import { nf, contar } from './dashboard-util.js';

export const compacto = new Intl.NumberFormat('es-CO', { notation: 'compact', maximumFractionDigits: 1 });
export const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);
export const chip = (cls, ico, txt) => `<span class="st-chip ${cls}">${ico ? `<i class="fa-solid ${ico}"></i> ` : ''}${txt}</span>`;

export function spark(valores) {
    if (valores.length < 2) return '';
    const w = 100, h = 22, max = Math.max(...valores), min = Math.min(...valores);
    const d = valores.map((v, i) => `${i ? 'L' : 'M'}${((i / (valores.length - 1)) * w).toFixed(1)} ${(h - 2 - (max === min ? 0.5 : (v - min) / (max - min)) * (h - 4)).toFixed(1)}`).join(' ');
    return `<svg class="st-spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none"><path class="a" d="${d} L${w} ${h} L0 ${h}Z"/><path class="l" d="${d}"/></svg>`;
}

/** Delta porcentual entre dos periodos como chip ("+12%" en verde, "-30%" en rojo). */
export function chipCambio(actual, previo, texto) {
    if (!previo) return chip('', 'fa-plus', texto(null));
    const p = ((actual - previo) / previo) * 100;
    return chip(p >= 0 ? 'up' : 'down', p >= 0 ? 'fa-arrow-up' : 'fa-arrow-down', texto(Math.abs(p).toFixed(0)));
}

export function iniciarFranja({ raizId, filaId, claveCerrado, url, onFiltro, construir }) {
    const raiz = document.getElementById(raizId);
    const fila = document.getElementById(filaId);
    const chipFiltro = raiz.querySelector('[data-filtro]');
    let datos = null;
    let activa = null;

    try { raiz.classList.toggle('cerrado', localStorage.getItem(claveCerrado) === '1'); } catch { /* sin almacenamiento */ }

    raiz.querySelector('[data-toggle]').addEventListener('click', () => {
        const cerrado = raiz.classList.toggle('cerrado');
        try { localStorage.setItem(claveCerrado, cerrado ? '1' : '0'); } catch { /* sin almacenamiento */ }
        window.dispatchEvent(new Event('resize')); // el grid recalcula su alto
        setTimeout(() => window.dispatchEvent(new Event('resize')), 480);
    });

    function marcarActiva(clave, etiqueta) {
        activa = clave;
        fila.querySelectorAll('.st-card').forEach((c) => c.classList.toggle('on', c.dataset.k === clave));
        chipFiltro.classList.toggle('on', !!clave);
        chipFiltro.querySelector('span').textContent = etiqueta ?? '';
    }

    function limpiar(avisar = true) {
        marcarActiva(null);
        if (avisar) onFiltro({ actividad: '' });
    }

    function pintar() {
        const { defs, hora } = construir(datos, { nf, chip, chipCambio, spark, pct, compacto });
        fila.innerHTML = defs.map((x, i) => {
            const cuerpo = x.special === 'anillo'
                ? `<div class="st-anillo"><svg viewBox="0 0 36 36"><circle class="bg" cx="18" cy="18" r="15.9" pathLength="100"/><circle class="fg" cx="18" cy="18" r="15.9" pathLength="100"/></svg><div><div class="st-val" data-v="0">0</div><div class="st-lbl" style="letter-spacing:0">${x.leyendaAnillo ?? ''}</div></div></div>`
                : '<div class="st-val" data-v="0">0</div>';
            return `<button type="button" class="st-card" data-k="${x.k}" style="--c:${x.c};--i:${i}" title="Filtrar el listado: ${x.etiqueta.toLowerCase()}">
                <div class="st-top"><span class="st-ico"><i class="fa-solid ${x.ico}"></i></span><span class="st-lbl">${x.lbl}</span></div>
                ${cuerpo}<div class="st-sub">${x.sub}</div>${x.extra ?? ''}
            </button>`;
        }).join('');

        defs.forEach((x) => {
            const card = fila.querySelector(`[data-k="${x.k}"]`);
            contar(card.querySelector('.st-val'), x.special === 'anillo' ? x.pct : x.v, x.special === 'anillo' ? (v) => `${Math.round(v)}%` : undefined);
            requestAnimationFrame(() => requestAnimationFrame(() => card.querySelector('.fg')?.style.setProperty('stroke-dashoffset', String(100 - x.pct))));
            card.addEventListener('click', () => {
                if (activa === x.k) { limpiar(); return; }
                marcarActiva(x.k, x.etiqueta);
                onFiltro({ actividad: x.k });
            });
        });
        raiz.querySelector('[data-hora]').textContent = hora;
    }

    async function cargar(fresco = false) {
        try {
            const res = await fetch(`${url}${fresco ? '?fresco=1' : ''}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (!res.ok) throw new Error(`Error ${res.status}`);
            datos = await res.json();
            const previa = activa;
            pintar();
            if (previa) fila.querySelector(`[data-k="${previa}"]`)?.classList.add('on');
        } catch (e) {
            console.error(e);
            raiz.querySelector('[data-hora]').textContent = 'No se pudieron cargar las estadísticas';
        }
    }

    chipFiltro.querySelector('button').addEventListener('click', () => limpiar());
    cargar();

    return { cargar, limpiar: () => limpiar(false) };
}
