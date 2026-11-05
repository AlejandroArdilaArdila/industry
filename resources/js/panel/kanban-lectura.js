// Tablero (kanban) genérico para los módulos cuyos documentos no se arrastran (cotizaciones, localización): columnas que cargan
// de a poco con «Ver más», búsqueda, filtro opcional por estado y seguimiento rápido. Cada módulo pone sus columnas y su tarjeta.
// Usa las clases kb-* de solicitudes-tablero.css, así que se ve igual que el tablero de solicitudes.
import '../../css/solicitudes-tablero.css';
import { toast } from './ficha-modal.js';
import { esc } from './dashboard-util.js';

export const fechaCorta = (iso) => (iso ? new Date(`${String(iso).slice(0, 10)}T00:00:00`).toLocaleDateString('es-CO', { day: 'numeric', month: 'short' }) : '');
export const iniciales = (n) => String(n ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';
const hoy = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/**
 * @param {object} o
 * @param {HTMLElement} o.raiz  contenedor con [data-kb-cuerpo], [data-kb-q] y, si se quiere, [data-kb-estado]
 * @param {Array<{clave: string, titulo: string, icono: string, color: string, ayuda: string}>} o.columnas
 * @param {string} o.urlColumna  con __COL__
 * @param {(item: object) => string} o.tarjeta  HTML de <article class="kb-card" data-id="…">
 * @param {(acc: string, item: object, origen: string) => void} o.alAccion  clic en [data-acc] (o 'abrir' si es en la tarjeta)
 * @param {{url: string, modulo: string, etiqueta?: string}|null} o.seguimiento
 */
export function iniciarKanban({ raiz, columnas, urlColumna, tarjeta, alAccion, seguimiento = null, csrfToken, vacio = 'Nada por aquí' }) {
    const estado = {};
    const filtros = { q: '', estado: '' };
    const cuerpo = raiz.querySelector('[data-kb-cuerpo]');
    const pedir = async (url, metodo = 'GET', datos = null) => {
        const r = await fetch(url, {
            method: metodo,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest', ...(datos ? { 'Content-Type': 'application/json' } : {}) },
            body: datos ? JSON.stringify(datos) : undefined,
        });
        const json = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(json.message || Object.values(json.errors ?? {})[0]?.[0] || 'No se pudo completar la acción.');
        return json;
    };

    cuerpo.innerHTML = columnas.map((c) => `
        <section class="kb-col" data-col="${c.clave}" style="--c:${c.color}">
            <header class="kb-col-cab" title="${esc(c.ayuda)}">
                <span class="kb-col-ico"><i class="fa-solid ${c.icono}"></i></span>
                <b>${esc(c.titulo)}</b><small>${esc(c.ayuda)}</small>
                <span class="kb-col-n" data-n>–</span>
            </header>
            <div class="kb-col-lista" data-lista></div>
        </section>`).join('');
    const col = (clave) => cuerpo.querySelector(`[data-col="${clave}"]`);

    function pintar(clave) {
        const e = estado[clave];
        const c = col(clave);
        c.querySelector('[data-n]').textContent = e.total;
        const lista = c.querySelector('[data-lista]');
        if (e.cargando && !e.items.length) { lista.innerHTML = '<div class="kb-esqueleto"></div><div class="kb-esqueleto"></div><div class="kb-esqueleto"></div>'; return; }
        const def = columnas.find((x) => x.clave === clave);
        lista.innerHTML = e.items.length
            ? e.items.map(tarjeta).join('') + (e.items.length < e.total ? `<button type="button" class="kb-mas-items" data-acc="mas">${e.cargando ? 'Cargando…' : `Ver más (${e.total - e.items.length})`}</button>` : '')
            : `<div class="kb-vacio"><i class="fa-solid ${def.icono}"></i><span>${filtros.q || filtros.estado ? 'Nada con esos filtros' : esc(vacio)}</span></div>`;
    }

    async function cargar(clave, mas = false) {
        const e = estado[clave] ??= { items: [], total: 0, cargando: false };
        if (e.cargando) return;
        e.cargando = true;
        if (!mas) e.items = [];
        pintar(clave);
        const p = new URLSearchParams();
        if (filtros.q) p.set('q', filtros.q);
        if (filtros.estado) p.set('estado', filtros.estado);
        if (mas) p.set('skip', e.items.length);
        try {
            const r = await pedir(`${urlColumna.replace('__COL__', clave)}?${p}`);
            e.items = mas ? e.items.concat(r.items) : r.items;
            e.total = r.total;
        } catch (err) { toast.fire({ icon: 'error', title: err.message }); }
        e.cargando = false;
        pintar(clave);
    }
    const cargarTodas = () => columnas.forEach((c) => cargar(c.clave));
    const buscar = (id) => {
        for (const c of columnas) { const s = estado[c.clave]?.items.find((x) => x.id === id); if (s) return { s, origen: c.clave }; }
        return null;
    };

    // ------------------------------------------------------------ seguimiento rápido
    const cerrarFlotantes = () => document.querySelectorAll('.kb-flotante').forEach((n) => n.remove());
    function seguimientoRapido(ancla, s, origen) {
        cerrarFlotantes();
        const f = document.createElement('div');
        f.className = 'kb-flotante';
        f.innerHTML = `<form class="kb-form"><b>Seguimiento · ${esc(s.ref)}</b>
            <textarea name="observacion" rows="3" maxlength="5000" placeholder="¿Qué pasó?" required></textarea>
            <div class="kb-form-pie"><button type="button" data-cancelar>Cancelar</button><button type="submit">Guardar</button></div></form>`;
        document.body.appendChild(f);
        const r = ancla.getBoundingClientRect();
        f.style.left = `${Math.max(8, Math.min(r.right - f.offsetWidth, innerWidth - f.offsetWidth - 8))}px`;
        f.style.top = `${r.bottom + f.offsetHeight + 8 > innerHeight ? Math.max(8, r.top - f.offsetHeight - 4) : r.bottom + 4}px`;
        const form = f.querySelector('form');
        form.elements.observacion.focus();
        f.querySelector('[data-cancelar]').addEventListener('click', cerrarFlotantes);
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const obs = form.elements.observacion.value.trim();
            if (!obs) return;
            const envio = form.querySelector('[type=submit]');
            envio.disabled = true;
            try {
                const res = await pedir(seguimiento.url, 'POST', { modulo: seguimiento.modulo, id: s.id, observacion: obs });
                cerrarFlotantes();
                toast.fire({ icon: 'success', title: res.message });
                cargar(origen);
            } catch (err) { envio.disabled = false; toast.fire({ icon: 'error', title: err.message }); }
        });
    }

    cuerpo.addEventListener('click', (ev) => {
        const a = ev.target.closest('[data-acc]');
        if (a?.dataset.acc === 'mas') { cargar(a.closest('.kb-col').dataset.col, true); return; }
        const tarj = ev.target.closest('.kb-card');
        if (!tarj) return;
        ev.preventDefault();
        const h = buscar(Number(tarj.dataset.id));
        if (!h) return;
        const acc = a?.dataset.acc ?? 'abrir';
        if (acc === 'seguimiento' && seguimiento) seguimientoRapido(a, h.s, h.origen);
        else alAccion(acc, h.s, h.origen);
    });
    document.addEventListener('click', (ev) => { if (!ev.target.closest('.kb-flotante, [data-acc=seguimiento]')) cerrarFlotantes(); });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrarFlotantes(); });
    cuerpo.addEventListener('scroll', cerrarFlotantes, true);

    let espera;
    raiz.querySelector('[data-kb-q]')?.addEventListener('input', (ev) => { clearTimeout(espera); espera = setTimeout(() => { filtros.q = ev.target.value.trim(); cargarTodas(); }, 300); });
    raiz.querySelector('[data-kb-estado]')?.addEventListener('change', (ev) => { filtros.estado = ev.target.value; cargarTodas(); });
    raiz.querySelector('[data-kb-recargar]')?.addEventListener('click', cargarTodas);
    cargarTodas();

    return { recargar: cargarTodas, hoy };
}
