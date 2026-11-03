// "Flujo comercial": pestañas SOLICITUDES / COTIZACIÓN / ÓRDENES DE COMPRA / ENTREGA con los colores de las cards
// del tablero. Rotan solas con un temporizador circular; el cursor encima (o la pausa manual) lo detiene.
import { Chart } from 'chart.js';
import { esc, nf, dineroCorto, fecha, mesCorto, colorTema, alfa, contar } from './dashboard-util.js';

const ROTACION_MS = 8000;
const $ = (id) => document.getElementById(id);

export function iniciarEtapas({ url, urlDocumentos, getPeriodo, esAdmin, onAbrir }) {
    const e = {
        datos: null, idx: 0, restante: ROTACION_MS, manual: matchMedia('(prefers-reduced-motion: reduce)').matches,
        encima: false, visible: true, metrica: 'cantidad', alcance: 'todos', chart: null, ctrl: null,
    };
    const docs = { cache: new Map(), seq: 0, q: '', espera: null };
    const card = $('d-etapas-card');
    const anillo = document.createElement('div');
    anillo.className = 'et-ring';
    anillo.innerHTML = '<svg viewBox="0 0 28 28" aria-hidden="true"><circle class="bg" cx="14" cy="14" r="11"/><circle class="fg" cx="14" cy="14" r="11" pathLength="100"/></svg><span></span>';
    const anilloFg = anillo.querySelector('.fg');
    const anilloTxt = anillo.querySelector('span');

    const pausado = () => e.manual || e.encima || !e.visible || document.hidden || !e.datos;

    function delta(act, prev) {
        if (!prev) return act ? '<span class="chip">Nuevo</span>' : '';
        const d = ((act - prev) / Math.abs(prev)) * 100;
        return `<span class="chip ${d >= 0 ? 'up' : 'down'}"><i class="fa-solid fa-arrow-${d >= 0 ? 'up' : 'down'}"></i> ${Math.abs(d).toFixed(d > 99 ? 0 : 1).replace('.', ',')}%</span>`;
    }

    function pintarTabs() {
        $('d-et-tabs').innerHTML = e.datos.etapas.map((x, i) => `
            <button type="button" role="tab" class="et-tab" data-i="${i}" aria-selected="false" style="--c:${x.colores.fondo};--t:${x.colores.texto};--a:${x.colores.acento}">
                <span class="et-lbl">${esc(x.nombre)}</span>
                <span class="et-num">${nf.format(x.activos)}<small>${esc(x.etiquetaActivos.toLowerCase())}</small></span>
            </button>`).join('');
    }

    function pintarEtapa(animar = true) {
        const x = e.datos.etapas[e.idx];
        document.querySelectorAll('#d-et-tabs .et-tab').forEach((t, i) => {
            t.classList.toggle('on', i === e.idx);
            t.setAttribute('aria-selected', i === e.idx);
        });
        document.querySelector(`#d-et-tabs .et-tab[data-i="${e.idx}"]`)?.appendChild(anillo);

        const tieneValor = x.monto !== null;
        if (!tieneValor) e.metrica = 'cantidad';
        $('d-et-metrica').classList.toggle('hidden', !tieneValor);
        document.querySelectorAll('#d-et-metrica button').forEach((b) => b.classList.toggle('on', b.dataset.m === e.metrica));

        const s = x.serie;
        const dias = s.granularidad === 'dia' ? s.etiquetas.length : null;
        const pico = Math.max(...s.cantidades, 0);
        const iPico = s.cantidades.indexOf(pico);
        const tiles = [
            [x.etiquetaActivos, nf.format(x.activos), tieneValor ? `${dineroCorto(x.valorActivo)} en juego` : 'ahora mismo'],
            [`${x.etiquetaSerie} en el periodo`, nf.format(x.total), delta(x.total, x.totalPrevio) || 'sin comparación'],
            [dias ? 'Promedio por día' : 'Promedio por mes', (x.total / (dias || s.etiquetas.length || 1)).toFixed(1).replace('.', ','), `${s.etiquetas.length} ${dias ? 'días' : 'meses'}`],
            tieneValor ? ['Valor del periodo', dineroCorto(x.monto), delta(x.monto, x.montoPrevio) || 'sin comparación']
                : ['Mejor día', pico ? nf.format(pico) : '—', pico ? (dias ? fecha(s.etiquetas[iPico]) : mesCorto(s.etiquetas[iPico])) : 'sin movimiento'],
        ];
        $('d-et-stats').innerHTML = tiles.map(([l, v, sub]) => `<div class="et-tile"><small>${esc(l)}</small><b>${v}</b><span>${sub}</span></div>`).join('');
        card.style.setProperty('--ea', x.colores.acento);
        card.style.setProperty('--ef', x.colores.fondo);

        if (animar) {
            const cuerpo = $('d-et-cuerpo');
            cuerpo.classList.remove('et-cambia');
            void cuerpo.offsetWidth;
            cuerpo.classList.add('et-cambia');
        }
        dibujar(x);
        pintarSalud(x);
        cargarDocs(x);
    }

    function dibujar(x) {
        const canvas = $('c-etapa');
        const s = x.serie;
        const valor = e.metrica === 'valor';
        const txt = colorTema('--muted-foreground');
        const acento = x.colores.acento;
        e.chart?.destroy();
        e.chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: s.etiquetas.map((k) => (s.granularidad === 'mes' ? mesCorto(k) : fecha(k))),
                datasets: [
                    {
                        label: valor ? 'Valor' : x.etiquetaSerie, data: valor ? s.montos : s.cantidades, borderColor: acento, borderWidth: 2.6, tension: 0.38, fill: true,
                        pointRadius: s.etiquetas.length > 45 ? 0 : 3, pointHoverRadius: 6, pointBackgroundColor: colorTema('--card'), pointBorderColor: acento, pointBorderWidth: 2,
                        backgroundColor: (c) => {
                            const a = c.chart.chartArea;
                            if (!a) return 'transparent';
                            const g = c.chart.ctx.createLinearGradient(0, a.top, 0, a.bottom);
                            g.addColorStop(0, alfa(acento, 40));
                            g.addColorStop(1, alfa(acento, 0));
                            return g;
                        },
                    },
                    { label: 'Periodo anterior', data: valor ? s.previosMontos : s.previosCantidades, borderColor: alfa(txt, 70), borderDash: [6, 5], borderWidth: 1.8, tension: 0.38, pointRadius: 0, pointHoverRadius: 4, fill: false },
                ],
            },
            options: {
                maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, animation: { duration: 900, easing: 'easeOutQuart' },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: txt, maxRotation: 0, autoSkipPadding: 18 } },
                    y: { beginAtZero: true, grid: { color: alfa(txt, 18) }, border: { display: false }, ticks: { color: txt, maxTicksLimit: 6, precision: 0, callback: (v) => (valor ? dineroCorto(v) : nf.format(v)) } },
                },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, color: txt, boxWidth: 8 } },
                    tooltip: { callbacks: { label: (c) => ` ${c.dataset.label}: ${valor ? dineroCorto(c.raw) : nf.format(c.raw)}` } },
                },
            },
        });
    }


    // ---- salud (mora) y documentos de la etapa
    function pintarSalud(x) {
        const m = x.mora;
        const tot = Math.max(1, m.mora + m.riesgo + m.aldia);
        const seg = (k, texto) => (m[k] ? `<i style="width:${(m[k] / tot) * 100}%;background:${m.colores[k]}" title="${texto}: ${nf.format(m[k])}"></i>` : '');
        $('d-et-salud').innerHTML = `<div class="et-barra">${seg('mora', 'En mora')}${seg('riesgo', 'En riesgo')}${seg('aldia', 'Al día')}</div>
            <div class="et-leyenda">
                <span title="Mora desde ${m.umbral} días"><i style="background:${m.colores.mora}"></i>En mora <b>${nf.format(m.mora)}</b></span>
                <span><i style="background:${m.colores.riesgo}"></i>En riesgo <b>${nf.format(m.riesgo)}</b></span>
                <span><i style="background:${m.colores.aldia}"></i>Al día <b>${nf.format(m.aldia)}</b></span>
            </div>`;
    }

    function chipsDoc(i) {
        const c = i.clasif.map((k) => `<span class="et-chip" style="--k:${k.color}" title="${esc(k.tipo)}">${esc(k.valor)}</span>`).join('');
        return (i.estado ? `<span class="et-chip neutro">${esc(i.estado)}</span>` : '') + c;
    }

    function pintarDocs(x, d) {
        const colores = d.colores;
        $('d-et-docs').innerHTML = d.items.length ? d.items.map((i, k) => `
            <button type="button" class="et-doc" data-modulo="${i.modulo}" data-doc="${i.doc}" style="--n:${colores[i.nivel]};--k:${Math.min(k, 12)}" title="Abrir seguimiento">
                <span class="et-dias"><b>${nf.format(i.dias)}</b><small>días</small></span>
                <span class="et-doc-b"><b>${esc(i.ref)} · ${esc(i.empresa)}</b>${i.titulo ? `<small>${esc(i.titulo)}</small>` : ''}<span class="et-chips">${chipsDoc(i)}${i.gestor && e.alcance === 'todos' ? `<span class="et-gestor">${esc(i.gestor)}</span>` : ''}</span></span>
                ${i.valor ? `<span class="et-doc-v">${dineroCorto(i.valor)}</span>` : ''}
            </button>`).join('') + (d.total > d.items.length ? `<p class="et-mas">Mostrando ${d.items.length} de ${nf.format(d.total)} · usa el buscador para acotar</p>` : '')
            : '<div class="ag-vacio"><i class="fa-solid fa-circle-check"></i><b>Sin documentos</b><span>No hay documentos activos en este periodo.</span></div>';
    }

    async function cargarDocs(x) {
        const clave = `${x.clave}|${getPeriodo()}|${e.alcance}|${docs.q}`;
        const n = ++docs.seq;
        if (docs.cache.has(clave)) { pintarDocs(x, docs.cache.get(clave)); return; }
        $('d-et-docs').innerHTML = Array(4).fill('<div class="et-doc-sk"></div>').join('');
        try {
            const res = await fetch(`${urlDocumentos.replace('__E__', x.clave)}?${new URLSearchParams({ periodo: getPeriodo(), alcance: esAdmin ? e.alcance : 'mios', q: docs.q })}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error(`Error ${res.status}`);
            const d = await res.json();
            docs.cache.set(clave, d);
            if (n === docs.seq) pintarDocs(x, d);
        } catch (err) {
            if (n === docs.seq) $('d-et-docs').innerHTML = `<div class="ag-vacio"><i class="fa-solid fa-triangle-exclamation"></i><b>No se pudo cargar</b><span>${esc(err.message)}</span></div>`;
        }
    }

    // ---- KPIs del tablero comercial
    function pintarKpisTablero() {
        const d = e.datos;
        const [a, z] = d.ventana;
        $('d-tab-nota').textContent = `Documentos creados del ${fecha(a)} al ${fecha(z, { day: 'numeric', month: 'short', year: 'numeric' })} · ${d.alcance === 'mios' ? 'solo los tuyos' : 'todo el equipo'} · el tablero cuenta desde ${d.desdeAnio}`;
        // Las 5 tarjetas (Solicitudes activas, Cotizaciones, Órdenes de compra, Entregas en curso y Tasa de aprobación) están
        // comentadas a pedido: ya no se muestran. La nota de fechas de arriba se conserva. Para volver a mostrarlas, descomentar
        // este bloque y el contenedor #d-tab-kpis de dashboard.blade.php.
        /*
        const chip = (cls, ico, t) => `<span class="chip ${cls}"><i class="fa-solid ${ico}"></i> ${t}</span>`;
        const moraChip = (x) => (x.mora.mora ? chip('down', 'fa-clock', `${nf.format(x.mora.mora)} en mora`) : chip('up', 'fa-check', 'sin mora'));
        const [sol, cot, oc, ent] = d.etapas;
        const tasa = d.tasa.total ? (d.tasa.aprobadas / d.tasa.total) * 100 : 0;
        const defs = [
            { l: 'Solicitudes activas', ico: 'fa-inbox', c: sol.colores.fondo, v: sol.activos, f: (v) => nf.format(Math.round(v)), sub: moraChip(sol) },
            { l: 'Cotizaciones', ico: 'fa-file-invoice-dollar', c: cot.colores.fondo, v: cot.activos, f: (v) => nf.format(Math.round(v)), sub: chip('', 'fa-coins', dineroCorto(cot.valorActivo)) + moraChip(cot) },
            { l: 'Órdenes de compra', ico: 'fa-shopping-cart', c: oc.colores.fondo, v: oc.activos, f: (v) => nf.format(Math.round(v)), sub: chip('', 'fa-coins', dineroCorto(oc.valorActivo)) + moraChip(oc) },
            { l: 'Entregas en curso', ico: 'fa-truck', c: ent.colores.fondo, v: ent.activos, f: (v) => nf.format(Math.round(v)), sub: moraChip(ent) },
            { l: 'Tasa de aprobación', ico: 'fa-bullseye', c: d.colorTasa || '#7c3aed', v: tasa, f: (v) => `${v.toFixed(1).replace('.', ',')}%`, sub: chip('', 'fa-circle-check', `${nf.format(d.tasa.aprobadas)} de ${nf.format(d.tasa.total)} cotizaciones`) },
        ];
        const cont = $('d-tab-kpis');
        if (cont.children.length !== defs.length) {
            cont.innerHTML = defs.map((k, i) => `<div class="dash-card kpi" style="--c:${k.c};--i:${i}"><div class="kpi-ico"><i class="fa-solid ${k.ico}"></i></div><div class="kpi-lbl">${k.l}</div><div class="kpi-val" data-v="0">0</div><div class="kpi-sub"></div></div>`).join('');
        }
        defs.forEach((k, i) => {
            const el = cont.children[i];
            el.style.setProperty('--c', k.c);
            contar(el.querySelector('.kpi-val'), k.v, k.f);
            el.querySelector('.kpi-sub').innerHTML = k.sub;
        });
        */
    }

    function sel(i, reiniciar = true) {
        e.idx = i;
        if (reiniciar) e.restante = ROTACION_MS;
        pintarEtapa();
    }

    function actualizarAnillo() {
        const frac = Math.max(0, Math.min(1, e.restante / ROTACION_MS));
        anilloFg.style.strokeDashoffset = 100 * (1 - frac);
        const enPausa = pausado();
        anillo.classList.toggle('pausa', enPausa);
        anilloTxt.textContent = enPausa && e.datos ? 'II' : Math.ceil(e.restante / 1000);
        anillo.title = e.manual ? 'En pausa (manual)' : e.encima ? 'En pausa mientras el cursor está encima' : `Cambia en ${Math.ceil(e.restante / 1000)} s`;
    }

    let previo = performance.now();
    (function tick(ahora) {
        const dt = Math.min(ahora - previo, 250);
        previo = ahora;
        if (!pausado()) {
            e.restante -= dt;
            if (e.restante <= 0) sel((e.idx + 1) % e.datos.etapas.length);
        }
        actualizarAnillo();
        requestAnimationFrame(tick);
    }(previo));

    // ---- eventos
    card.addEventListener('mouseenter', () => { e.encima = true; });
    card.addEventListener('mouseleave', () => { e.encima = false; });
    card.addEventListener('focusin', () => { e.encima = true; });
    card.addEventListener('focusout', () => { e.encima = false; });
    new IntersectionObserver(([en]) => { e.visible = en.isIntersecting; }, { threshold: 0.15 }).observe(card);

    $('d-et-tabs').addEventListener('click', (ev) => {
        const t = ev.target.closest('.et-tab');
        if (t) sel(+t.dataset.i);
    });
    const pausa = $('d-et-pausa');
    const iconoPausa = () => {
        pausa.querySelector('i').className = `fa-solid fa-${e.manual ? 'play' : 'pause'}`;
        pausa.title = e.manual ? 'Reanudar la rotación automática' : 'Pausar la rotación automática';
    };
    pausa.addEventListener('click', () => { e.manual = !e.manual; iconoPausa(); });
    iconoPausa();
    $('d-et-q').addEventListener('input', (ev) => {
        clearTimeout(docs.espera);
        docs.espera = setTimeout(() => { docs.q = ev.target.value.trim(); if (e.datos) cargarDocs(e.datos.etapas[e.idx]); }, 300);
    });
    $('d-et-docs').addEventListener('click', (ev) => {
        const d = ev.target.closest('.et-doc');
        if (d) onAbrir?.(d.dataset.modulo, d.dataset.doc);
    });
    $('d-et-metrica').addEventListener('click', (ev) => {
        const b = ev.target.closest('button');
        if (!b) return;
        e.metrica = b.dataset.m;
        pintarEtapa(false);
    });
    $('d-et-alcance')?.addEventListener('click', (ev) => {
        const b = ev.target.closest('button');
        if (!b) return;
        e.alcance = b.dataset.a;
        document.querySelectorAll('#d-et-alcance button').forEach((x) => x.classList.toggle('on', x === b));
        cargar();
    });

    async function cargar() {
        e.ctrl?.abort();
        e.ctrl = new AbortController();
        card.classList.add('cargando');
        try {
            const res = await fetch(`${url}?${new URLSearchParams({ periodo: getPeriodo(), alcance: esAdmin ? e.alcance : 'mios' })}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: e.ctrl.signal });
            if (!res.ok) throw new Error(`Error ${res.status}`);
            e.datos = await res.json();
            e.idx = Math.min(e.idx, e.datos.etapas.length - 1);
            $('d-et-sub').textContent = `${e.datos.periodoLabel} · ${e.datos.alcance === 'mios' ? 'solo tus documentos' : 'todo el equipo'}`;
            docs.cache.clear();
            pintarKpisTablero();
            pintarTabs();
            pintarEtapa(false);
        } catch (err) {
            if (err.name === 'AbortError') return;
            console.error(err);
            $('d-et-sub').textContent = 'No se pudo cargar el flujo comercial';
        } finally {
            card.classList.remove('cargando');
        }
    }

    return {
        cargar,
        repintar: () => { if (e.datos) pintarEtapa(false); },
        /** «Ver acciones» de la línea de proceso: abre esa etapa en el Flujo comercial. */
        seleccionar: (i) => { if (e.datos) sel(i); },
        colores: () => e.datos?.etapas.map((x) => x.colores),
        /** Vista previa / guardado: aplica { 1..4: {fondo,texto,acento}, 5: {fondo} } sin volver a consultar el servidor. */
        aplicarColores: (mapa) => {
            if (!e.datos) return;
            e.datos.etapas.forEach((x, i) => { x.colores = { ...x.colores, ...(mapa[i + 1] ?? {}) }; });
            if (mapa[5]?.fondo) e.datos.colorTasa = mapa[5].fondo;
            pintarKpisTablero();
            pintarTabs();
            pintarEtapa(false);
        },
        datos: () => e.datos,
        alcance: () => (esAdmin ? e.alcance : 'mios'),
    };
}
