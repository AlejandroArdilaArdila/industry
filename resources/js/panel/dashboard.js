// Dashboard del panel. Los datos llegan de PanelDashboardService (JSON inicial incrustado
// y /panel/dashboard/data al cambiar filtros); aquí solo viven el pintado y la interacción.
import '../../css/dashboard.css';
import { Chart, registerables } from 'chart.js';
import { iniciarEtapas } from './dashboard-etapas.js';
import { iniciarActividades } from './dashboard-actividades.js';
import { iniciarLeyenda } from './dashboard-leyenda.js';
import { iniciarAlertas } from './alertas-vencidas.js';
import { iniciarPlanDia } from './plan-dia.js';
import { iniciarColores } from './dashboard-colores.js';
import { iniciarProceso } from './dashboard-proceso.js';

Chart.register(...registerables);

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const cfg = JSON.parse($('d-config').textContent);
const estado = { periodo: '30', vendedor: '', auto: false, timer: null, ctrl: null, datos: null, charts: {} };

try {
    const pref = JSON.parse(localStorage.getItem('panel-dashboard') || '{}');
    if (pref.periodo && document.querySelector(`#d-periodos [data-periodo="${pref.periodo}"]`)) estado.periodo = pref.periodo;
} catch { /* sin almacenamiento */ }

let alertas = null; // se crea justo después; las acciones del panel lateral lo vuelven a consultar
const actividades = iniciarActividades({ onCambio: () => alertas?.revisar(), url: cfg.urlActividades, panelUrl: cfg.urlPanelActividades, csrf: cfg.csrf, tareas: { form: cfg.urlTareaForm, show: cfg.urlTareaShow, estado: cfg.urlTareaEstado, eliminar: cfg.urlTareaEliminar, rechazar: cfg.urlTareaRechazar }, ms: cfg.urlMicrosoft ? { estado: cfg.urlMicrosoft, conectar: cfg.urlMicrosoftConectar, prefs: cfg.urlMicrosoftPrefs, sync: cfg.urlMicrosoftSync, eventos: cfg.urlMicrosoftEventos, eventoEliminar: cfg.urlMicrosoftEventoEliminar, eventoRechazar: cfg.urlMicrosoftEventoRechazar, base: cfg.urlMicrosoftBase } : null });
// Modal de actividades vencidas (tareas y recordatorios atrasados): se gestionan sin salir del dashboard.
alertas = iniciarAlertas({
    auto: false, // la primera revisión la hace el modal «Mi día» (si hay vencidas, es su primera sugerencia)
    url: cfg.urlAlertas, silenciarUrl: cfg.urlAlertasSilenciar, csrf: cfg.csrf,
    urls: { tareaEstado: cfg.urlTareaEstado, tareaPosponer: cfg.urlTareaPosponer, eventoPosponer: cfg.urlEventoPosponer },
    abrirTarea: (id) => actividades.abrirTarea(id), abrirDocumento: (m, id) => actividades.abrir(m, id), onCambio: () => actividades.cargar(),
});
const etapas = iniciarEtapas({ url: cfg.urlEtapas, urlDocumentos: cfg.urlDocumentos, getPeriodo: () => estado.periodo, esAdmin: cfg.esAdmin, onAbrir: (modulo, id) => actividades.abrir(modulo, id) });
// Modal «Mi día»: sugerencias para gestionar el día. Al cerrarlo con «Empezar mi día» arranca la rotación del tablero desde Solicitudes.
const hh = new Date().getHours();
const planDia = iniciarPlanDia({
    url: cfg.urlPlanDia, userId: cfg.userId,
    saludo: `${hh < 12 ? 'Buenos días' : hh < 19 ? 'Buenas tardes' : 'Buenas noches'}, ${cfg.usuario}`,
    acciones: {
        pausar: () => etapas.pausar(),
        empezar: () => etapas.reproducir(),
        vencidas: () => alertas.revisar(),
        actividades: (f) => actividades.filtrar(f),
        etapa: (clave, nivel) => etapas.ver(clave, nivel),
        abrirDoc: (m, id) => actividades.abrir(m, id),
        programar: () => actividades.programar(),
    },
});
document.getElementById('d-mi-dia')?.addEventListener('click', () => planDia.abrir());
// Siempre que se llega al tablero se muestra el plan del día. Mientras se prepara (y se lee) el flujo comercial queda pausado;
// al cerrar el modal arranca la presentación desde Solicitudes (o de inmediato si no se pudo mostrar).
etapas.pausar();
planDia.abrir().then((mostro) => { if (!mostro) etapas.reproducir(); });

const proceso = iniciarProceso({
    url: cfg.urlProceso,
    urlDocumentos: cfg.urlProcesoDocs,
    onAbrir: (modulo, id, lista) => actividades.abrir(modulo, id, lista),
    getAlcance: () => etapas.alcance(),
    onVerAcciones: (tab) => { etapas.seleccionar(tab); document.getElementById('d-etapas-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); },
});
const leyenda = iniciarLeyenda({ url: cfg.urlLeyenda, getPeriodo: () => estado.periodo, getAlcance: () => etapas.alcance() });

if (cfg.esAdmin && cfg.urlColores) {
    iniciarColores({
        url: cfg.urlColores,
        csrf: cfg.csrf,
        onCambio: (mapa) => { etapas.aplicarColores(mapa); actividades.colores({ 1: etapas.colores()[0], 2: etapas.colores()[1], 3: etapas.colores()[2], 4: etapas.colores()[3] }); },
    });
}

/** Carga las dos tarjetas de actividad comercial; las actividades toman los colores configurados de las cards. */
function cargarComercial() {
    actividades.cargar();
    leyenda.cargar();
    if (cfg.soloComercial) proceso.cargar();
    return etapas.cargar().then(() => {
        const c = etapas.colores();
        if (c) actividades.colores({ 1: c[0], 2: c[1], 3: c[2], 4: c[3] });
        leyenda.etapas(etapas.datos()?.etapas ?? null);
    });
}

// ------------------------------------------------------------------ formato

const nf = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 });
const compacto = new Intl.NumberFormat('es-CO', { notation: 'compact', maximumFractionDigits: 1 });
const dinero = (v) => `${v < 0 ? '-' : ''}$${nf.format(Math.abs(Math.round(v)))}`;
const dineroCorto = (v) => `${v < 0 ? '-' : ''}$${Math.abs(v) >= 1e6 ? compacto.format(Math.abs(v)) : nf.format(Math.abs(Math.round(v)))}`;
const fecha = (iso, opts = { day: 'numeric', month: 'short' }) => new Date(`${iso}T00:00:00`).toLocaleDateString('es-CO', opts);
const MES = (k) => { const [y, m] = k.split('-'); return new Date(+y, +m - 1, 1).toLocaleDateString('es-CO', { month: 'short', year: '2-digit' }); };

// ------------------------------------------------------------------ colores del tema

const sonda = document.createElement('span');
sonda.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none';
document.body.appendChild(sonda);
const color = (variable) => { sonda.style.color = `var(${variable})`; return getComputedStyle(sonda).color; };
const alfa = (c, pct) => `color-mix(in oklab, ${c} ${pct}%, transparent)`;

function tema() {
    const primario = color('--primary');
    return { primario, texto: color('--muted-foreground'), linea: alfa(color('--muted-foreground'), 18), tarjeta: color('--card') };
}

// ------------------------------------------------------------------ KPIs

function contar(el, hasta, fmt) {
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

function delta(act, prev) {
    if (!prev) return act ? '<span class="chip">Nuevo</span>' : '';
    const d = ((act - prev) / Math.abs(prev)) * 100;
    const sube = d >= 0;
    return `<span class="chip ${sube ? 'up' : 'down'}"><i class="fa-solid fa-arrow-${sube ? 'up' : 'down'}"></i> ${Math.abs(d).toFixed(d > 99 ? 0 : 1).replace('.', ',')}% vs. periodo anterior</span>`;
}

function spark(valores) {
    if (!valores || valores.length < 2) return '';
    const w = 100, h = 30;
    const max = Math.max(...valores), min = Math.min(...valores);
    const pts = valores.map((v, i) => [(i / (valores.length - 1)) * w, h - 3 - (max === min ? 0.5 : (v - min) / (max - min)) * (h - 6)]);
    const d = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join(' ');
    return `<svg class="spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none"><path class="a" d="${d} L${w} ${h} L0 ${h}Z"/><path class="l" d="${d}"/></svg>`;
}

function pintarKpis(d) {
    const a = d.actual, p = d.previo;
    const defs = [
        { id: 'neto', lbl: 'Ventas netas', ico: 'fa-sack-dollar', c: 'var(--primary)', v: a.neto, p: p.neto, f: dineroCorto, full: dinero(a.neto), serie: d.serie.valores,
            extra: a.nNotas ? `<span class="chip down"><i class="fa-solid fa-rotate-left"></i> ${nf.format(a.nNotas)} notas crédito · ${dineroCorto(a.notas)}</span>` : '' },
        { id: 'facturas', lbl: 'Facturas emitidas', ico: 'fa-file-invoice-dollar', c: '#0ea5e9', v: a.facturas, p: p.facturas, f: (x) => nf.format(Math.round(x)), serie: d.serie.facturas },
        { id: 'ticket', lbl: 'Ticket promedio', ico: 'fa-receipt', c: '#8b5cf6', v: a.ticket, p: p.ticket, f: dineroCorto, full: dinero(a.ticket) },
        { id: 'unidades', lbl: 'Unidades vendidas', ico: 'fa-boxes-stacked', c: '#f59e0b', v: a.unidades, p: p.unidades, f: (x) => nf.format(Math.round(x)) },
        { id: 'clientes', lbl: 'Clientes con compra', ico: 'fa-users', c: '#10b981', v: a.clientes, p: p.clientes, f: (x) => nf.format(Math.round(x)) },
        { id: 'nuevos', lbl: 'Clientes nuevos', ico: 'fa-user-plus', c: '#ec4899', v: a.nuevosClientes, p: p.nuevosClientes, f: (x) => nf.format(Math.round(x)) },
    ];
    const cont = $('d-kpis');
    if (!cont.children.length) {
        cont.innerHTML = defs.map((k, i) => `
            <div class="dash-card kpi" style="--c:${k.c};--i:${i}" data-k="${k.id}">
                <div class="kpi-ico"><i class="fa-solid ${k.ico}"></i></div>
                <div class="kpi-lbl">${k.lbl}</div>
                <div class="kpi-val" data-v="0">0</div>
                <div class="kpi-sub"></div>
            </div>`).join('');
    }
    defs.forEach((k) => {
        const el = cont.querySelector(`[data-k="${k.id}"]`);
        const val = el.querySelector('.kpi-val');
        val.title = k.full ?? '';
        contar(val, k.v, k.f);
        el.querySelector('.kpi-sub').innerHTML = delta(k.v, k.p) + (k.extra ?? '');
        el.querySelector('.spark')?.remove();
        if (k.serie) el.insertAdjacentHTML('beforeend', spark(k.serie));
    });
}

// ------------------------------------------------------------------ insights

function pintarInsights(d) {
    const L = [];
    const a = d.actual, p = d.previo;
    if (p.neto) {
        const pct = ((a.neto - p.neto) / Math.abs(p.neto)) * 100;
        L.push([pct >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down', pct >= 0 ? '#10b981' : '#ef4444', `Ventas netas ${pct >= 0 ? 'suben' : 'bajan'} ${Math.abs(pct).toFixed(0)}% frente al periodo anterior`]);
    }
    const mejor = d.diaSemana.reduce((m, x, i) => (x.valor > (m.v ?? -Infinity) ? { i, v: x.valor } : m), {});
    if (mejor.v > 0) L.push(['fa-calendar-check', '#0ea5e9', `Tu mejor día es el ${['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'][mejor.i]}`]);
    if (d.topClientes[0] && a.bruto > 0) {
        const share = (d.topClientes[0].valor / a.bruto) * 100;
        L.push([share >= 25 ? 'fa-triangle-exclamation' : 'fa-handshake', share >= 25 ? '#f59e0b' : '#8b5cf6', `${d.topClientes[0].nombre} concentra el ${share.toFixed(0)}% de lo facturado`]);
    }
    if (a.bruto > 0 && Math.abs(a.notas) / a.bruto > 0.1) L.push(['fa-rotate-left', '#ef4444', `Las notas crédito equivalen al ${((Math.abs(a.notas) / a.bruto) * 100).toFixed(0)}% de lo facturado`]);
    if (d.ultimaVenta) {
        const dias = Math.floor((Date.now() - new Date(`${d.ultimaVenta}T00:00:00`)) / 864e5);
        if (dias > 7) L.push(['fa-hourglass-half', '#f59e0b', `Sin facturación hace ${dias} días (última: ${fecha(d.ultimaVenta, { day: 'numeric', month: 'short', year: 'numeric' })})`]);
    }
    if (d.catalogo.borradores) L.push(['fa-pen-ruler', '#64748b', `${d.catalogo.borradores} factura${d.catalogo.borradores === 1 ? '' : 's'} en borrador`]);
    if (!L.length) L.push(['fa-check', '#10b981', 'Todo en orden por ahora']);
    $('d-insights').innerHTML = L.map(([ico, c, t], i) => `<div class="ins" style="--c:${c};animation-delay:${i * 80}ms"><i class="fa-solid ${ico}"></i>${esc(t)}</div>`).join('');
}

// ------------------------------------------------------------------ gráficas

function grafica(id, config) {
    estado.charts[id]?.destroy();
    estado.charts[id] = new Chart($(id), config);
}

function degradado(ctx, area, c) {
    const g = ctx.createLinearGradient(0, area.top, 0, area.bottom);
    g.addColorStop(0, alfa(c, 38));
    g.addColorStop(1, alfa(c, 0));
    return g;
}

function ejes(t, fmtY) {
    return {
        x: { grid: { display: false }, border: { display: false }, ticks: { color: t.texto, maxRotation: 0, autoSkipPadding: 18 } },
        y: { grid: { color: t.linea }, border: { display: false }, ticks: { color: t.texto, callback: fmtY, maxTicksLimit: 6 } },
    };
}

function pintarMeses(d) {
    const t = tema();
    const m = d.meses;
    grafica('c-meses', {
        data: {
            labels: m.map((x) => MES(x.mes)),
            datasets: [
                { type: 'bar', label: 'Ventas netas', data: m.map((x) => x.valor), borderRadius: 8, borderSkipped: false, yAxisID: 'y', order: 2,
                    backgroundColor: m.map((_, i) => (i === m.length - 1 ? t.primario : alfa(t.primario, 55))) },
                { type: 'line', label: 'Facturas', data: m.map((x) => x.facturas), borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: 0.4, pointRadius: 3, borderWidth: 2.2, yAxisID: 'y1', order: 1 },
            ],
        },
        options: {
            maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, animation: { duration: 1100, easing: 'easeOutQuart' },
            scales: { ...ejes(t, dineroCorto), y1: { position: 'right', grid: { display: false }, border: { display: false }, beginAtZero: true, ticks: { color: t.texto, precision: 0, maxTicksLimit: 6 } } },
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, color: t.texto, boxWidth: 8 } }, tooltip: { callbacks: { label: (x) => ` ${x.dataset.label}: ${x.datasetIndex === 0 ? dinero(x.raw) : nf.format(x.raw)}` } } },
        },
    });
}

// ------------------------------------------------------------------ listas

function ranking(el, filas, vacio, fila) {
    if (!filas.length) { el.innerHTML = `<div class="vacio"><i class="fa-solid fa-box-open"></i>${vacio}</div>`; return; }
    const max = Math.max(...filas.map((f) => f.valor), 1);
    el.innerHTML = filas.map((f, i) => `
        <div class="rk"><div class="rk-n">${i + 1}</div>
            <div class="min-w-0"><b title="${esc(f.nombre)}">${esc(f.nombre)}</b><div class="rk-bar"><i data-w="${Math.max((f.valor / max) * 100, 3).toFixed(1)}"></i></div></div>
            <div class="rk-v">${dineroCorto(f.valor)}<small>${fila(f)}</small></div></div>`).join('');
    requestAnimationFrame(() => requestAnimationFrame(() => el.querySelectorAll('.rk-bar i').forEach((b) => { b.style.width = `${b.dataset.w}%`; })));
}

function pintarCalendario(d) {
    const pos = d.calendario.map((x) => x.v).filter((v) => v > 0).sort((a, b) => a - b);
    const corte = (q) => pos[Math.min(pos.length - 1, Math.floor(pos.length * q))] ?? 0;
    const [q1, q2, q3] = [corte(0.25), corte(0.5), corte(0.8)];
    $('d-heat').innerHTML = d.calendario.map((x, k) => {
        const n = x.v <= 0 ? 0 : x.v <= q1 ? 1 : x.v <= q2 ? 2 : x.v <= q3 ? 3 : 4;
        return `<i data-n="${n}" ${x.futuro ? 'data-futuro' : ''} style="--k:${k}" title="${fecha(x.f, { weekday: 'short', day: 'numeric', month: 'short' })}: ${x.futuro ? 'aún no' : dinero(x.v)}"></i>`;
    }).join('');
}

// ------------------------------------------------------------------ carga

function pintar(d) {
    estado.datos = d;
    const [a, z] = d.rango;
    $('d-subtitulo').textContent = `${d.periodoLabel} · ${fecha(a)} – ${fecha(z)}${d.ultimaVenta ? ` · última factura ${fecha(d.ultimaVenta, { day: 'numeric', month: 'short', year: 'numeric' })}` : ''}`;
    pintarKpis(d);
    pintarInsights(d);
    pintarMeses(d);
    ranking($('d-clientes'), d.topClientes, 'Sin ventas en el periodo.', (f) => `${f.facturas} factura${f.facturas === 1 ? '' : 's'}`);
    ranking($('d-productos'), d.topProductos, 'Sin productos vendidos en el periodo.', (f) => `${nf.format(f.unidades)} und.`);
    pintarCalendario(d);

    const sel = $('d-vendedor');
    if (d.vendedores.length > 1) {
        sel.innerHTML = '<option value="">Todos los vendedores</option>' + d.vendedores.map((v) => `<option value="${v.id}">${esc(v.nombre)}</option>`).join('');
        sel.value = estado.vendedor;
        sel.classList.remove('hidden');
    }
    $('d-live').classList.add('on');
    $('d-live-txt').textContent = `Actualizado ${d.generado}`;
}

async function cargar() {
    cargarComercial();
    estado.ctrl?.abort();
    estado.ctrl = new AbortController();
    const btn = $('d-refrescar');
    btn.classList.add('cargando');
    $('dash').classList.add('cargando');
    try {
        const qs = new URLSearchParams({ periodo: estado.periodo, ...(estado.vendedor ? { vendedor: estado.vendedor } : {}) });
        const res = await fetch(`${cfg.url}?${qs}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: estado.ctrl.signal });
        if (!res.ok || res.redirected) throw new Error(res.status === 401 || res.redirected ? 'La sesión expiró; recarga la página.' : `Error ${res.status}`);
        pintar(await res.json());
    } catch (e) {
        if (e.name === 'AbortError') return;
        console.error(e);
        $('d-live').classList.remove('on');
        $('d-live-txt').textContent = e.message || 'No se pudo actualizar';
    } finally {
        btn.classList.remove('cargando');
        $('dash').classList.remove('cargando');
    }
}

// ------------------------------------------------------------------ eventos

function marcar(grupo, selector, valor, attr) {
    document.querySelectorAll(`${grupo} ${selector}`).forEach((b) => b.classList.toggle('on', b.dataset[attr] === valor));
}

document.querySelectorAll('#d-periodos button').forEach((b) => b.addEventListener('click', () => {
    estado.periodo = b.dataset.periodo;
    marcar('#d-periodos', 'button', estado.periodo, 'periodo');
    try { localStorage.setItem('panel-dashboard', JSON.stringify({ periodo: estado.periodo })); } catch { /* sin almacenamiento */ }
    cargar();
}));
$('d-vendedor').addEventListener('change', (e) => { estado.vendedor = e.target.value; cargar(); });
$('d-refrescar').addEventListener('click', cargar);
$('d-auto').addEventListener('click', (e) => {
    estado.auto = !estado.auto;
    e.currentTarget.classList.toggle('on', estado.auto);
    clearInterval(estado.timer);
    if (estado.auto) estado.timer = setInterval(() => { if (!document.hidden) cargar(); }, 60000);
});
document.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.metaKey || e.altKey || /input|select|textarea/i.test(e.target.tagName)) return;
    if (e.key.toLowerCase() === 'r') cargar();
});

// Si cambian el color o el modo del tema, las gráficas se redibujan con los colores nuevos.
let temaTimer;
new MutationObserver(() => {
    clearTimeout(temaTimer);
    temaTimer = setTimeout(() => { if (estado.datos) { pintarMeses(estado.datos); etapas.repintar(); leyenda.repintar(); } }, 80);
}).observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });

// ------------------------------------------------------------------ arranque

const h = new Date().getHours();
$('d-hola').textContent = `${h < 12 ? 'Buenos días' : h < 19 ? 'Buenas tardes' : 'Buenas noches'}, ${cfg.usuario}`;
Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
Chart.defaults.font.size = 11.5;

marcar('#d-periodos', 'button', estado.periodo, 'periodo');
const inicial = JSON.parse($('d-inicial').textContent);
if (inicial.periodo === estado.periodo) { pintar(inicial); cargarComercial(); } else cargar();
