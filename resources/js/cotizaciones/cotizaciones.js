// Tablero (kanban) de cotizaciones activas: Atrasadas, Atención y Al día según los días desde el último movimiento (fecha de la cotización o su último seguimiento).
// El estado de una cotización lo gobierna su propio módulo: las tarjetas se abren en el panel de seguimiento y no se arrastran.
import '../../css/dashboard.css';
import { esc, dineroCorto } from '../panel/dashboard-util.js';
import { iniciarKanban, fechaCorta, iniciales } from '../panel/kanban-lectura.js';
import { iniciarProceso } from '../panel/dashboard-proceso.js';
import { ActividadFichaModal } from '../panel/actividades-ficha.js';

const config = window.COTIZACIONES_CONFIG;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const NIVEL = (d) => (d <= 30 ? ['verde', 'Al día'] : d <= 90 ? ['ambar', 'Atención'] : ['rojo', 'Atrasada']);
const COLOR_ESTADO = { 1: '#2563eb', 2: '#0891b2' };

function tarjeta(s) {
    const [nivel, txt] = NIVEL(s.mora);
    const ultimo = s.ultimoSeguimiento;

    return `<article class="kb-card mora-${nivel}" data-id="${s.id}">
        <div class="kb-card-top">
            <a href="#" class="kb-ref" data-acc="abrir" title="Ver la cotización y su seguimiento"><i class="fa-solid fa-file-invoice-dollar"></i>${esc(s.ref)}</a>
            <span class="kb-mora" title="Días desde el último movimiento">${txt} · ${s.mora} d</span>
        </div>
        <div class="kb-cliente"><i class="fa-solid fa-building"></i><span>${esc(s.cliente || 'Sin cliente')}</span></div>
        <p class="kb-asunto" title="${esc(s.titulo)}">${esc(s.titulo) || '<em>Sin título</em>'}</p>
        <div class="kb-meta">
            <span class="kb-chip estado" style="--e:${COLOR_ESTADO[s.estado] ?? '#64748b'}">${esc(s.estadoNombre)}</span>
            ${s.valor ? `<span class="kb-chip ok" title="Valor cotizado (en pesos)"><i class="fa-solid fa-sack-dollar"></i>${dineroCorto(s.valor)}</span>` : ''}
            ${s.solicitud ? `<span class="kb-chip" title="Solicitud de origen"><i class="fa-solid fa-inbox"></i>${esc(s.solicitud)}</span>` : ''}
        </div>
        ${ultimo ? `<p class="kb-seg" title="${esc(ultimo.texto)}"><i class="fa-solid fa-comment-dots"></i><span>${esc(ultimo.texto)}</span>${ultimo.fecha ? `<time>${fechaCorta(ultimo.fecha)}</time>` : ''}</p>` : '<p class="kb-seg vacio"><i class="fa-regular fa-comment"></i><span>Sin seguimientos</span></p>'}
        <footer class="kb-pie">
            <span class="kb-avatar" title="${esc(s.creador || '')}">${esc(iniciales(s.creador))}</span>
            <span class="kb-prox ${s.vencida ? 'vencido' : ''}" title="Validez de la oferta">
                <i class="fa-regular ${s.vencida ? 'fa-clock' : 'fa-calendar'}"></i>${s.validaHasta ? (s.vencida ? `Venció ${fechaCorta(s.validaHasta)}` : `Válida hasta ${fechaCorta(s.validaHasta)}`) : 'Sin validez'}
            </span>
            <button type="button" class="kb-seguir" data-acc="seguimiento" title="Registrar seguimiento"><i class="fa-solid fa-comment-medical"></i>Seguimiento</button>
        </footer>
    </article>`;
}

document.addEventListener('DOMContentLoaded', () => {
    const raiz = document.getElementById('cotizaciones-kb');
    if (!raiz) return;
    const panel = new ActividadFichaModal({ panelUrl: config.panelUrl, csrfToken: csrf, onSaved: () => tablero.recargar() });
    const tablero = iniciarKanban({
        raiz, columnas: config.columnas, urlColumna: config.columnaUrl, csrfToken: csrf, vacio: 'Sin cotizaciones',
        seguimiento: { url: config.seguimientoUrl, modulo: 'cotizacion' },
        tarjeta,
        alAccion: (acc, s) => panel.abrirDocumento('cotizacion', s.id),
    });
    iniciarProceso({
        url: config.procesoUrl,
        urlDocumentos: config.procesoDocsUrl,
        getAlcance: () => 'todos',
        activa: 'cotizacion',
        compacta: true,
        onAbrir: (modulo, id, lista) => { panel.lista = lista ? { ...lista, vistos: new Set() } : null; panel.abrirDocumento(modulo, id); },
    }).cargar();
});
