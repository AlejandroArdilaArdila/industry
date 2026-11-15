// "Flujo comercial": pestañas SOLICITUDES / COTIZACIÓN / ÓRDENES DE COMPRA / ENTREGA con los colores de las cards
// del tablero. Rotan solas con un temporizador circular; el cursor encima (o la pausa manual) lo detiene.
import { Chart } from 'chart.js';
import { esc, nf, dineroCorto, fecha, mesCorto, colorTema, alfa, contar } from './dashboard-util.js';

const ROTACION_MS = 8000;
const $ = (id) => document.getElementById(id);

export function iniciarEtapas({ url, urlDocumentos, getPeriodo, esAdmin, onAbrir }) {
    const e = {
        datos: null, idx: 0, restante: ROTACION_MS, manual: matchMedia('(prefers-reduced-motion: reduce)').matches,
        encima: false, visible: true, metrica: 'cantidad', alcance: 'todos', chart: null, ctrl: null, nivel: {},
    };
    const docs = { cache: new Map(), seq: 0, q: '', espera: null };
    const card = $('d-etapas-card');
    const anillo = document.createElement('div');
    anillo.className = 'et-ring';
    anillo.innerHTML = '<svg viewBox="0 0 28 28" aria-hidden="true"><circle class="bg" cx="14" cy="14" r="11"/><circle class="fg" cx="14" cy="14" r="11" pathLength="100"/></svg><span></span>';
    const anilloFg = anillo.querySelector('.fg');
    const anilloTxt = anillo.querySelector('span');

    const pausado = () => e.manual || e.encima || !e.visible || document.hidden || !e.datos || Object.values(e.nivel).some(Boolean);

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
                ${x.resumen ? `<span class="et-mini" tabindex="0" data-resumen="${i}">${x.resumen.map((r) => `<span><i style="background:${r.color}"></i>${nf.format(r.n)}</span>`).join('')}</span>` : ''}
            </button>`).join('');
    }

    // Tarjeta flotante con el resumen de solicitudes por grupo: cuántas hay y la más antigua y la más nueva de cada una.
    let pop = null;
    const cerrarPop = () => { pop?.remove(); pop = null; };
    function abrirPop(ancla, etapa) {
        cerrarPop();
        const resumen = etapa.resumen;
        const esSol = etapa.clave === 'solicitud';
        const dias = (d) => `${nf.format(d)} ${d === 1 ? 'día' : 'días'}`;
        const fila = (rot, x) => (x ? `<div class="et-pop-r"><span>${rot}</span><b>${esc(x.ref)}</b><em>${dias(x.dias)}</em></div>` : '');
        pop = document.createElement('div');
        pop.className = 'et-pop';
        pop.innerHTML = `<header><i class="fa-solid ${esSol ? 'fa-inbox' : 'fa-clock'}"></i><div><b>${esSol ? 'Solicitudes por grupo' : `${esc(etapa.nombre)} por mora`}</b><small>${esSol ? 'Como en el tablero · antigüedad desde la creación' : `En mora desde ${etapa.mora.umbral} días · antigüedad del documento`}</small></div></header>
            ${resumen.map((r) => `<section style="--k:${r.color}"><h5><i></i>${esc(r.titulo)}<span>${nf.format(r.n)}</span></h5>
                ${r.n ? fila('Más antigua', r.masAntigua) + fila('Más nueva', r.masNueva) : '<p>Sin documentos</p>'}</section>`).join('')}`;
        document.body.appendChild(pop);
        const a = ancla.getBoundingClientRect();
        const w = pop.offsetWidth;
        pop.style.left = `${Math.max(8, Math.min(a.left, innerWidth - w - 8))}px`;
        const abajo = a.bottom + 8;
        pop.style.top = `${abajo + pop.offsetHeight > innerHeight - 8 ? Math.max(8, a.top - pop.offsetHeight - 8) : abajo}px`;
    }
    $('d-et-tabs').addEventListener('mouseover', (ev) => {
        const m = ev.target.closest('[data-resumen]');
        if (m && e.datos) abrirPop(m, e.datos.etapas[Number(m.dataset.resumen)]);
    });
    $('d-et-tabs').addEventListener('mouseout', (ev) => { if (ev.target.closest('[data-resumen]') && !ev.relatedTarget?.closest?.('[data-resumen]')) cerrarPop(); });
    $('d-et-tabs').addEventListener('focusin', (ev) => { const m = ev.target.closest('[data-resumen]'); if (m && e.datos) abrirPop(m, e.datos.etapas[Number(m.dataset.resumen)]); });
    $('d-et-tabs').addEventListener('focusout', cerrarPop);
    addEventListener('scroll', cerrarPop, true);

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
        dibujarEstados(x);
        pintarSalud(x);
        cargarDocs(x);
    }

    // Antigüedad de los documentos activos (columnas por rango de días, de verde a rojo) y, debajo, el reparto por estado.
    const ESCALA = ['#16a34a', '#84cc16', '#f59e0b', '#f97316', '#ef4444', '#b91c1c'];
    const PALETA = ['#2563eb', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899', '#84cc16', '#64748b'];
    function dibujarEstados(x) {
        const rangos = x.antiguedad ?? [];
        const total = rangos.reduce((a, r) => a + r.n, 0);
        const txt = colorTema('--muted-foreground');
        $('d-et-est-n').textContent = total ? `${nf.format(total)} activos` : '';
        e.chartEst?.destroy();
        e.chartEst = new Chart($('c-estados'), {
            type: 'bar',
            data: { labels: rangos.map((r) => r.etq), datasets: [{ data: rangos.map((r) => r.n), backgroundColor: ESCALA, borderRadius: 8, borderSkipped: false, barPercentage: 0.78, categoryPercentage: 0.9 }] },
            options: {
                indexAxis: 'y', maintainAspectRatio: false, animation: { duration: 800, easing: 'easeOutQuart' }, layout: { padding: { right: 42 } },
                scales: {
                    x: { beginAtZero: true, grid: { color: alfa(txt, 14) }, border: { display: false }, ticks: { color: txt, maxTicksLimit: 5, precision: 0 } },
                    y: { grid: { display: false }, border: { display: false }, ticks: { color: txt, font: { weight: 600 } } },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { title: (c) => `${c[0].label} de antigüedad`, label: (c) => ` ${nf.format(c.raw)} documentos (${total ? Math.round((c.raw / total) * 100) : 0}%)` } },
                },
            },
            plugins: [{
                id: 'valores',
                afterDatasetsDraw(ch) {
                    const { ctx } = ch;
                    ctx.save();
                    ctx.fillStyle = colorTema('--foreground');
                    ctx.font = '700 11px sans-serif';
                    ctx.textBaseline = 'middle';
                    ch.getDatasetMeta(0).data.forEach((b, i) => {
                        if (rangos[i].n) ctx.fillText(`${nf.format(rangos[i].n)} · ${total ? Math.round((rangos[i].n / total) * 100) : 0}%`, b.x + 6, b.y);
                    });
                    ctx.restore();
                },
            }],
        });

        // Reparto por estado en una sola barra apilada con chips.
        const lista = x.estados ?? [];
        const sum = lista.reduce((a, r) => a + r.n, 0);
        $('d-et-est-b').innerHTML = sum ? `<div class="et-ap-barra">${lista.map((r, i) => `<i style="width:${(r.n / sum) * 100}%;background:${PALETA[i % PALETA.length]}" title="${esc(r.estado)}: ${nf.format(r.n)}"></i>`).join('')}</div>
            <div class="et-ap-leyenda">${lista.slice(0, 5).map((r, i) => `<span title="${esc(r.estado)}"><i style="background:${PALETA[i % PALETA.length]}"></i>${esc(r.estado)} <b>${nf.format(r.n)}</b></span>`).join('')}${lista.length > 5 ? `<span>+${lista.length - 5} más</span>` : ''}</div>` : '';
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
        const sel = e.nivel[x.clave] || '';
        const tot = Math.max(1, m.mora + m.riesgo + m.aldia);
        const seg = (k, texto) => (m[k] ? `<i data-nivel="${k}" class="${sel && sel !== k ? 'tenue' : ''}" style="width:${(m[k] / tot) * 100}%;background:${m.colores[k]}" title="${texto}: ${nf.format(m[k])} · clic para filtrar"></i>` : '');
        const item = (k, texto, tip = '') => `<button type="button" data-nivel="${k}" class="et-niv ${sel === k ? 'on' : ''} ${sel && sel !== k ? 'tenue' : ''}" aria-pressed="${sel === k}" ${tip ? `title="${tip}"` : 'title="Clic para filtrar la lista"'} style="--k:${m.colores[k]}"><i></i>${texto} <b>${nf.format(m[k])}</b></button>`;
        $('d-et-salud').innerHTML = `<div class="et-barra">${seg('mora', 'En mora')}${seg('riesgo', 'En riesgo')}${seg('aldia', 'Al día')}</div>
            <div class="et-leyenda">
                ${item('mora', 'En mora', `Mora desde ${m.umbral} días · clic para filtrar`)}${item('riesgo', 'En riesgo')}${item('aldia', 'Al día')}
                ${sel ? '<button type="button" data-nivel="" class="et-quitar" title="Quitar el filtro"><i class="fa-solid fa-xmark"></i> Quitar filtro</button>' : ''}
            </div>`;
    }

    function chipsDoc(i) {
        const c = i.clasif.map((k) => `<span class="et-chip" style="--k:${k.color}" title="${esc(k.tipo)}">${esc(k.valor)}</span>`).join('');
        return (i.estado ? `<span class="et-chip neutro">${esc(i.estado)}</span>` : '') + c;
    }

    const NIVEL = { mora: 'En mora', riesgo: 'En riesgo', aldia: 'Al día' };
    const corta = (iso) => (iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('es-CO', { day: 'numeric', month: 'short' }) : '');
    const hoyIso = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

    // Misma tarjeta del tablero de solicitudes: borde por mora, bloques Estado / Próx. contacto, caja de mora y pie.
    function pintarDocs(x, d) {
        const colores = d.colores;
        const hoy = hoyIso();
        $('d-et-docs').innerHTML = d.items.length ? d.items.map((i, k) => {
            const c = colores[i.nivel];
            const vencido = i.proximo && i.proximo <= hoy;
            const tag2 = i.modulo === 'solicitud'
                ? `<span class="et-tg"><span>Próx. contacto</span><b class="${vencido ? 'crit' : ''}"><i style="background:${vencido ? '#dc2626' : 'var(--muted-foreground)'}"></i>${i.proximo ? (vencido ? `Contactar · ${corta(i.proximo)}` : corta(i.proximo)) : 'Sin programar'}</b></span>`
                : `<span class="et-tg"><span>${i.valor ? 'Valor' : 'Responsable'}</span><b><i style="background:var(--muted-foreground)"></i>${i.valor ? dineroCorto(i.valor) : esc(i.gestor || '—')}</b></span>`;
            return `<button type="button" class="et-doc" data-modulo="${i.modulo}" data-doc="${i.doc}" style="--n:${c};--k:${Math.min(k, 12)}" title="Abrir seguimiento">
                <span class="et-h"><b>${esc(i.ref)}</b><span class="et-bd">${NIVEL[i.nivel]}</span></span>
                <span class="et-cli">${esc(i.empresa)}</span>
                ${i.titulo ? `<span class="et-ds">${esc(i.titulo)}</span>` : ''}
                <span class="et-gr">
                    <span class="et-tg"><span>Estado</span><b><i style="background:var(--primary)"></i>${esc(i.estado || '—')}</b></span>
                    ${tag2}
                </span>
                <span class="et-mo"><span>MORA TOTAL <small>desde ${i.desde ? corta(i.desde) : 'ingreso'}</small></span><b>${nf.format(i.dias)} <small>días</small></b></span>
                <span class="et-pie"><span class="et-gestor">${i.gestor ? `&#128100; ${esc(i.gestor)}` : ''}</span>${i.clasif.length ? `<span class="et-chips">${i.clasif.map((q) => `<span class="et-chip" style="--k:${q.color}" title="${esc(q.tipo)}">${esc(q.valor)}</span>`).join('')}</span>` : ''}${i.valor && i.modulo !== 'solicitud' ? `<span class="et-doc-v">${dineroCorto(i.valor)}</span>` : ''}</span>
            </button>`;
        }).join('') + (d.total > d.items.length ? `<p class="et-mas">Mostrando ${d.items.length} de ${nf.format(d.total)} · usa el buscador para acotar</p>` : '')
            : '<div class="ag-vacio"><i class="fa-solid fa-circle-check"></i><b>Sin documentos</b><span>No hay documentos activos en este periodo.</span></div>';
    }

    async function cargarDocs(x) {
        const nivel = e.nivel[x.clave] || '';
        const clave = `${x.clave}|${getPeriodo()}|${e.alcance}|${docs.q}|${nivel}`;
        const n = ++docs.seq;
        if (docs.cache.has(clave)) { pintarDocs(x, docs.cache.get(clave)); return; }
        $('d-et-docs').innerHTML = Array(4).fill('<div class="et-doc-sk"></div>').join('');
        try {
            const res = await fetch(`${urlDocumentos.replace('__E__', x.clave)}?${new URLSearchParams({ periodo: getPeriodo(), alcance: esAdmin ? e.alcance : 'mios', q: docs.q, nivel })}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error(`Error ${res.status}`);
            const d = await res.json();
            docs.cache.set(clave, d);
            if (n === docs.seq) pintarDocs(x, d);
        } catch (err) {
            if (n === docs.seq) $('d-et-docs').innerHTML = `<div class="ag-vacio"><i class="fa-solid fa-triangle-exclamation"></i><b>No se pudo cargar</b><span>${esc(err.message)}</span></div>`;
        }
    }

    // Clic en la barra o en la leyenda de mora: filtra la lista por ese nivel (otro clic en el mismo lo quita).
    $('d-et-salud').addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-nivel]');
        if (!b || !e.datos) return;
        const x = e.datos.etapas[e.idx];
        const k = b.dataset.nivel;
        e.nivel[x.clave] = k && e.nivel[x.clave] !== k ? k : '';
        pintarSalud(x);
        cargarDocs(x);
    });

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
