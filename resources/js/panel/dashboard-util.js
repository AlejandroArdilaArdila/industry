// Utilidades compartidas por los módulos del dashboard (formato, escape y colores del tema).
export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export const nf = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 });
const compacto = new Intl.NumberFormat('es-CO', { notation: 'compact', maximumFractionDigits: 1 });
export const dinero = (v) => `${v < 0 ? '-' : ''}$${nf.format(Math.abs(Math.round(v)))}`;
export const dineroCorto = (v) => `${v < 0 ? '-' : ''}$${Math.abs(v) >= 1e6 ? compacto.format(Math.abs(v)) : nf.format(Math.abs(Math.round(v)))}`;
export const fecha = (iso, opts = { day: 'numeric', month: 'short' }) => new Date(`${iso.slice(0, 10)}T00:00:00`).toLocaleDateString('es-CO', opts);
export const hora = (dt) => dt.slice(11, 16);
export const mesCorto = (k) => { const [y, m] = k.split('-'); return new Date(+y, +m - 1, 1).toLocaleDateString('es-CO', { month: 'short', year: '2-digit' }); };
export const isoLocal = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

const sonda = document.createElement('span');
sonda.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none';
document.body.appendChild(sonda);
export const colorTema = (variable) => { sonda.style.color = `var(${variable})`; return getComputedStyle(sonda).color; };
export const alfa = (c, pct) => `color-mix(in oklab, ${c} ${pct}%, transparent)`;

/** Colores de fábrica de las cards del tablero (se reemplazan con los configurados en Dolibarr). */
export const COLORES_CARD = {
    1: { fondo: '#1e3a8a', texto: '#ffffff', acento: '#3b82f6' },
    2: { fondo: '#ea580c', texto: '#ffffff', acento: '#fb923c' },
    3: { fondo: '#064e3b', texto: '#ffffff', acento: '#059669' },
    4: { fondo: '#134e4a', texto: '#ffffff', acento: '#14b8a6' },
};

/** Anima un número hasta su valor final (respeta "reducir movimiento"). */
export function contar(el, hasta, fmt = (v) => nf.format(Math.round(v))) {
    const desde = +el.dataset.v || 0;
    el.dataset.v = hasta;
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = fmt(hasta); return; }
    const t0 = performance.now();
    const paso = (t) => {
        const k = Math.min(1, (t - t0) / 900);
        el.textContent = fmt(desde + (hasta - desde) * (1 - (1 - k) ** 3));
        if (k < 1) requestAnimationFrame(paso);
    };
    requestAnimationFrame(paso);
}
