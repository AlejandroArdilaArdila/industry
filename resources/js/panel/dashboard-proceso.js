// Línea de proceso comercial (vista de negociadores): una columna por etapa con el total de documentos y la fecha más
// antigua (desde el año configurado en INFORMESSOLU_ANIO_DESDE) y, a la derecha, la etapa más atrasada frente a su meta.
import Swal from 'sweetalert2';
import { esc, nf, fecha } from './dashboard-util.js';


const ICONOS = {
    solicitud: 'fa-inbox', localizacion: 'fa-location-dot', cotizacion: 'fa-file-invoice-dollar', negociacion: 'fa-handshake', orden: 'fa-cart-shopping',
    produccion: 'fa-industry', despacho: 'fa-box-open', transito: 'fa-truck-fast', nacionalizacion: 'fa-landmark', entrega: 'fa-circle-check',
};

/** Texto blanco u oscuro según la luminosidad del fondo. */
const textoSobre = (hex) => {
    const n = parseInt(hex.slice(1), 16);
    const luz = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255;
    return luz > 0.65 ? '#1f2937' : '#ffffff';
};

export function iniciarProceso({ url, urlDocumentos, getAlcance, onVerAcciones, onAbrir }) {
    const cont = document.getElementById('d-proceso');
    if (!cont) return { cargar: () => {} };
    let ctrl = null;

    // La fecha lleva el año cuando no es el actual: así se nota un dato viejo (p. ej. «2 feb 2022»).
    const fechaCorta = (iso) => (iso.slice(0, 4) === String(new Date().getFullYear()) ? fecha(iso) : fecha(iso, { day: 'numeric', month: 'short', year: 'numeric' }));
    const IDX = { solicitud: 0, localizacion: 0, cotizacion: 1, negociacion: 1, orden: 2, produccion: 2 };

    function pintar(d) {
        const peor = d.masAtrasada;
        const pasos = d.etapas.map((e) => {
            const f = e.fecha ? fechaCorta(e.fecha) : '—';
            const rotulo = e.pendiente ? 'sin definir' : e.total === 0 ? 'sin documentos' : `${e.ultima ? 'última' : 'desde'} ${f}`;
            const dias = e.fecha ? ` · ${nf.format(e.dias)} días` : '';
            // Color propio de la etapa (Configuración → Panel) o, por defecto, el de la card del tablero de su fuente.
            const propio = e.colorPropio ? `--m:${e.colorPropio};--mt:${textoSobre(e.colorPropio)}` : '';
            const m = propio || (e.card && d.colores?.[e.card] ? `--m:${d.colores[e.card].fondo};--mt:${d.colores[e.card].texto || '#fff'}` : '');
            return `<li style="${m}" class="pr-paso ${e.pendiente ? '' : 'pr-click'} ${{ verde: 'ok', amarillo: 'aviso', rojo: 'mora', vacio: 'vacio', pend: 'pend' }[e.color]}" ${e.pendiente ? '' : `data-clave="${e.clave}" tabindex="0" role="button"`} title="${esc(e.nombre)}: ${e.pendiente ? 'sin definir (se configura en Configuración → Panel)' : `${nf.format(e.total)} documentos${e.fecha ? ` · ${e.ultima ? 'última' : 'más antigua'}: ${e.fecha}${dias}` : ''}`}">
                <span class="pr-ico"><i class="fa-solid ${ICONOS[e.clave] ?? 'fa-circle'}"></i>${e.pendiente ? '' : '<span class="pr-dot"></span>'}</span>
                <b>${esc(e.nombre)}</b>
                <span class="pr-num">${nf.format(e.total)}</span>
                <small>${esc(rotulo)}</small>
            </li>`;
        }).join('');

        let tarjeta = '<div class="pr-meta ok"><i class="fa-regular fa-circle-check pr-meta-ico"></i><div><small>Etapa más atrasada</small><strong>Todo en verde</strong></div></div>';
        if (peor) {
            const rojo = peor.color === 'rojo';
            const exceso = peor.dias - peor.amarillo;
            const pct = peor.amarillo ? Math.round((exceso / peor.amarillo) * 100) : 100;
            tarjeta = `<div class="pr-meta ${rojo ? 'mora' : 'aviso'}">
                <i class="fa-regular fa-clock pr-meta-ico"></i>
                <div><small>Tiempo en ${esc(peor.nombre.toLowerCase())}</small><strong>${nf.format(peor.dias)} días</strong>
                    <span>${rojo ? `Rojo desde ${nf.format(peor.amarillo + 1)} días (<b>+${nf.format(exceso)}</b>)` : `Verde hasta ${nf.format(peor.verde)} días · en alerta`}</span></div>
                ${rojo ? `<em>+${pct > 999 ? '999+' : pct}%</em>` : ''}
                <button type="button" class="dash-btn dash-btn-sm" data-ver="${IDX[peor.clave] ?? 3}">Ver acciones</button>
            </div>`;
        }
        const sem = d.semaforo ? `--sv:${d.semaforo.verde};--sa:${d.semaforo.amarillo};--sr:${d.semaforo.rojo}` : '';
        cont.innerHTML = `<ol class="pr-linea" style="${sem}">${pasos}</ol>${tarjeta.replace('<div class="pr-meta', `<div style="${sem}" class="pr-meta`)}`;
    }

    // Clic en una etapa: abre de una vez el seguimiento del primer documento (el más antiguo) y deja la lista de la etapa
    // para «Cambiar documento», que muestra los que faltan por revisar.
    async function abrirDirecto(clave) {
        document.body.style.cursor = 'progress';
        try {
            const res = await fetch(`${urlDocumentos.replace('__E__', clave)}?${new URLSearchParams({ alcance: getAlcance() })}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error(`Error ${res.status}`);
            const d = await res.json();
            if (!d.items.length) return Swal.fire({ icon: 'info', title: d.etapa, text: 'No hay documentos en esta etapa.' });
            const x = d.items[0];
            onAbrir?.(x.modulo, x.doc, { etapa: d.etapa, items: d.items });
        } catch (err) {
            console.error(err);
            Swal.fire({ icon: 'error', title: 'No se pudieron cargar los documentos', text: err.message });
        } finally { document.body.style.cursor = ''; }
    }
    const abrirPaso = (ev) => {
        const li = ev.target.closest('[data-clave]');
        if (li) abrirDirecto(li.dataset.clave);
    };
    cont.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrirPaso(ev); } });
    cont.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-ver]');
        if (b) return onVerAcciones(+b.dataset.ver);
        abrirPaso(ev);
    });

    async function cargar() {
        ctrl?.abort();
        ctrl = new AbortController();
        try {
            const res = await fetch(`${url}?${new URLSearchParams({ alcance: getAlcance() })}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: ctrl.signal });
            if (!res.ok) throw new Error(`Error ${res.status}`);
            pintar(await res.json());
        } catch (err) {
            if (err.name === 'AbortError') return;
            console.error(err);
            cont.innerHTML = '<div class="vacio"><i class="fa-solid fa-triangle-exclamation"></i>No se pudo cargar la línea de proceso.</div>';
        }
    }

    return { cargar };
}
