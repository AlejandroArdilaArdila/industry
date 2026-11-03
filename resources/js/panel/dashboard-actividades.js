// Programador de actividades: lista los recordatorios y seguimientos del usuario y abre el panel lateral
// (media pantalla) para programar otros o cerrar los pendientes.
import { ActividadFichaModal } from './actividades-ficha.js';
import { esc, fecha, hora, isoLocal, COLORES_CARD } from './dashboard-util.js';

const $ = (id) => document.getElementById(id);

export function iniciarActividades({ url, panelUrl, csrf }) {
    const s = { vista: 'pendientes', datos: null, dia: null, colores: COLORES_CARD };
    const modal = new ActividadFichaModal({ panelUrl, csrfToken: csrf, onSaved: () => cargar() });

    const color = (card) => (s.colores[card] ?? COLORES_CARD[card]).fondo;

    function resumen(r) {
        const chips = [
            ['atrasados', 'Atrasadas', 'rojo', r.atrasados], ['hoy', 'Hoy', 'ambar', r.hoy], ['proximos', 'Próximas', 'azul', r.proximos], ['realizados', 'Realizadas', 'verde', r.realizados],
        ];
        $('d-act-resumen').innerHTML = chips.map(([k, t, c, n]) => `<span class="ag-chip ${c} ${n ? '' : 'cero'}"><b>${n}</b>${t}</span>`).join('');
    }

    function semana(items) {
        const hoyIso = isoLocal(new Date());
        const cuenta = {};
        items.forEach((i) => { const d = i.grupo === 'atrasado' ? hoyIso : i.cuando.slice(0, 10); cuenta[d] = (cuenta[d] || 0) + 1; });
        const dias = [...Array(7)].map((_, k) => { const d = new Date(); d.setDate(d.getDate() + k); return d; });
        $('d-act-semana').innerHTML = dias.map((d) => {
            const iso = isoLocal(d), n = cuenta[iso] || 0;
            return `<button type="button" data-dia="${iso}" class="ag-dia ${iso === hoyIso ? 'hoy' : ''} ${s.dia === iso ? 'on' : ''} ${n ? 'con' : ''}" title="${n} pendiente${n === 1 ? '' : 's'}">
                <small>${d.toLocaleDateString('es-CO', { weekday: 'short' }).replace('.', '')}</small><b>${d.getDate()}</b><i>${n || ''}</i></button>`;
        }).join('');
    }

    function item(i, extra = '') {
        return `<button type="button" class="ag-item ${i.grupo} ${i.horaVencida ? 'vencida' : ''}" data-modulo="${i.modulo}" data-id="${i.fkRecord}" style="--c:${color(i.card)}">
            <span class="ag-when"><b>${i.grupo === 'realizado' ? '<i class="fa-solid fa-check"></i>' : hora(i.cuando)}</b><small>${fecha(i.cuando)}</small></span>
            <span class="ag-body"><b>${esc(i.titulo)}</b><small>${esc(i.ref)} · ${esc(i.empresa)}</small>${extra}</span>
            <span class="ag-tag" title="${esc(i.moduloNombre)}"><i class="fa-solid ${i.icono}"></i></span></button>`;
    }

    function lista() {
        const d = s.datos;
        const cont = $('d-act-lista');
        let html = '';

        if (s.vista === 'historial') {
            html = d.items.map((i) => `<button type="button" class="ag-item hist" data-modulo="${i.modulo}" data-id="${i.fkRecord}" style="--c:${color(i.card)}">
                <span class="ag-when"><b><i class="fa-solid fa-comment"></i></b><small>${fecha(i.fecha)}</small></span>
                <span class="ag-body"><b>${esc(i.ref)} · ${esc(i.empresa)}</b><small class="ag-texto">${esc(i.texto)}</small></span>
                <span class="ag-tag" title="${esc(i.moduloNombre)}"><i class="fa-solid ${i.icono}"></i></span></button>`).join('');
            if (!d.items.length) html = vacio('fa-comments', 'Aún no has registrado seguimientos.', 'Programa uno y quedará aquí.');
        } else if (s.vista === 'realizados') {
            html = d.items.map((i) => item(i, i.actividad ? `<small class="ag-texto ok">${esc(i.actividad)}</small>` : '')).join('');
            if (!d.items.length) html = vacio('fa-clipboard-check', 'Todavía no has cerrado actividades.', 'Las que marques como realizadas aparecen aquí.');
        } else {
            const items = s.dia ? d.items.filter((i) => (i.grupo === 'atrasado' ? isoLocal(new Date()) : i.cuando.slice(0, 10)) === s.dia) : d.items;
            for (const [g, titulo] of [['atrasado', 'Atrasadas'], ['hoy', 'Hoy'], ['proximo', 'Próximas']]) {
                const gi = items.filter((i) => i.grupo === g);
                if (gi.length) html += `<h4 class="ag-grupo ${g}">${titulo} <span>${gi.length}</span></h4>${gi.map((i) => item(i)).join('')}`;
            }
            if (!items.length) html = s.dia ? vacio('fa-calendar-day', 'Nada programado para ese día.', 'Elige otro día o programa una actividad.') : vacio('fa-circle-check', 'No tienes actividades pendientes.', 'Programa el próximo contacto con un cliente.');
        }
        cont.innerHTML = html;
    }

    const vacio = (ico, t, sub) => `<div class="ag-vacio"><i class="fa-solid ${ico}"></i><b>${t}</b><span>${sub}</span><button type="button" class="dash-btn" data-act-nueva><i class="fa-solid fa-plus"></i> Programar actividad</button></div>`;

    function pintar() {
        resumen(s.datos.resumen);
        $('d-act-semana').classList.toggle('hidden', s.vista !== 'pendientes');
        if (s.vista === 'pendientes') semana(s.datos.items);
        lista();
    }

    async function cargar() {
        $('d-act-card').classList.add('cargando');
        try {
            const res = await fetch(`${url}?vista=${s.vista}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error(`Error ${res.status}`);
            s.datos = await res.json();
            pintar();
        } catch (e) {
            console.error(e);
            $('d-act-lista').innerHTML = `<div class="ag-vacio"><i class="fa-solid fa-triangle-exclamation"></i><b>No se pudieron cargar las actividades</b><span>${esc(e.message)}</span></div>`;
        } finally {
            $('d-act-card').classList.remove('cargando');
        }
    }

    $('d-act-card').addEventListener('click', (ev) => {
        if (ev.target.closest('[data-act-nueva]')) return modal.abrirNueva();
        const v = ev.target.closest('[data-vista]');
        if (v) {
            s.vista = v.dataset.vista; s.dia = null;
            document.querySelectorAll('#d-act-tabs [data-vista]').forEach((b) => b.classList.toggle('on', b === v));
            return cargar();
        }
        const d = ev.target.closest('[data-dia]');
        if (d) { s.dia = s.dia === d.dataset.dia ? null : d.dataset.dia; semana(s.datos.items); return lista(); }
        const it = ev.target.closest('.ag-item');
        if (it) { modal.lista = null; return modal.abrirDocumento(it.dataset.modulo, it.dataset.id); }
        return null;
    });

    return { cargar, abrir: (modulo, id, lista) => { modal.lista = lista ? { ...lista, vistos: new Set() } : null; return modal.abrirDocumento(modulo, id); }, colores: (c) => { if (c) s.colores = c; } };
}
