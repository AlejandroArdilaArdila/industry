// Panel de colores de las cards del "Tablero comercial" y del "Flujo comercial" (solo administradores).
// Lo guardado vale para todos los usuarios; mientras se edita, el tablero muestra una vista previa en vivo.
import { esc } from './dashboard-util.js';

const CARDS = [
    { n: 1, nombre: 'Solicitudes', campos: ['fondo', 'texto', 'acento'] },
    { n: 2, nombre: 'Cotización / Propuesta', campos: ['fondo', 'texto', 'acento'] },
    { n: 3, nombre: 'Órdenes de compra', campos: ['fondo', 'texto', 'acento'] },
    { n: 4, nombre: 'Entrega / Solución', campos: ['fondo', 'texto', 'acento'] },
    { n: 5, nombre: 'Tasa de aprobación', campos: ['fondo'] },
];
const ROTULO = { fondo: 'Fondo', texto: 'Texto', acento: 'Acento' };
const AYUDA = 'Fondo: color de la card y de la pestaña activa · Texto: letras sobre el fondo · Acento: barra y detalles del flujo';
const HEX = /^#[0-9a-f]{6}$/i;

export function iniciarColores({ url, csrf, onCambio }) {
    let modal = null;
    let original = null; // colores vigentes al abrir, para cancelar la vista previa
    let actual = null;

    const hdr = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf };
    const clon = (o) => JSON.parse(JSON.stringify(o));

    function cerrar(revertir) {
        if (revertir && original) onCambio(original);
        modal?.remove();
        modal = null;
        document.removeEventListener('keydown', teclas);
    }
    const teclas = (ev) => { if (ev.key === 'Escape') cerrar(true); };

    function aviso(texto, error = false) {
        const el = modal?.querySelector('[data-msg]');
        if (!el) return;
        el.textContent = texto;
        el.className = `col-msg ${error ? 'err' : ''}`;
    }

    function dibujar() {
        modal.querySelector('[data-filas]').innerHTML = CARDS.map((c) => `
            <div class="col-fila">
                <span class="col-pre" data-pre="${c.n}" style="background:${esc(actual[c.n].fondo)};color:${esc(actual[c.n].texto || '#fff')}">${esc(c.nombre)}</span>
                <div class="col-campos">${c.campos.map((k) => `
                    <label class="col-campo"><small>${ROTULO[k]}</small>
                        <span><input type="color" data-n="${c.n}" data-k="${k}" value="${esc(actual[c.n][k])}"><input type="text" maxlength="7" spellcheck="false" data-n="${c.n}" data-k="${k}" data-hex value="${esc(actual[c.n][k])}"></span>
                    </label>`).join('')}</div>
            </div>`).join('');
    }

    function cambiar(n, k, valor) {
        actual[n][k] = valor.toLowerCase();
        const pre = modal.querySelector(`[data-pre="${n}"]`);
        pre.style.background = actual[n].fondo;
        pre.style.color = actual[n].texto || '#fff';
        modal.querySelectorAll(`[data-n="${n}"][data-k="${k}"]`).forEach((i) => { if (i.value.toLowerCase() !== valor.toLowerCase()) i.value = valor; });
        onCambio(clon(actual));
    }

    async function guardar(restablecer) {
        const btn = modal.querySelector(restablecer ? '[data-reset]' : '[data-guardar]');
        btn.disabled = true;
        try {
            const res = await fetch(url, { method: 'PUT', headers: hdr, body: JSON.stringify({ colores: restablecer ? {} : actual }) });
            const j = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(j.errors ? Object.values(j.errors)[0][0] : (j.message || `Error ${res.status}`));
            onCambio(clon(j.colores));
            original = null; // ya es lo guardado: cerrar no revierte
            cerrar(false);
        } catch (err) {
            aviso(err.message, true);
            btn.disabled = false;
        }
    }

    async function abrir() {
        if (modal) return;
        modal = document.createElement('div');
        modal.className = 'col-overlay';
        modal.innerHTML = `
            <div class="col-modal" role="dialog" aria-modal="true" aria-label="Colores de las cards">
                <header><h3><i class="fa-solid fa-palette"></i> Colores de las cards</h3><button type="button" class="dash-ib" data-cerrar title="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>
                <p class="col-ayuda">Se aplican al Tablero comercial y al Flujo comercial, y los verán todos los usuarios. ${AYUDA}.</p>
                <div data-filas class="col-filas"><div class="vacio"><i class="fa-solid fa-circle-notch fa-spin"></i></div></div>
                <footer><span data-msg class="col-msg"></span>
                    <button type="button" class="dash-btn" data-reset><i class="fa-solid fa-rotate-left"></i> Restablecer</button>
                    <button type="button" class="dash-btn" data-cancelar>Cancelar</button>
                    <button type="button" class="dash-btn dash-btn-pri" data-guardar><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
                </footer>
            </div>`;
        document.body.appendChild(modal);
        document.addEventListener('keydown', teclas);
        modal.addEventListener('mousedown', (ev) => { if (ev.target === modal) cerrar(true); });
        modal.addEventListener('click', (ev) => {
            if (ev.target.closest('[data-cerrar],[data-cancelar]')) cerrar(true);
            else if (ev.target.closest('[data-guardar]')) guardar(false);
            else if (ev.target.closest('[data-reset]') && confirm('¿Volver a los colores de fábrica para todos los usuarios?')) guardar(true);
        });
        modal.addEventListener('input', (ev) => {
            const i = ev.target;
            if (!i.dataset.k) return;
            if (i.dataset.hex !== undefined && !HEX.test(i.value)) return;
            cambiar(+i.dataset.n, i.dataset.k, i.value);
        });

        try {
            const res = await fetch(url, { headers: hdr });
            if (!res.ok) throw new Error(`Error ${res.status}`);
            const j = await res.json();
            actual = {};
            CARDS.forEach((c) => { actual[c.n] = Object.fromEntries(c.campos.map((k) => [k, (j.colores[c.n]?.[k] ?? '#ffffff').toLowerCase()])); });
            original = clon(actual);
            dibujar();
        } catch (err) {
            modal.querySelector('[data-filas]').innerHTML = `<div class="vacio"><i class="fa-solid fa-triangle-exclamation"></i>No se pudieron cargar los colores: ${esc(err.message)}</div>`;
        }
    }

    document.querySelectorAll('[data-colores]').forEach((b) => b.addEventListener('click', abrir));
}
