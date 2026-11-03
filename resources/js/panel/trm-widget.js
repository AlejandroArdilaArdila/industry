// Widget flotante de TRM (COP por 1 unidad de cada moneda). Arrastrable, ampliable, con
// historial para todos y configuración de monedas para administradores. Datos: TrmController.
import '../../css/trm-widget.css';

const raiz = document.getElementById('trm-widget');
if (raiz) iniciar(raiz);

function iniciar(raiz) {
    const cfg = {
        usuario: raiz.dataset.user, url: raiz.dataset.url, historial: raiz.dataset.urlHistorial,
        config: raiz.dataset.urlConfig, cargar: raiz.dataset.urlCargar, csrf: raiz.dataset.csrf,
    };
    const CLAVE = `panel-trm:${cfg.usuario}`;
    const guardado = (() => { try { return JSON.parse(localStorage.getItem(CLAVE) || '{}'); } catch { return {}; } })();
    const estado = {
        vista: 'tasas', expandido: !!guardado.expandido, mini: !!guardado.mini, datos: null, error: '', cargando: false,
        x: guardado.x, y: guardado.y, borrador: null, borradorColores: null, mensaje: '', hist: null, graf: null,
    };
    const persistir = () => { try { localStorage.setItem(CLAVE, JSON.stringify({ x: estado.x, y: estado.y, expandido: estado.expandido, mini: estado.mini })); } catch { /* sin almacenamiento */ } };

    // ---------------------------------------------------------------- utilidades
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const nombres = new Intl.DisplayNames(['es'], { type: 'currency' });
    const nombre = (c) => { try { const n = nombres.of(c) || c; return n.charAt(0).toUpperCase() + n.slice(1); } catch { return c; } };
    const dinero = (v) => (v == null ? '—' : `$ ${v.toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: v < 100 ? 4 : 2 })}`);
    const fechaCorta = (f) => (f ? new Date(`${f}T00:00:00`).toLocaleDateString('es-CO', { day: 'numeric', month: 'short' }) : '');
    const fechaLarga = (f) => new Date(`${f}T00:00:00`).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' });
    const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const FUENTE = { superfinanciera: 'TRM oficial', bce: 'BCE × TRM', 'er-api': 'Ref. × TRM' };
    const COLOR = { USD: '#16a34a', EUR: '#2563eb', GBP: '#7c3aed', MXN: '#059669', BRL: '#ca8a04', CAD: '#dc2626', JPY: '#e11d48', CHF: '#ef4444', CNY: '#dc2626', AUD: '#0891b2', PEN: '#be123c', ARS: '#0ea5e9', CLP: '#1d4ed8' };
    const hslHex = (h, s, l) => {
        s /= 100; l /= 100;
        const k = (n) => (n + h / 30) % 12, a = s * Math.min(l, 1 - l);
        const f = (n) => Math.round(255 * (l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1))))).toString(16).padStart(2, '0');
        return `#${f(0)}${f(8)}${f(4)}`;
    };
    // Color de fábrica de una moneda (el administrador puede reemplazarlo en la configuración).
    const colorBase = (c) => COLOR[c] || hslHex([...c].reduce((a, ch) => a + ch.charCodeAt(0) * 37, 0) % 360, 62, 46);
    const colorDe = (c) => estado.borradorColores?.monedas?.[c] || estado.datos?.colores?.monedas?.[c] || colorBase(c);
    const textoSobre = (hex) => { const n = parseInt(hex.slice(1), 16); return ((n >> 16) * 299 + ((n >> 8) & 255) * 587 + (n & 255) * 114) / 1000 > 150 ? '#111827' : '#ffffff'; };
    // Colores del tema del componente (acento, borde, fondo y fuente): reemplazan los del panel solo dentro del widget.
    function aplicarTema(c = {}) {
        const poner = (nombre, valor) => (valor ? el.style.setProperty(nombre, valor) : el.style.removeProperty(nombre));
        poner('--primary', c.general);
        poner('--primary-foreground', c.general ? textoSobre(c.general) : null);
        poner('--trm-borde', c.borde);
        poner('--border', c.borde);
        poner('--input', c.borde);
        poner('--card', c.fondo);
        poner('--background', c.fondo ? 'color-mix(in oklab, var(--card) 93%, var(--foreground))' : null);
        poner('--muted', c.fondo ? 'color-mix(in oklab, var(--card) 86%, var(--foreground))' : null);
        poner('--foreground', c.fuente);
        poner('--muted-foreground', c.fuente ? `color-mix(in oklab, ${c.fuente} 62%, transparent)` : null);
    }
    const TEMA = [
        ['general', 'Acento (botones, línea, gráfica)', '--primary'],
        ['borde', 'Borde', '--border'],
        ['fondo', 'Fondo', '--card'],
        ['fuente', 'Fuente (texto)', '--foreground'],
    ];
    const lienzo = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
    function aHex(css) {
        lienzo.clearRect(0, 0, 1, 1);
        lienzo.fillStyle = '#000';
        lienzo.fillStyle = css;
        lienzo.fillRect(0, 0, 1, 1);
        const [r, g, b] = lienzo.getImageData(0, 0, 1, 1).data;
        return `#${[r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')}`;
    }

    const sonda = document.createElement('span');
    sonda.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none';
    document.body.appendChild(sonda);
    const colorTema = (v) => { sonda.style.color = `var(${v})`; return getComputedStyle(sonda).color; };

    async function api(url, opts = {}) {
        const res = await fetch(url, {
            ...opts,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(opts.body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf } : {}) },
            credentials: 'same-origin',
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json.message || (res.status === 401 ? 'La sesión expiró.' : `Error ${res.status}`));
        return json;
    }

    // ---------------------------------------------------------------- DOM
    const el = document.createElement('div');
    el.className = 'trm';
    el.setAttribute('role', 'complementary');
    el.setAttribute('aria-label', 'Tasas de cambio (TRM)');
    document.body.appendChild(el);

    function delta(item) {
        if (item.valor == null || !item.anterior) return '<span class="trm-d igual">—</span>';
        const p = ((item.valor - item.anterior) / item.anterior) * 100;
        if (Math.abs(p) < 0.005) return '<span class="trm-d igual">0,00%</span>';
        return `<span class="trm-d ${p > 0 ? 'up' : 'down'}"><i class="fa-solid fa-caret-${p > 0 ? 'up' : 'down'}"></i>${Math.abs(p).toFixed(2).replace('.', ',')}%</span>`;
    }

    function spark(serie) {
        const s = (serie || []).slice(-20);
        if (s.length < 2) return '';
        const w = 100, h = 20, max = Math.max(...s), min = Math.min(...s);
        const d = s.map((v, i) => `${i ? 'L' : 'M'}${((i / (s.length - 1)) * w).toFixed(1)} ${(h - 2 - (max === min ? 0.5 : (v - min) / (max - min)) * (h - 4)).toFixed(1)}`).join(' ');
        return `<svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none"><path class="a" d="${d} L${w} ${h} L0 ${h}Z"/><path class="l" d="${d}"/></svg>`;
    }

    function fila(item, principal, k) {
        return `<div class="trm-row" data-c="${item.codigo}" style="--c:${colorDe(item.codigo)};--k:${k}">
            <div class="trm-cur">${esc(item.codigo)}</div>
            <div class="trm-nom"><b>${esc(nombre(item.codigo))}</b><small>${item.valor == null ? 'Sin datos aún' : `${esc(FUENTE[item.fuente] || '')} · ${fechaCorta(item.fecha)}`}</small></div>
            <div class="trm-val ${principal ? 'principal' : ''}"><b>${dinero(item.valor)}</b>${delta(item)}</div>
            ${principal ? `<div class="trm-spark">${spark(item.serie)}</div>` : ''}
        </div>`;
    }

    const boton = (a, icono, titulo, extra = '') => `<button type="button" class="trm-ib ${extra}" data-a="${a}" title="${titulo}" aria-label="${titulo}"><i class="fa-solid ${icono}"></i></button>`;

    function cabecera() {
        const d = estado.datos;
        const usd = d?.items.find((i) => i.codigo === 'USD') ?? d?.items[0];
        const extras = d ? Math.max(0, d.items.length - 2) : 0;
        const titulos = { tasas: 'TRM · Peso colombiano', config: 'Configurar monedas', historial: 'Historial de TRM' };
        return `<div class="trm-head">
            <i class="fa-solid fa-grip-vertical trm-grip"></i>
            <div class="trm-title"><b>${titulos[estado.vista]}</b><small>${estado.vista === 'tasas' ? (usd?.fecha ? `Vigente al ${fechaLarga(usd.fecha)}` : 'Cargando…') : 'COP por unidad de moneda'}</small></div>
            <div class="trm-pill" title="${esc(usd?.codigo || 'USD')} · clic para abrir"><b>${dinero(usd?.valor)}</b></div>
            ${estado.vista === 'tasas' ? `
                ${boton('historial', 'fa-clock-rotate-left', 'Ver historial')}
                ${boton('refrescar', 'fa-rotate', 'Actualizar', estado.cargando ? 'cargando' : '')}
                ${d?.puedeConfigurar ? boton('config', 'fa-gear', 'Configurar monedas') : ''}
                ${extras ? `<button type="button" class="trm-ib ${estado.expandido ? 'on' : ''}" data-a="ampliar" title="${estado.expandido ? 'Mostrar menos' : `Ver ${extras} moneda${extras === 1 ? '' : 's'} más`}" aria-label="Ampliar" aria-expanded="${estado.expandido}"><i class="fa-solid fa-angles-down"></i><span class="n">${extras}</span></button>` : ''}
            ` : boton('volver', 'fa-arrow-left', 'Volver')}
            ${boton('mini', 'fa-minus', 'Minimizar')}
        </div>`;
    }

    function vistaTasas() {
        const d = estado.datos;
        if (!d) return `<div class="trm-body">${estado.error ? `<div class="trm-err">${esc(estado.error)}</div>` : '<div class="trm-vacio"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando tasas…</div>'}</div>`;
        const principales = d.items.slice(0, 2), extra = d.items.slice(2);
        const hora = d.generado ? new Date(d.generado).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' }) : '';
        return `<div class="trm-body">
            ${estado.error ? `<div class="trm-err">${esc(estado.error)}</div>` : ''}
            ${principales.map((i, k) => fila(i, true, k)).join('')}
            ${extra.length ? `<div class="trm-extra"><div>${extra.map((i, k) => fila(i, false, k)).join('')}</div></div>` : ''}
        </div>
        <div class="trm-foot"><span>Actualizado ${esc(hora)}</span><button type="button" data-a="historial">Ver historial <i class="fa-solid fa-arrow-right"></i></button></div>`;
    }

    // ---- configuración (admin)
    function vistaConfig() {
        const c = estado.borrador;
        const disponibles = (estado.datos.config.catalogo || []).filter((m) => !c.includes(m.codigo));
        const sinBce = new Set((estado.datos.config.catalogo || []).filter((m) => m.fuente !== 'bce').map((m) => m.codigo));
        return `<div class="trm-vista">
            <h4>Tema del componente</h4>
            <div class="trm-tema">${TEMA.map(([k, texto, variable]) => `
                <div class="trm-item" style="grid-template-columns:minmax(0,1fr) 26px auto">
                    <span class="t">${texto}${estado.borradorColores[k] ? '' : ' <small style="color:var(--muted-foreground)">· del tema</small>'}</span>
                    <input type="color" class="trm-color" data-color="${k}" value="${estado.borradorColores[k] || aHex(colorTema(variable))}" title="${texto}">
                    <button type="button" class="trm-btn" data-a="color-reset" data-k="${k}" ${estado.borradorColores[k] ? '' : 'disabled'} style="height:24px;padding:0 8px;font-size:10.5px" title="Volver al color del tema del panel"><i class="fa-solid fa-rotate-left"></i></button>
                </div>`).join('')}</div>
            <h4>Colores por moneda</h4>
            <p class="trm-nota" style="margin-top:0">Se aplican al cuadrito de cada moneda en la lista de abajo.</p>
            <h4>Monedas mostradas (${c.length}/${estado.datos.config.maximo})</h4>
            <div class="trm-lista">${c.map((m, i) => `
                <div class="trm-item ${i < 2 ? 'fija' : ''}" style="animation-delay:${i * 30}ms">
                    <span class="pos" title="${i < 2 ? 'Siempre visible' : 'Se ve al ampliar'}">${i < 2 ? '<i class="fa-solid fa-thumbtack"></i>' : i + 1}</span>
                    <span class="t"><b>${esc(m)}</b> · ${esc(nombre(m))}${sinBce.has(m) ? ' *' : ''}</span>
                    <input type="color" class="trm-color" data-color="${m}" value="${colorDe(m)}" title="Color de ${esc(m)}">
                    <span class="acc">
                        <button type="button" data-a="subir" data-i="${i}" ${i === 0 ? 'disabled' : ''} title="Subir"><i class="fa-solid fa-chevron-up"></i></button>
                        <button type="button" data-a="bajar" data-i="${i}" ${i === c.length - 1 ? 'disabled' : ''} title="Bajar"><i class="fa-solid fa-chevron-down"></i></button>
                        <button type="button" class="del" data-a="quitar" data-i="${i}" ${c.length <= 2 ? 'disabled' : ''} title="Quitar"><i class="fa-solid fa-xmark"></i></button>
                    </span>
                </div>`).join('')}</div>
            <div class="trm-add">
                <select class="trm-input" id="trm-nueva" ${c.length >= estado.datos.config.maximo ? 'disabled' : ''}>
                    <option value="">Agregar moneda…</option>
                    ${disponibles.map((m) => `<option value="${m.codigo}">${m.codigo} · ${esc(nombre(m.codigo))}${m.fuente !== 'bce' ? ' *' : ''}</option>`).join('')}
                </select>
                <button type="button" class="trm-btn" data-a="agregar">Agregar</button>
            </div>
            <p class="trm-nota">Las dos primeras se muestran siempre; las demás, al ampliar el componente. USD usa la TRM oficial de la Superfinanciera; las otras son el cruce con el BCE (<b>*</b> sin histórico previo: se guarda desde el día en que se agregan).</p>
            ${estado.mensaje ? `<div class="${estado.mensaje.startsWith('!') ? 'trm-err' : 'trm-nota'}" style="margin:0 0 6px">${esc(estado.mensaje.replace(/^!/, ''))}</div>` : ''}
            <div class="trm-acciones">
                <button type="button" class="trm-btn" data-a="cargar-ahora" title="Consulta las fuentes y guarda la TRM de hoy"><i class="fa-solid fa-cloud-arrow-down"></i> Cargar ahora</button>
                <button type="button" class="trm-btn" data-a="volver">Cancelar</button>
                <button type="button" class="trm-btn pri" data-a="guardar" ${estado.cargando ? 'disabled' : ''}>Guardar</button>
            </div>
        </div>`;
    }

    // ---- historial
    function vistaHistorial() {
        const h = estado.hist, d = estado.datos;
        const pills = [['7', '7d'], ['30', '30d'], ['90', '90d'], ['365', '1 año'], ['1825', '5 años']];
        const st = h.datos?.stats;
        const filas = h.datos?.filas ?? [];
        const desc = [...filas].reverse();
        return `<div class="trm-vista">
            <select class="trm-input" id="trm-h-moneda">${d.items.map((i) => `<option value="${i.codigo}" ${i.codigo === h.moneda ? 'selected' : ''}>${i.codigo} · ${esc(nombre(i.codigo))}</option>`).join('')}</select>
            <div class="trm-pills">${pills.map(([v, t]) => `<button type="button" data-a="rango" data-r="${v}" class="${h.rango === v ? 'on' : ''}">${t}</button>`).join('')}</div>
            <div class="trm-fechas"><input type="date" class="trm-input" id="trm-h-desde" value="${h.desde}" max="${h.hasta}"><input type="date" class="trm-input" id="trm-h-hasta" value="${h.hasta}" max="${iso(new Date())}"></div>
            ${h.error ? `<div class="trm-err" style="margin:8px 0 0">${esc(h.error)}</div>` : ''}
            <div class="trm-graf">${h.cargando ? '<div class="trm-vacio"><i class="fa-solid fa-circle-notch fa-spin"></i> Consultando…</div>' : filas.length ? '<canvas id="trm-chart"></canvas>' : '<div class="trm-vacio">Sin datos en el rango.</div>'}</div>
            ${st ? `<div class="trm-stats">
                <div><small>Último</small><b>${dinero(st.ultimo)}</b></div><div><small>Mínimo</small><b>${dinero(st.minimo)}</b></div><div><small>Máximo</small><b>${dinero(st.maximo)}</b></div>
                <div><small>Promedio</small><b>${dinero(st.promedio)}</b></div>
                <div><small>Variación</small><b style="color:${st.variacion >= 0 ? '#10b981' : '#f43f5e'}">${st.variacion == null ? '—' : `${st.variacion >= 0 ? '+' : ''}${st.variacion.toFixed(2).replace('.', ',')}%`}</b></div>
                <div><small>Registros</small><b>${st.dias}</b></div></div>` : ''}
            ${filas.length ? `<div class="trm-tabla"><table><thead><tr><th>Fecha</th><th>COP</th><th>Var.</th></tr></thead><tbody>${desc.slice(0, 400).map((f, i) => {
                const ant = desc[i + 1]?.valor;
                const p = ant ? ((f.valor - ant) / ant) * 100 : null;
                return `<tr><td>${fechaLarga(f.fecha)}</td><td>${dinero(f.valor)}</td><td style="color:${p == null ? 'inherit' : p >= 0 ? '#10b981' : '#f43f5e'}">${p == null ? '—' : `${p >= 0 ? '+' : ''}${p.toFixed(2).replace('.', ',')}%`}</td></tr>`;
            }).join('')}</tbody></table></div>` : ''}
            <div class="trm-acciones"><button type="button" class="trm-btn" data-a="csv" ${filas.length ? '' : 'disabled'}><i class="fa-solid fa-file-csv"></i> Descargar CSV</button></div>
        </div>`;
    }

    // ---------------------------------------------------------------- render
    function render() {
        aplicarTema(estado.borradorColores || estado.datos?.colores);
        el.classList.toggle('mini', estado.mini);
        el.classList.toggle('expandido', estado.expandido && !estado.mini);
        el.classList.toggle('ancho', estado.vista !== 'tasas' && !estado.mini);
        el.innerHTML = cabecera() + (estado.vista === 'config' && estado.datos ? vistaConfig() : estado.vista === 'historial' && estado.hist ? vistaHistorial() : vistaTasas());
        if (estado.vista === 'historial') dibujarGrafica();
        requestAnimationFrame(acomodar);
    }

    /** Redibuja y marca con un destello las monedas cuyo valor cambió respecto de la carga anterior. */
    function actualizarFilas(previo) {
        render();
        previo?.forEach((p) => {
            const nuevo = estado.datos?.items.find((i) => i.codigo === p.codigo);
            if (nuevo?.valor != null && p.valor != null && nuevo.valor !== p.valor) {
                el.querySelector(`.trm-row[data-c="${p.codigo}"]`)?.classList.add(nuevo.valor > p.valor ? 'flash-up' : 'flash-down');
            }
        });
    }

    async function dibujarGrafica() {
        const h = estado.hist;
        const canvas = document.getElementById('trm-chart');
        estado.graf?.destroy();
        estado.graf = null;
        if (!canvas || !h?.datos?.filas.length) return;
        const { Chart, registerables } = await import('chart.js');
        Chart.register(...registerables);
        if (document.getElementById('trm-chart') !== canvas) return;
        const col = estado.datos?.colores || {};
        const prim = col.general || colorTema('--primary'), txt = col.fuente || colorTema('--muted-foreground');
        const filas = h.datos.filas;
        estado.graf = new Chart(canvas, {
            type: 'line',
            data: {
                labels: filas.map((f) => fechaCorta(f.fecha)),
                datasets: [{
                    data: filas.map((f) => f.valor), borderColor: prim, borderWidth: 2, tension: 0.3, fill: true, pointRadius: filas.length > 60 ? 0 : 2.5, pointHoverRadius: 5,
                    backgroundColor: (c) => {
                        const a = c.chart.chartArea;
                        if (!a) return 'transparent';
                        const g = c.chart.ctx.createLinearGradient(0, a.top, 0, a.bottom);
                        g.addColorStop(0, `color-mix(in oklab, ${prim} 35%, transparent)`);
                        g.addColorStop(1, `color-mix(in oklab, ${prim} 0%, transparent)`);
                        return g;
                    },
                }],
            },
            options: {
                maintainAspectRatio: false, animation: { duration: 900, easing: 'easeOutQuart' }, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false }, tooltip: { callbacks: { title: (x) => fechaLarga(filas[x[0].dataIndex].fecha), label: (x) => ` ${dinero(x.raw)}` } } },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: txt, maxTicksLimit: 5, maxRotation: 0, font: { size: 10 } } },
                    y: { grid: { color: `color-mix(in oklab, ${txt} 16%, transparent)` }, border: { display: false }, ticks: { color: txt, maxTicksLimit: 4, font: { size: 10 }, callback: (v) => v.toLocaleString('es-CO', { maximumFractionDigits: 0 }) } },
                },
            },
        });
    }

    // ---------------------------------------------------------------- posición y arrastre
    function acomodar() {
        const w = el.offsetWidth, h = el.offsetHeight, m = 8;
        if (estado.x == null || estado.y == null) { estado.x = innerWidth - w - 20; estado.y = innerHeight - h - 20; }
        estado.x = Math.min(Math.max(m, estado.x), Math.max(m, innerWidth - w - m));
        estado.y = Math.min(Math.max(m, estado.y), Math.max(m, innerHeight - h - m));
        el.style.left = `${estado.x}px`;
        el.style.top = `${estado.y}px`;
        el.classList.add('listo');
    }
    addEventListener('resize', acomodar);

    let arrastre = null;
    el.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || !e.target.closest('.trm-head') || e.target.closest('button')) return;
        arrastre = { dx: e.clientX - estado.x, dy: e.clientY - estado.y, x0: e.clientX, y0: e.clientY, movido: false };
        el.setPointerCapture(e.pointerId);
    });
    el.addEventListener('pointermove', (e) => {
        if (!arrastre) return;
        if (!arrastre.movido && Math.hypot(e.clientX - arrastre.x0, e.clientY - arrastre.y0) < 4) return;
        arrastre.movido = true;
        el.classList.add('arrastrando');
        estado.x = e.clientX - arrastre.dx;
        estado.y = e.clientY - arrastre.dy;
        acomodar();
    });
    const soltar = () => {
        if (!arrastre) return;
        const { movido } = arrastre;
        arrastre = null;
        el.classList.remove('arrastrando');
        if (movido) persistir();
        else if (estado.mini) { estado.mini = false; persistir(); render(); }
    };
    el.addEventListener('pointerup', soltar);
    el.addEventListener('pointercancel', soltar);

    // ---------------------------------------------------------------- acciones
    async function cargarTasas(manual = false) {
        const previo = estado.datos?.items;
        estado.cargando = true;
        if (manual && estado.vista === 'tasas') render();
        try {
            estado.datos = await api(cfg.url);
            estado.error = '';
        } catch (e) {
            estado.error = e.message;
        } finally {
            estado.cargando = false;
            estado.vista === 'tasas' ? actualizarFilas(previo) : render();
        }
    }

    async function cargarHistorial() {
        const h = estado.hist;
        h.cargando = true; h.error = '';
        render();
        try {
            h.datos = await api(`${cfg.historial}?${new URLSearchParams({ moneda: h.moneda, desde: h.desde, hasta: h.hasta })}`);
        } catch (e) {
            h.error = e.message; h.datos = null;
        } finally {
            h.cargando = false;
            if (estado.vista === 'historial') render();
        }
    }

    function abrirHistorial(moneda) {
        const hasta = iso(new Date());
        estado.hist = { moneda: moneda || estado.datos?.items[0]?.codigo || 'USD', rango: '90', desde: iso(new Date(Date.now() - 89 * 864e5)), hasta, datos: null, cargando: true, error: '' };
        estado.vista = 'historial';
        render();
        cargarHistorial();
    }

    function descargarCsv() {
        const h = estado.hist;
        const filas = h.datos?.filas ?? [];
        const csv = `﻿fecha;moneda;cop_por_unidad;fuente\n${filas.map((f) => `${f.fecha};${h.moneda};${String(f.valor).replace('.', ',')};${f.fuente}`).join('\n')}\n`;
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        a.download = `trm-${h.moneda}-${h.desde}_${h.hasta}.csv`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    }

    async function guardarConfig() {
        estado.cargando = true; estado.mensaje = '';
        render();
        try {
            const r = await api(cfg.config, { method: 'PUT', body: JSON.stringify({ monedas: estado.borrador, colores: estado.borradorColores }) });
            estado.datos = r; estado.vista = 'tasas'; estado.error = ''; estado.borradorColores = null;
        } catch (e) {
            estado.mensaje = `!${e.message}`;
        } finally {
            estado.cargando = false;
            render();
        }
    }

    async function cargarAhora() {
        estado.mensaje = 'Consultando las fuentes…';
        render();
        try {
            const r = await api(cfg.cargar, { method: 'POST', body: '{}' });
            estado.datos = { ...r, config: estado.datos.config };
            estado.mensaje = r.message;
        } catch (e) {
            estado.mensaje = `!${e.message}`;
        }
        render();
    }

    el.addEventListener('click', (e) => {
        const b = e.target.closest('[data-a]');
        if (!b) return;
        const a = b.dataset.a, i = +b.dataset.i;
        const mover = (de, a2) => { const l = estado.borrador; [l[de], l[a2]] = [l[a2], l[de]]; render(); };
        switch (a) {
            case 'ampliar': estado.expandido = !estado.expandido; persistir(); el.classList.toggle('expandido', estado.expandido); el.querySelector('[data-a="ampliar"]').classList.toggle('on', estado.expandido); requestAnimationFrame(() => setTimeout(acomodar, 520)); break;
            case 'mini': estado.mini = !estado.mini; persistir(); render(); break;
            case 'refrescar': cargarTasas(true); break;
            case 'historial': abrirHistorial(b.closest('.trm-row')?.dataset.c); break;
            case 'config': estado.borrador = [...estado.datos.config.monedas]; estado.borradorColores = { general: null, borde: null, fondo: null, fuente: null, ...estado.datos.colores, monedas: { ...(estado.datos.colores?.monedas || {}) } }; estado.mensaje = ''; estado.vista = 'config'; render(); break;
            case 'volver': estado.vista = 'tasas'; estado.borradorColores = null; render(); break;
            case 'color-reset': estado.borradorColores[b.dataset.k] = null; render(); break;
            case 'subir': mover(i, i - 1); break;
            case 'bajar': mover(i, i + 1); break;
            case 'quitar': estado.borrador.splice(i, 1); render(); break;
            case 'agregar': {
                const v = el.querySelector('#trm-nueva')?.value;
                if (v && estado.borrador.length < estado.datos.config.maximo) { estado.borrador.push(v); render(); }
                break;
            }
            case 'guardar': guardarConfig(); break;
            case 'cargar-ahora': cargarAhora(); break;
            case 'rango': {
                estado.hist.rango = b.dataset.r;
                estado.hist.hasta = iso(new Date());
                estado.hist.desde = iso(new Date(Date.now() - (+b.dataset.r - 1) * 864e5));
                cargarHistorial();
                break;
            }
            case 'csv': descargarCsv(); break;
            default: break;
        }
    });

    // Vista previa en vivo de los colores sin redibujar (así el selector de color no se cierra).
    el.addEventListener('input', (e) => {
        const k = e.target.dataset?.color;
        if (!k || !estado.borradorColores) return;
        if (TEMA.some(([t]) => t === k)) { estado.borradorColores[k] = e.target.value; aplicarTema(estado.borradorColores); }
        else estado.borradorColores.monedas[k] = e.target.value;
    });

    el.addEventListener('change', (e) => {
        const h = estado.hist;
        if (e.target.id === 'trm-h-moneda') { h.moneda = e.target.value; cargarHistorial(); }
        if (e.target.id === 'trm-h-desde' || e.target.id === 'trm-h-hasta') {
            h.desde = el.querySelector('#trm-h-desde').value; h.hasta = el.querySelector('#trm-h-hasta').value; h.rango = null;
            if (h.desde && h.hasta) cargarHistorial();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && estado.vista !== 'tasas' && !document.querySelector('.swal2-container') && !document.querySelector('.ficha-overlay.is-open')) { estado.vista = 'tasas'; render(); }
    });

    // Refresco cada 10 min mientras la pestaña esté visible.
    setInterval(() => { if (!document.hidden && estado.vista === 'tasas') cargarTasas(); }, 600000);

    render();
    cargarTasas();
}
