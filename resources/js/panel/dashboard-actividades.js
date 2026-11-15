// Programador de actividades: lista los recordatorios y seguimientos del usuario y abre el panel lateral
// (media pantalla) para programar otros o cerrar los pendientes.
import { ActividadFichaModal } from './actividades-ficha.js';
import { toast } from './ficha-modal.js';
import { esc, fecha, hora, isoLocal, COLORES_CARD } from './dashboard-util.js';

const $ = (id) => document.getElementById(id);

export function iniciarActividades({ url, panelUrl, csrf, ms: msCfg, tareas: tareasCfg }) {
    const s = { vista: 'pendientes', datos: null, dia: null, colores: COLORES_CARD, externos: [], cacheExt: new Map(), filtro: null, modo: 'semana', sem: 0, mes: new Date(new Date().getFullYear(), new Date().getMonth(), 1) };
    const modal = new ActividadFichaModal({ panelUrl, csrfToken: csrf, onSaved: () => cargar(), tareaFormUrl: tareasCfg?.form, tareaShowUrl: tareasCfg?.show });

    const color = (card) => (s.colores[card] ?? COLORES_CARD[card]).fondo;

    /** Los chips de color son los filtros: cada color = un tipo de actividad (rojo atrasadas, ámbar hoy, azul próximas, verde realizadas). */
    function resumen(r) {
        const chips = [
            ['atrasado', 'Atrasadas', 'rojo', r.atrasados], ['hoy', 'Hoy', 'ambar', r.hoy], ['proximo', 'Próximas', 'azul', r.proximos], ['realizados', 'Realizadas', 'verde', r.realizados],
        ];
        const activo = (k) => (k === 'realizados' ? s.vista === 'realizados' : s.vista === 'pendientes' && s.filtro === k);
        $('d-act-resumen').innerHTML = chips.map(([k, t, c, n]) => `<button type="button" data-chip="${k}" class="ag-chip ${c} ${n ? '' : 'cero'} ${activo(k) ? 'on' : ''}" title="${activo(k) ? 'Quitar filtro' : `Ver ${t.toLowerCase()}`}"><b>${n}</b>${t}</button>`).join('')
            + `<button type="button" data-chip="historial" class="ag-chip gris ${s.vista === 'historial' ? 'on' : ''}" title="Mis seguimientos"><i class="fa-solid fa-comments"></i></button>`;
    }

    /** Día al que pertenece un pendiente: en la semana, lo atrasado se agrupa en hoy; en el mes, cada uno en su fecha. */
    const claveDia = (i) => (s.modo === 'semana' && i.grupo === 'atrasado' ? isoLocal(new Date()) : i.cuando.slice(0, 10));

    function modo() {
        const nombre = s.mes.toLocaleDateString('es-CO', { month: 'long', year: 'numeric' });
        const esMes = s.modo === 'mes';
        const hoyVisible = esMes ? (s.mes.getMonth() !== new Date().getMonth() || s.mes.getFullYear() !== new Date().getFullYear()) : s.sem !== 0;
        // En semana los días ya se ven en la tira; el nombre solo hace falta en el mes.
        $('d-act-modo').innerHTML = `<div class="dash-seg dash-seg-sm"><button type="button" data-modo="semana" class="${esMes ? '' : 'on'}">Semana</button><button type="button" data-modo="mes" class="${esMes ? 'on' : ''}">Mes</button></div>
            ${esMes || hoyVisible ? `<div class="ag-rango">${esMes ? `<b>${esc(nombre)}</b>` : ''}${hoyVisible ? '<button type="button" data-hoy>Hoy</button>' : ''}</div>` : ''}`;
    }

    /** Flechas a cada lado de los días: en la semana mueven 7 días, en el mes mueven un mes. */
    function flechas() {
        const mes = s.modo === 'mes';
        $('d-act-prev').dataset.sem = mes ? '' : '-1'; $('d-act-prev').dataset.mes = mes ? '-1' : '';
        $('d-act-next').dataset.sem = mes ? '' : '1'; $('d-act-next').dataset.mes = mes ? '1' : '';
        $('d-act-prev').title = mes ? 'Mes anterior' : 'Semana anterior';
        $('d-act-next').title = mes ? 'Mes siguiente' : 'Semana siguiente';
    }

    function semana(items) {
        const hoyIso = isoLocal(new Date());
        const cuenta = {};
        [...items, ...s.externos].forEach((i) => { const d = claveDia(i); cuenta[d] = (cuenta[d] || 0) + 1; });
        const celda = (d, extra = '') => {
            const iso = isoLocal(d), n = cuenta[iso] || 0;
            return `<button type="button" data-dia="${iso}" class="ag-dia ${iso === hoyIso ? 'hoy' : ''} ${s.dia === iso ? 'on' : ''} ${n ? 'con' : ''} ${extra}" title="${n} pendiente${n === 1 ? '' : 's'}">
                ${s.modo === 'semana' ? `<small>${d.toLocaleDateString('es-CO', { weekday: 'short' }).replace('.', '')}</small>` : ''}<b>${d.getDate()}</b><i>${n || ''}</i></button>`;
        };
        const cont = $('d-act-semana');
        cont.classList.toggle('mes', s.modo === 'mes');
        if (s.modo === 'semana') {
            cont.innerHTML = [...Array(7)].map((_, k) => { const d = new Date(); d.setDate(d.getDate() + s.sem * 7 + k); return celda(d); }).join('');
            return;
        }
        // Mes: cuadrícula de semanas completas (lunes a domingo).
        const primero = new Date(s.mes.getFullYear(), s.mes.getMonth(), 1);
        const inicio = new Date(primero); inicio.setDate(1 - ((primero.getDay() + 6) % 7));
        const ultimo = new Date(s.mes.getFullYear(), s.mes.getMonth() + 1, 0);
        const semanas = Math.ceil((((primero.getDay() + 6) % 7) + ultimo.getDate()) / 7);
        const cab = ['L', 'M', 'M', 'J', 'V', 'S', 'D'].map((l) => `<span class="ag-mes-cab">${l}</span>`).join('');
        cont.innerHTML = cab + [...Array(semanas * 7)].map((_, k) => { const d = new Date(inicio); d.setDate(inicio.getDate() + k); return celda(d, d.getMonth() === s.mes.getMonth() ? '' : 'fuera'); }).join('');
    }

    function itemExt(i) {
        const h = i.todoDia ? '<i class="fa-solid fa-sun"></i>' : hora(i.cuando);
        return `<button type="button" class="ag-item ext ${i.grupo}" data-ext="${esc(i.url || '')}" style="--c:#0078d4">
            <span class="ag-when"><b>${h}</b><small>${i.todoDia ? 'Todo el día' : fecha(i.cuando)}</small></span>
            <span class="ag-body"><b>${esc(i.titulo)}</b><small>Calendario de Outlook${i.organizador ? ` · ${esc(i.organizador)}` : ''}${i.teams ? ` · <span class="ag-teams" data-teams="${esc(i.teams)}" title="Unirse a la reunión de Teams"><i class="fa-solid fa-video"></i> Teams</span>` : ''}</small></span>
            <span class="flex items-center gap-2"><span class="ag-tag" title="Outlook"><i class="fa-brands fa-microsoft"></i></span></span></button>`;
    }

    /** Rango de fechas visible (semana o cuadrícula del mes) para pedir los eventos de Outlook. */
    function rangoVisible() {
        if (s.modo === 'semana') {
            const a = new Date(); a.setDate(a.getDate() + s.sem * 7);
            const b = new Date(a); b.setDate(b.getDate() + 6);
            return [isoLocal(a), isoLocal(b)];
        }
        const p = new Date(s.mes.getFullYear(), s.mes.getMonth(), 1);
        const a = new Date(p); a.setDate(1 - ((p.getDay() + 6) % 7));
        const b = new Date(a); b.setDate(a.getDate() + 41);
        return [isoLocal(a), isoLocal(b)];
    }

    async function cargarExternos(forzar = false) {
        if (!ms.est?.conectado || !ms.est.calendario || s.vista !== 'pendientes' || !msCfg?.eventos) { s.externos = []; return; }
        const [d, h] = rangoVisible(), clave = `${d}|${h}`;
        if (s.cacheExt.has(clave)) $('d-act-fila').classList.remove('cargando-ext');
        if (forzar) s.cacheExt.delete(clave);
        if (!s.cacheExt.has(clave)) {
            const fila = $('d-act-fila');
            fila.classList.add('cargando-ext'); // loading mientras se leen los eventos de Outlook
            try { s.cacheExt.set(clave, (await mj(`${msCfg.eventos}?desde=${d}&hasta=${h}`)).items); } catch (e) { console.error(e); s.cacheExt.set(clave, []); }
            if (clave === rangoVisible().join('|')) fila.classList.remove('cargando-ext');
        }
        if (clave !== rangoVisible().join('|')) return; // el usuario ya navegó a otro rango
        const hoy = new Date(); hoy.setHours(0, 0, 0, 0);
        s.externos = s.cacheExt.get(clave).map((i) => ({ ...i, ext: true, grupo: new Date(`${i.cuando.slice(0, 10)}T00:00`) < hoy ? 'pasado' : (i.cuando.slice(0, 10) === isoLocal(new Date()) ? 'hoy' : 'proximo') }));
        if (s.datos) { semana(s.datos.items); lista(); }
    }

    function item(i, extra = '') {
        const tarea = i.origen === 'tarea';
        const sub = [i.ref, i.empresa].filter(Boolean).join(' · ');
        const marcas = tarea ? [
            i.prioridad ? `<i class="fa-solid fa-flag" style="color:${esc(i.prioridad.color)}" title="Prioridad ${esc(i.prioridad.label)}"></i>` : '',
            i.recurrencia ? `<i class="fa-solid fa-repeat" title="${esc(i.recurrencia)}"></i>` : '',
            i.integrantes > 1 ? `<i class="fa-solid fa-user-group" title="${i.integrantes} integrantes"></i>` : '',
        ].filter(Boolean).join('') : '';
        const dato = tarea ? `data-tarea="${i.tarea}"` : `data-modulo="${i.modulo}" data-id="${i.fkRecord}"`;
        return `<button type="button" class="ag-item ${i.grupo} ${i.horaVencida ? 'vencida' : ''}" ${dato} style="--c:${color(i.card)}">
            <span class="ag-when"><b>${i.grupo === 'realizado' ? '<i class="fa-solid fa-check"></i>' : hora(i.cuando)}</b><small>${fecha(i.cuando)}</small></span>
            <span class="ag-body"><b>${esc(i.titulo)}</b><small>${esc(sub)}${marcas ? ` <span class="ag-marcas">${marcas}</span>` : ''}${i.teams ? ` · <span class="ag-teams" data-teams="${esc(i.teams)}" title="Unirse a la reunión de Teams"><i class="fa-solid fa-video"></i> Teams</span>` : ''}</small>${extra}</span>
            <span class="flex items-center gap-2">${i.ms ? '<span class="ag-msic" title="Sincronizado con Office 365"><i class="fa-brands fa-microsoft"></i></span>' : ''}${tarea && i.grupo !== 'realizado' ? `<span class="ag-check" role="button" data-tarea-hecho="${i.tarea}" title="Completar"><i class="fa-solid fa-check"></i></span>` : ''}<span class="ag-tag" title="${esc(i.moduloNombre)}"><i class="fa-solid ${i.icono}"></i></span></span></button>`;
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
            const delFiltro = (i) => !s.filtro || i.grupo === s.filtro;
            const propios = (s.dia ? d.items.filter((i) => claveDia(i) === s.dia) : d.items).filter(delFiltro);
            // Eventos de Outlook: del día elegido o, sin filtro, los de hoy en adelante dentro del rango visible.
            const ext = s.externos.filter((i) => (s.dia ? claveDia(i) === s.dia : i.grupo !== 'pasado') && delFiltro(i));
            const items = [...propios, ...ext];
            for (const [g, titulo] of [['atrasado', 'Atrasadas'], ['hoy', 'Hoy'], ['proximo', 'Próximas'], ['pasado', 'Eventos pasados']]) {
                const gi = items.filter((i) => i.grupo === g).sort((a, b) => a.cuando.localeCompare(b.cuando));
                if (gi.length) html += `<h4 class="ag-grupo ${g}">${titulo} <span>${gi.length}</span></h4>${gi.map((i) => (i.ext ? itemExt(i) : item(i))).join('')}`;
            }
            if (!items.length) html = s.dia ? vacio('fa-calendar-day', 'Nada programado para ese día.', 'Elige otro día o programa una actividad.') : vacio('fa-circle-check', 'No tienes actividades pendientes.', 'Programa el próximo contacto con un cliente.');
        }
        cont.innerHTML = html;
    }

    const vacio = (ico, t, sub) => `<div class="ag-vacio"><i class="fa-solid ${ico}"></i><b>${t}</b><span>${sub}</span><button type="button" class="dash-btn" data-act-nueva><i class="fa-solid fa-plus"></i> Programar actividad</button></div>`;

    function pintar() {
        resumen(s.datos.resumen);
        $('d-act-semana').classList.toggle('hidden', s.vista !== 'pendientes');
        $('d-act-modo').classList.toggle('hidden', s.vista !== 'pendientes');
        $('d-act-fila').classList.toggle('hidden', s.vista !== 'pendientes');
        if (s.vista === 'pendientes') { modo(); flechas(); semana(s.datos.items); }
        lista();
        cargarExternos();
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

    // ---- Office 365 (Outlook, To Do, Teams)
    const mj = (u, method = 'GET', body) => fetch(u, { method, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf, ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined }).then(async (r) => { const j = await r.json().catch(() => ({})); if (!r.ok) throw new Error(j.message || `Error ${r.status}`); return j; });
    const ms = { est: null, abierto: false };

    function pintarMs() {
        const c = $('d-act-ms'), e = ms.est;
        if (!msCfg || !e || !e.configurado) { c.classList.add('hidden'); return; }
        c.classList.remove('hidden');
        if (!e.conectado) {
            c.innerHTML = `<a class="ag-ms-btn" href="${msCfg.conectar}"><i class="fa-brands fa-microsoft"></i> ${e.reconectar ? 'Reconectar Office 365' : 'Conectar Office 365'}</a><small>Outlook, To Do y Teams</small>`;
            return;
        }
        const op = (k, t, ic) => `<label><input type="checkbox" data-ms-pref="${k}" ${e[k] ? 'checked' : ''}> <i class="fa-solid ${ic}"></i> ${t}</label>`;
        c.innerHTML = `<button type="button" class="ag-ms-btn on" data-ms-toggle title="${esc(e.email || '')}"><i class="fa-brands fa-microsoft"></i> Office 365 conectado <i class="fa-solid fa-chevron-${ms.abierto ? 'up' : 'down'}"></i></button>
            ${ms.abierto ? `<div class="ag-ms-pan"><b>${esc(e.email || '')}</b>
                ${op('calendario', 'Crear evento en Outlook', 'fa-calendar-days')}${op('tareas', 'Crear tarea en Microsoft To Do', 'fa-list-check')}${op('teams', 'Reunión de Teams por defecto', 'fa-video')}
                <div><button type="button" class="dash-btn" data-ms-sync><i class="fa-solid fa-rotate"></i> Sincronizar ahora</button><button type="button" class="dash-btn" data-ms-off><i class="fa-solid fa-link-slash"></i> Desconectar</button></div>
                <small>Los cambios de fecha o título en Outlook, y las tareas completadas en To Do, se reflejan aquí.${e.ultimaSync ? ` Última sincronización: ${esc(e.ultimaSync)}.` : ''}</small></div>` : ''}`;
    }

    async function cargarMs() {
        if (!msCfg) return;
        try { ms.est = await mj(msCfg.estado); } catch (e) { console.error(e); ms.est = null; }
        pintarMs();
        cargarExternos();
    }

    $('d-act-ms')?.addEventListener('click', async (ev) => {
        if (ev.target.closest('[data-ms-toggle]')) { ms.abierto = !ms.abierto; return pintarMs(); }
        try {
            if (ev.target.closest('[data-ms-sync]')) {
                const r = await mj(msCfg.sync, 'POST');
                toast.fire({ icon: 'success', title: `Sincronizado: ${r.enviados || 0} enviadas a Outlook, ${r.reprogramados} reprogramadas, ${r.completados} completadas` });
                s.cacheExt.clear(); cargarMs(); return cargar();
            }
            if (ev.target.closest('[data-ms-off]')) {
                if (!window.confirm('¿Desconectar Office 365? Los eventos ya creados en Outlook se conservan.')) return null;
                await mj(msCfg.base, 'DELETE'); ms.abierto = false; return cargarMs();
            }
        } catch (e) { toast.fire({ icon: 'error', title: e.message }); }
        return null;
    });
    $('d-act-ms')?.addEventListener('change', async (ev) => {
        if (!ev.target.matches('[data-ms-pref]')) return;
        const b = {}; document.querySelectorAll('[data-ms-pref]').forEach((i) => { b[i.dataset.msPref] = i.checked; });
        try { ms.est = await mj(msCfg.prefs, 'PUT', b); } catch (e) { toast.fire({ icon: 'error', title: e.message }); }
    });

    // Retorno del flujo OAuth (?ms=ok|error|cancelado)
    const q = new URLSearchParams(location.search);
    if (q.get('ms')) {
        const m = { ok: ['success', 'Office 365 conectado'], cancelado: ['info', 'Conexión con Office 365 cancelada'], error: ['error', 'No se pudo conectar con Office 365'] }[q.get('ms')];
        if (m) toast.fire({ icon: m[0], title: m[1] });
        q.delete('ms'); history.replaceState(null, '', location.pathname + (q.toString() ? `?${q}` : ''));
    }
    cargarMs();

    $('d-act-card').addEventListener('click', (ev) => {
        const tm = ev.target.closest('[data-teams]');
        if (tm) { ev.stopPropagation(); window.open(tm.dataset.teams, '_blank', 'noopener'); return null; }
        const ex = ev.target.closest('[data-ext]');
        if (ex) { if (ex.dataset.ext) window.open(ex.dataset.ext, '_blank', 'noopener'); return null; }
        if (ev.target.closest('[data-act-nueva]')) return modal.abrirNueva(false, null, s.vista === 'pendientes' ? s.dia : null);
        const ch = ev.target.closest('[data-chip]');
        if (ch) {
            const k = ch.dataset.chip, antes = s.vista;
            s.dia = null;
            if (k === 'realizados' || k === 'historial') { s.vista = s.vista === k ? 'pendientes' : k; s.filtro = null; }
            else { s.vista = 'pendientes'; s.filtro = s.vista === antes && s.filtro === k ? null : k; }
            if (s.vista !== antes) return cargar();
            resumen(s.datos.resumen); lista();
            return null;
        }
        const md = ev.target.closest('[data-modo]');
        if (md) { s.modo = md.dataset.modo; s.dia = null; if (s.modo === 'mes') s.mes = new Date(new Date().getFullYear(), new Date().getMonth(), 1); modo(); flechas(); s.externos = []; semana(s.datos.items); lista(); return cargarExternos(); }
        const sm = ev.target.closest('[data-sem]');
        if (sm && sm.dataset.sem) { s.sem += Number(sm.dataset.sem); s.dia = null; s.externos = []; modo(); semana(s.datos.items); lista(); return cargarExternos(); }
        if (ev.target.closest('[data-hoy]')) { s.sem = 0; s.mes = new Date(new Date().getFullYear(), new Date().getMonth(), 1); s.dia = null; modo(); semana(s.datos.items); return lista(); }
        const mn = ev.target.closest('[data-mes]');
        if (mn && mn.dataset.mes) {
            s.mes = new Date(s.mes.getFullYear(), s.mes.getMonth() + Number(mn.dataset.mes), 1);
            s.dia = null; s.externos = []; modo(); semana(s.datos.items); lista(); return cargarExternos();
        }
        const d = ev.target.closest('[data-dia]');
        if (d) { s.dia = s.dia === d.dataset.dia ? null : d.dataset.dia; semana(s.datos.items); return lista(); }
        const ck = ev.target.closest('[data-tarea-hecho]');
        if (ck) {
            ev.stopPropagation();
            mj(tareasCfg.estado.replace('__ID__', ck.dataset.tareaHecho), 'PUT', { cerrada: true })
                .then((r) => { toast.fire({ icon: 'success', title: r.message }); return cargar(); })
                .catch((e) => toast.fire({ icon: 'error', title: e.message }));
            return null;
        }
        const it = ev.target.closest('.ag-item');
        if (it?.dataset.tarea) { modal.lista = null; return modal.abrirTarea(it.dataset.tarea); }
        if (it) { modal.lista = null; return modal.abrirDocumento(it.dataset.modulo, it.dataset.id); }
        return null;
    });

    return { cargar, abrir: (modulo, id, lista) => { modal.lista = lista ? { ...lista, vistos: new Set() } : null; return modal.abrirDocumento(modulo, id); }, colores: (c) => { if (c) s.colores = c; } };
}
