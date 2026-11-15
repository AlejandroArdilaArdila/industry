// Métricas de la leyenda del tablero (urgencia, oportunidad, problemas, estado operativo, cumplimiento) y la salud
// (al día / en riesgo / en mora) de cada etapa, con los colores configurados en la leyenda.
import { Chart } from 'chart.js';
import { esc, nf, colorTema, alfa } from './dashboard-util.js';

const $ = (id) => document.getElementById(id);
const PALETA = ['#2563eb', '#ea580c', '#059669', '#7c3aed', '#0d9488', '#f59e0b', '#db2777', '#64748b'];
const DEFS = [
    ['urgencia', 'Urgencia', 'fa-fire', '#dc2626', 'dolor del cliente'],
    ['oportunidad', 'Oportunidad', 'fa-bullseye', '#ea580c', 'cotizaciones'],
    ['problema', 'Problemas', 'fa-triangle-exclamation', '#7c3aed', 'órdenes de compra'],
    ['estado_op', 'Estado operativo', 'fa-gears', '#0d9488', 'órdenes de compra'],
    ['cumplimiento', 'Cumplimiento', 'fa-check-double', '#059669', 'entregas'],
];

export function iniciarLeyenda({ url, getPeriodo, getAlcance }) {
    // Bloque suspendido en la vista (sin url): no se consulta ni se pinta nada.
    if (!url) return { cargar() {}, etapas() {}, repintar() {} };
    const e = { datos: null, etapas: null, charts: {}, ctrl: null };

    const grafica = (id, cfg) => {
        e.charts[id]?.destroy();
        e.charts[id] = new Chart($(id), cfg);
    };

    function pintar() {
        const d = e.datos;
        if (!d) return;
        const vivos = DEFS.filter(([k]) => Object.values(d.categorias[k] ?? {}).some((n) => n > 0));
        const salud = e.etapas ? `<div class="dash-card" style="--i:0"><h3 class="dash-h"><span class="inline-flex items-center gap-2 text-[0.8rem] font-bold"><i class="fa-solid fa-heart-pulse" style="color:#dc2626"></i> Salud por etapa</span><small class="text-muted-foreground">mora, riesgo y al día</small></h3><div class="relative h-[230px]"><canvas id="c-salud"></canvas></div></div>` : '';
        const tarjetas = vivos.map(([k, titulo, ico, c, sub], i) => `<div class="dash-card" style="--i:${i + 1}"><h3 class="dash-h"><span class="inline-flex items-center gap-2 text-[0.8rem] font-bold"><i class="fa-solid ${ico}" style="color:${c}"></i> ${titulo}</span><small class="text-muted-foreground">${sub}</small></h3><div class="relative h-[230px]"><canvas id="c-ley-${k}"></canvas></div></div>`).join('');
        const faltan = DEFS.filter((x) => !vivos.includes(x)).map((x) => x[1].toLowerCase());
        const nota = faltan.length ? `<div class="dash-card" style="--i:${vivos.length + 1}"><div class="vacio"><i class="fa-solid fa-tags"></i>Sin clasificar en este periodo: ${esc(faltan.join(', '))}.<br>Se llenan cuando se asigna la leyenda a los documentos desde el tablero.</div></div>` : '';
        $('d-leyenda').innerHTML = salud + tarjetas + nota;
        $('d-ley-nota').textContent = `Documentos activos creados del ${d.ventana[0]} al ${d.ventana[1]}`;

        const txt = colorTema('--muted-foreground');
        const borde = colorTema('--card');
        vivos.forEach(([k]) => {
            const datos = d.categorias[k];
            const etiquetas = Object.keys(datos).filter((l) => datos[l] > 0);
            const colores = d.leyenda[k]?.items ?? {};
            grafica(`c-ley-${k}`, {
                type: 'doughnut',
                data: { labels: etiquetas, datasets: [{ data: etiquetas.map((l) => datos[l]), backgroundColor: etiquetas.map((l, i) => colores[l] || PALETA[i % PALETA.length]), borderWidth: 2, borderColor: borde, hoverOffset: 8 }] },
                options: { maintainAspectRatio: false, cutout: '66%', animation: { animateRotate: true, duration: 1100 }, plugins: { legend: { position: 'right', labels: { usePointStyle: true, color: txt, boxWidth: 8 } }, tooltip: { callbacks: { label: (c) => ` ${c.label}: ${nf.format(c.raw)}` } } } },
            });
        });
        pintarSalud();
    }

    function pintarSalud() {
        if (!e.etapas || !$('c-salud')) return;
        const txt = colorTema('--muted-foreground');
        const cols = e.etapas[0].mora.colores;
        const serie = (k) => e.etapas.map((x) => x.mora[k]);
        grafica('c-salud', {
            type: 'bar',
            data: {
                labels: e.etapas.map((x) => x.nombre.split(' / ')[0].replace('ÓRDENES DE COMPRA', 'ÓRDENES').replace(/^./, (c) => c) ),
                datasets: [
                    { label: 'Al día', data: serie('aldia'), backgroundColor: cols.aldia, borderRadius: 6 },
                    { label: 'En riesgo', data: serie('riesgo'), backgroundColor: cols.riesgo, borderRadius: 6 },
                    { label: 'En mora', data: serie('mora'), backgroundColor: cols.mora, borderRadius: 6 },
                ],
            },
            options: {
                indexAxis: 'y', maintainAspectRatio: false, animation: { duration: 1000, easing: 'easeOutQuart' },
                scales: { x: { stacked: true, grid: { color: alfa(txt, 18) }, border: { display: false }, ticks: { color: txt, precision: 0 } }, y: { stacked: true, grid: { display: false }, border: { display: false }, ticks: { color: txt, font: { size: 10 } } } },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, color: txt, boxWidth: 8 } } },
            },
        });
    }

    async function cargar() {
        e.ctrl?.abort();
        e.ctrl = new AbortController();
        try {
            const res = await fetch(`${url}?${new URLSearchParams({ periodo: getPeriodo(), alcance: getAlcance() })}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: e.ctrl.signal });
            if (!res.ok) throw new Error(`Error ${res.status}`);
            e.datos = await res.json();
            pintar();
        } catch (err) {
            if (err.name === 'AbortError') return;
            console.error(err);
            $('d-ley-nota').textContent = 'No se pudieron cargar las métricas';
        }
    }

    return {
        cargar,
        etapas: (etapas) => { e.etapas = etapas; if (e.datos) pintar(); },
        repintar: () => pintar(),
    };
}
