// Tablero (kanban) de solicitudes por urgencia: Atrasadas, Atención, Por contactar y Al día (días de mora).
// Arrastrar a «Por contactar» programa el contacto para hoy; sacarla de allí a otra columna lo reprograma.
// El estado real de la solicitud (activa, en cotización, importación…) se cambia desde el menú de la tarjeta.
// Las columnas cargan de a poco con «Ver más».
import '../../css/solicitudes-tablero.css';
import Swal from 'sweetalert2';
import { toast } from '../panel/ficha-modal.js';
import { esc } from '../panel/dashboard-util.js';
import { seguimientoSolicitud } from './solicitud-seguimiento.js';

const MORA = {
    verde: { txt: 'Al día', hasta: 15 },
    ambar: { txt: 'Atención', hasta: 30 },
    rojo: { txt: 'Atrasada', hasta: Infinity },
};
const nivelMora = (d) => (d <= MORA.verde.hasta ? 'verde' : d <= MORA.ambar.hasta ? 'ambar' : 'rojo');
// Color de la etiqueta de estado de la solicitud.
const COLOR_ESTADO = { 1: '#2563eb', 2: '#0891b2', 3: '#0891b2', 6: '#0891b2', 4: '#7c3aed', 5: '#059669' };

const fechaNum = (iso) => (iso ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}` : '');
const fechaCorta = (iso) => (iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('es-CO', { day: 'numeric', month: 'short' }) : '');
const iniciales = (n) => String(n ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';
const sumarDias = (n) => {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};

// Estados que cuenta cada tarjeta de estadísticas que se ven en el tablero (las órdenes de compra no se trabajan aquí).
const ETAPAS = { activas: [1, 2, 3, 4, 5, 6], proceso: [2, 3, 4, 6], cotizaciones: [5] };

export function iniciarTablero({ raiz, config, csrfToken, ficha, panel, onCambio }) {
    const columnas = config.tablero.columnas;
    const estado = {}; // clave => { items, total, cargando }
    const filtros = { q: '', estados: [], desde: '', hasta: '' };
    let grupo = []; // estados que dejan las tarjetas de estadísticas ('' = todos)
    let motivos = null;
    let arrastrada = null;

    const cuerpo = raiz.querySelector('[data-kb-cuerpo]');
    const cabecera = (opts) => ({
        Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest', ...opts,
    });
    const pedir = async (url, metodo = 'GET', datos = null) => {
        const r = await fetch(url, {
            method: metodo,
            headers: cabecera(datos ? { 'Content-Type': 'application/json' } : {}),
            body: datos ? JSON.stringify(datos) : undefined,
        });
        const json = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(json.message || Object.values(json.errors ?? {})[0]?.[0] || 'No se pudo completar la acción.');
        return json;
    };

    // ------------------------------------------------------------ armado

    cuerpo.innerHTML = columnas.map((c) => `
        <section class="kb-col" data-col="${c.clave}" style="--c:${c.color}">
            <header class="kb-col-cab" title="${esc(c.ayuda)}">
                <span class="kb-col-ico"><i class="fa-solid ${c.icono}"></i></span>
                <b>${esc(c.titulo)}</b><small>${esc(c.ayuda)}</small>
                <span class="kb-col-n" data-n>–</span>
            </header>
            <div class="kb-col-lista" data-lista></div>
        </section>`).join('');

    const col = (clave) => cuerpo.querySelector(`[data-col="${clave}"]`);

    function tarjeta(s) {
        const vencido = s.contactoVencido;
        const ultimo = s.ultimoSeguimiento;
        const sal = s.salud, urg = s.urgencia;
        const cUrg = urg?.color ?? '#94a3b8';
        const desde = s.reabierta ?? s.ingreso;
        const prox = s.proximoContacto ? (vencido ? `Contactar · ${fechaCorta(s.proximoContacto)}` : fechaCorta(s.proximoContacto)) : '';
        // Borde izquierdo = urgencia; borde derecho y punto = salud (al día / en riesgo / en mora).
        return `<article class="kb-card sol" style="--u:${cUrg};--m:${sal.color}" draggable="true" data-id="${s.id}">
            <div class="sol-hdr">
                <span class="sol-dot" title="${esc(sal.nombre)}"></span>
                <a href="#" class="sol-id" data-acc="editar" title="Abrir la solicitud">${esc(s.ref)}</a>
                <button type="button" class="sol-badge ${urg ? '' : 'sin'}" data-acc="urgencia" title="Urgencia: clic para cambiarla" style="--b:${cUrg}">${urg ? esc(urg.valor) : '+ Urgencia'}</button>
                <button type="button" class="kb-mas" data-acc="menu" title="Más acciones" aria-label="Más acciones"><i class="fa-solid fa-ellipsis-vertical"></i></button>
            </div>
            <div class="sol-cli" title="${esc(s.cliente || '')}">${esc(s.cliente || 'Sin cliente')}</div>
            <div class="sol-desc" title="${esc(s.asunto)}">${esc(s.asunto) || '<em>Sin asunto</em>'}</div>
            <div class="sol-grid">
                <div class="sol-tag" data-acc="urgencia" title="Urgencia (clic para cambiarla)"><div class="lbl">Urgencia</div><div class="val" style="color:${cUrg}"><span class="dot" style="background:${cUrg}"></span>${urg ? esc(urg.valor) : 'Sin definir'}</div></div>
                <div class="sol-tag" title="Salud según los días de mora"><div class="lbl">Estado</div><div class="val" style="color:${sal.color}"><span class="dot" style="background:${sal.color}"></span>${esc(sal.nombre)}</div></div>
            </div>
            <div class="sol-mora">
                <span class="lbl">MORA TOTAL <small>desde ${s.reabierta ? 'reapertura' : 'ingreso'} ${fechaNum(desde)}</small></span>
                <span class="val">${s.moraTotal} <small>días</small></span>
            </div>
            <div class="sol-ft">
                <span class="sol-ing" title="Fecha de ingreso">&#128197; Ingreso: ${fechaNum(s.ingreso)}</span>
                <span class="sol-resp" title="Gestor">&#128100; ${esc(s.gestor || '—')}</span>
            </div>
            ${ultimo ? `<div class="sol-seg" title="${esc(ultimo.texto)}">&#128204; <b>${ultimo.fecha ? fechaNum(ultimo.fecha) : ''}</b>${ultimo.autor ? ` · ${esc(ultimo.autor)}` : ''}</div>` : '<div class="sol-seg sin">&#128204; Sin seguimiento</div>'}
            <div class="sol-acc">
                <span class="sol-est" title="Estado de la solicitud" style="--e:${COLOR_ESTADO[s.estado] ?? '#64748b'}">${esc(s.estadoNombre)}</span>
                ${prox ? `<span class="sol-prox ${vencido ? 'vencido' : ''}" title="Próximo contacto"><i class="fa-solid fa-phone"></i> ${prox}</span>` : ''}
                ${s.productos ? `<span class="sol-mini" title="Productos"><i class="fa-solid fa-box"></i>${s.productos}</span>` : ''}
                <span class="sol-mini ${s.correoEnviado ? 'ok' : ''}" title="${s.correoEnviado ? 'Correo enviado al cliente' : 'Aún sin correo al cliente'}"><i class="fa-solid ${s.correoEnviado ? 'fa-envelope-circle-check' : 'fa-envelope'}"></i></span>
                <button type="button" class="kb-seguir" data-acc="seguimiento" title="Registrar seguimiento"><i class="fa-solid fa-comment-medical"></i>Seguimiento</button>
            </div>
        </article>`;
    }

    function pintar(clave) {
        const e = estado[clave];
        const c = col(clave);
        c.querySelector('[data-n]').textContent = e.total;
        const lista = c.querySelector('[data-lista]');
        if (e.cargando && !e.items.length) {
            lista.innerHTML = '<div class="kb-esqueleto"></div><div class="kb-esqueleto"></div><div class="kb-esqueleto"></div>';
            return;
        }
        const def = columnas.find((x) => x.clave === clave);
        lista.innerHTML = e.items.length
            ? e.items.map(tarjeta).join('') + (e.items.length < e.total
                ? `<button type="button" class="kb-mas-items" data-acc="mas">${e.cargando ? 'Cargando…' : `Ver más (${e.total - e.items.length})`}</button>` : '')
            : `<div class="kb-vacio"><i class="fa-solid ${def.icono}"></i><span>${filtros.q || filtros.estados.length || filtros.desde || filtros.hasta ? 'Nada con esos filtros' : 'Sin solicitudes'}</span></div>`;
    }

    async function cargar(clave, mas = false) {
        const e = estado[clave] ??= { items: [], total: 0, cargando: false };
        if (e.cargando) return;
        e.cargando = true;
        if (!mas) e.items = [];
        pintar(clave);
        const p = new URLSearchParams();
        if (filtros.q) p.set('q', filtros.q);
        if (filtros.estados.length) p.set('estados', filtros.estados.join(','));
        if (filtros.desde) p.set('desde', filtros.desde);
        if (filtros.hasta) p.set('hasta', filtros.hasta);
        if (mas) p.set('skip', e.items.length);
        try {
            const r = await pedir(`${config.tablero.columnaUrl.replace('__COL__', clave)}?${p}`);
            e.items = mas ? e.items.concat(r.items) : r.items;
            e.total = r.total;
        } catch (err) {
            toast.fire({ icon: 'error', title: err.message });
        }
        e.cargando = false;
        pintar(clave);
    }

    const cargarTodas = () => columnas.forEach((c) => cargar(c.clave));

    // ------------------------------------------------------------ mover

    async function elegirMotivo(s) {
        try { motivos ??= (await pedir(config.tablero.motivosUrl)).items; } catch (err) { toast.fire({ icon: 'error', title: err.message }); return null; }
        const { value } = await Swal.fire({
            title: `Cerrar ${s.ref}`, text: 'Podrás reabrirla después.', icon: 'warning', input: 'select',
            inputOptions: Object.fromEntries(motivos.map((m) => [m.id, m.label])), inputPlaceholder: 'Motivo del cierre', inputValidator: (v) => (v ? undefined : 'Elige el motivo'),
            showCancelButton: true, confirmButtonText: 'Cerrar solicitud', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626', reverseButtons: true,
        });
        return value ? Number(value) : null;
    }

    function buscar(id) {
        for (const c of columnas) {
            const s = estado[c.clave]?.items.find((x) => x.id === id);
            if (s) return { s, origen: c.clave };
        }
        return null;
    }

    /** Programa el próximo contacto (y deja la observación) con la misma acción del seguimiento. */
    async function programar(s, fecha, texto) {
        const r = await pedir(config.tablero.seguimientoUrl, 'POST', {
            modulo: 'solicitud', id: s.id, observacion: texto, programar: true, fecha, hora: '08:00', titulo: `Seguimiento ${s.ref}`,
        });
        toast.fire({ icon: 'success', title: r.message });
    }

    async function elegirFecha(s) {
        const { value } = await Swal.fire({
            title: `Reprogramar ${s.ref}`, text: '¿Cuándo es el próximo contacto?', input: 'radio',
            inputOptions: { 1: 'Mañana', 3: 'En 3 días', 7: 'En 1 semana', 15: 'En 15 días' }, inputValue: '3', inputValidator: (v) => (v ? undefined : 'Elige una opción'),
            showCancelButton: true, confirmButtonText: 'Reprogramar', cancelButtonText: 'Cancelar', reverseButtons: true,
        });
        return value ? sumarDias(Number(value)) : null;
    }

    /** Suelta una tarjeta en otra columna: a «Por contactar» = contactar hoy; de ahí a otra = reprogramar. */
    async function soltarEn(id, destino) {
        const hallada = buscar(id);
        if (!hallada || hallada.origen === destino) return;
        const { s, origen } = hallada;
        try {
            if (destino === 'contactar') {
                await programar(s, sumarDias(0), 'Contacto adelantado desde el tablero');
            } else if (origen === 'contactar') {
                const fecha = await elegirFecha(s);
                if (!fecha) return;
                await programar(s, fecha, 'Contacto reprogramado desde el tablero');
            } else {
                toast.fire({ icon: 'info', title: 'La mora corre desde que se creó la solicitud; no se cambia a mano.' });
                return;
            }
        } catch (err) {
            toast.fire({ icon: 'error', title: err.message });
        }
        cargar(origen);
        cargar(destino);
        onCambio?.();
    }

    /** Cambia el estado real de la solicitud (cerrar pide el motivo; reabrir sigue las reglas de la ficha). */
    async function cambiarEstado(id, nuevo) {
        const hallada = buscar(id);
        if (!hallada) return;
        const { s, origen } = hallada;
        if (s.estado === nuevo) return;
        if (nuevo === 9 && s.estado !== 1) { toast.fire({ icon: 'info', title: 'Solo se cierran las solicitudes activas.' }); return; }
        const motivo = nuevo === 9 ? await elegirMotivo(s) : undefined;
        if (nuevo === 9 && !motivo) return;
        try {
            const r = await pedir(config.tablero.moverUrl.replace('__ID__', id), 'POST', { estado: nuevo, ...(motivo ? { motivo } : {}) });
            toast.fire({ icon: 'success', title: r.message });
        } catch (err) {
            toast.fire({ icon: 'error', title: err.message });
        }
        cargar(origen);
        onCambio?.();
    }

    // ------------------------------------------------------------ arrastrar

    cuerpo.addEventListener('dragstart', (ev) => {
        const t = ev.target.closest?.('.kb-card');
        if (!t || t.classList.contains('fija')) { ev.preventDefault(); return; }
        arrastrada = Number(t.dataset.id);
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', String(arrastrada));
        requestAnimationFrame(() => t.classList.add('arrastrando'));
        const s = buscar(arrastrada)?.s;
        const origen = buscar(arrastrada)?.origen;
        // A «Por contactar» se llega desde cualquier columna; desde ella se sale a cualquiera (reprogramar).
        columnas.forEach((c) => col(c.clave).classList.toggle('destino', c.clave !== origen && (c.clave === 'contactar' || origen === 'contactar')));
    });
    cuerpo.addEventListener('dragend', () => {
        arrastrada = null;
        cuerpo.querySelectorAll('.arrastrando').forEach((n) => n.classList.remove('arrastrando'));
        cuerpo.querySelectorAll('.destino,.sobre').forEach((n) => n.classList.remove('destino', 'sobre'));
    });
    cuerpo.addEventListener('dragover', (ev) => {
        const c = ev.target.closest?.('.kb-col.destino');
        if (!c || arrastrada === null) return;
        ev.preventDefault();
        cuerpo.querySelectorAll('.sobre').forEach((n) => { if (n !== c) n.classList.remove('sobre'); });
        c.classList.add('sobre');
    });
    cuerpo.addEventListener('drop', (ev) => {
        const c = ev.target.closest?.('.kb-col.destino');
        if (!c || arrastrada === null) return;
        ev.preventDefault();
        const id = arrastrada;
        cuerpo.dispatchEvent(new Event('dragend'));
        soltarEn(id, c.dataset.col);
    });

    // ------------------------------------------------------------ acciones de la tarjeta

    const cerrarFlotantes = () => document.querySelectorAll('.kb-flotante').forEach((n) => n.remove());

    function flotante(ancla, html) {
        cerrarFlotantes();
        const f = document.createElement('div');
        f.className = 'kb-flotante';
        f.innerHTML = html;
        document.body.appendChild(f);
        const r = ancla.getBoundingClientRect();
        const w = f.offsetWidth;
        const h = f.offsetHeight;
        f.style.left = `${Math.max(8, Math.min(r.right - w, innerWidth - w - 8))}px`;
        f.style.top = `${r.bottom + h + 8 > innerHeight ? Math.max(8, r.top - h - 4) : r.bottom + 4}px`;
        return f;
    }

    function menu(ancla, s, origen) {
        const f = flotante(ancla, `
            <button type="button" data-m="seguimiento"><i class="fa-solid fa-comment-medical"></i>Registrar seguimiento</button>
            <button type="button" data-m="historial"><i class="fa-solid fa-comments"></i>Ver seguimiento completo</button>
            <button type="button" data-m="editar"><i class="fa-solid fa-pen-to-square"></i>Abrir solicitud</button>
            <hr><small>Cambiar estado</small>
            ${config.tablero.estados.filter((e) => e.id !== s.estado).map((e) => `<button type="button" data-m="estado" data-estado="${e.id}" style="--c:${COLOR_ESTADO[e.id] ?? '#dc2626'}"><i class="fa-solid fa-circle"></i>${esc(e.id === 9 ? 'Cerrar solicitud' : e.nombre)}</button>`).join('')}`);
        f.addEventListener('click', (ev) => {
            const b = ev.target.closest('[data-m]');
            if (!b) return;
            cerrarFlotantes();
            if (b.dataset.m === 'editar') ficha.abrir(s.id);
            else if (b.dataset.m === 'historial') panel.abrirDocumento('solicitud', s.id);
            else if (b.dataset.m === 'seguimiento') seguimientoRapido(ancla, s, origen);
            else cambiarEstado(s.id, Number(b.dataset.estado));
        });
    }

    /** Fija la urgencia (Alta / Media / Baja) desde la tarjeta; se repinta la columna al guardar. */
    function menuUrgencia(ancla, s, origen) {
        const f = flotante(ancla, `<small>Urgencia</small>${(config.tablero.urgencias ?? []).map((u) => `<button type="button" data-u="${esc(u.valor)}" style="--c:${u.color}"><i class="fa-solid fa-circle"></i>${esc(u.valor)}${s.urgencia?.valor === u.valor ? ' <i class="fa-solid fa-check"></i>' : ''}</button>`).join('')}`);
        f.addEventListener('click', async (ev) => {
            const b = ev.target.closest('[data-u]');
            if (!b) return;
            cerrarFlotantes();
            if (s.urgencia?.valor === b.dataset.u) return;
            try {
                const r = await pedir(config.tablero.urgenciaUrl.replace('__ID__', s.id), 'PUT', { valor: b.dataset.u });
                s.urgencia = r.urgencia;
                pintar(origen);
                toast.fire({ icon: 'success', title: r.message });
            } catch (err) { toast.fire({ icon: 'error', title: err.message }); }
        });
    }

    /** Seguimiento completo (bitácora, próximo contacto y correo con IA) en un diálogo. */
    function seguimientoRapido(ancla, s, origen) {
        cerrarFlotantes();
        seguimientoSolicitud({ id: s.id, ref: s.ref, cliente: s.cliente, onGuardado: () => { cargar(origen); onCambio?.(); } });
    }

    cuerpo.addEventListener('click', (ev) => {
        const a = ev.target.closest('[data-acc]');
        const tarj = ev.target.closest('.kb-card');
        if (a?.dataset.acc === 'mas') {
            cargar(a.closest('.kb-col').dataset.col, true);
            return;
        }
        if (!tarj) return;
        ev.preventDefault();
        const id = Number(tarj.dataset.id);
        const hallada = buscar(id);
        if (!hallada) return;
        const acc = a?.dataset.acc ?? 'editar'; // un clic en cualquier parte de la tarjeta abre la solicitud
        if (acc === 'urgencia') menuUrgencia(a, hallada.s, hallada.origen);
        else if (acc === 'menu') menu(a, hallada.s, hallada.origen);
        else if (acc === 'seguimiento') seguimientoRapido(a, hallada.s, hallada.origen);
        else ficha.abrir(id);
    });
    document.addEventListener('click', (ev) => { if (!ev.target.closest('.kb-flotante, [data-acc=menu], [data-acc=seguimiento], [data-acc=urgencia]')) cerrarFlotantes(); });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrarFlotantes(); });
    cuerpo.addEventListener('scroll', cerrarFlotantes, true);

    // ------------------------------------------------------------ filtros de la barra

    let espera;
    raiz.querySelector('[data-kb-q]').addEventListener('input', (ev) => {
        clearTimeout(espera);
        espera = setTimeout(() => { filtros.q = ev.target.value.trim(); cargarTodas(); }, 300);
    });
    const selEstado = raiz.querySelector('[data-kb-estado]');
    const aplicarEstados = () => { filtros.estados = selEstado.value ? [Number(selEstado.value)] : grupo; cargarTodas(); };
    selEstado.addEventListener('change', aplicarEstados);

    // Fecha de creación: Todo, último mes, 3 o 6 meses, o un rango propio.
    const grupoFechas = raiz.querySelector('[data-kb-fechas]');
    const cajaRango = raiz.querySelector('[data-kb-rango]');
    const inDesde = raiz.querySelector('[data-kb-desde]');
    const inHasta = raiz.querySelector('[data-kb-hasta]');
    const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const aplicarRango = () => { filtros.desde = inDesde.value; filtros.hasta = inHasta.value; cargarTodas(); };
    grupoFechas.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-rango]');
        if (!b) return;
        grupoFechas.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
        const v = b.dataset.rango;
        cajaRango.hidden = v !== 'rango';
        if (v === 'rango') { inDesde.focus(); aplicarRango(); return; }
        if (v) { const d = new Date(); d.setMonth(d.getMonth() - Number(v)); filtros.desde = iso(d); } else filtros.desde = '';
        filtros.hasta = '';
        cargarTodas();
    });
    inDesde.addEventListener('change', aplicarRango);
    inHasta.addEventListener('change', aplicarRango);

    // Configuración de las columnas (administradores): título, estados y condiciones de cada grupo; cae en la primera que cumple.
    raiz.querySelector('[data-kb-config]')?.addEventListener('click', async () => {
        const filas = config.tablero.columnas.map((c) => ({ ...c }));
        const num = (v) => (v === null || v === undefined ? '' : v);
        const pintarFila = (c, i) => `<fieldset class="kb-cfg-col" data-i="${i}" data-clave="${c.clave}" style="--c:${c.color}">
            <legend><span class="kb-cfg-n">${i + 1}</span><input type="text" data-f="titulo" value="${esc(c.titulo)}" maxlength="40" aria-label="Nombre del grupo">
                <button type="button" data-mv="-1" title="Subir (tiene prioridad)"${i === 0 ? ' disabled' : ''}><i class="fa-solid fa-arrow-up"></i></button>
                <button type="button" data-mv="1" title="Bajar"${i === filas.length - 1 ? ' disabled' : ''}><i class="fa-solid fa-arrow-down"></i></button></legend>
            <div class="kb-cfg-fila"><label>Mora desde (días)<input type="number" min="0" data-f="moraMin" value="${num(c.moraMin)}" placeholder="sin mínimo"></label>
                <label>Mora hasta (días)<input type="number" min="0" data-f="moraMax" value="${num(c.moraMax)}" placeholder="sin máximo"></label>
                <label>Próximo contacto<select data-f="contacto">
                    ${[['cualquiera', 'Cualquiera'], ['vencido', 'Vencido o para hoy'], ['no_vencido', 'Sin vencer']].map(([v, n]) => `<option value="${v}"${c.contacto === v ? ' selected' : ''}>${n}</option>`).join('')}
                </select></label></div>
            <div class="kb-cfg-est"><span>Estados (ninguno = todos):</span>${config.tablero.estadosTablero.map((e) => `<label><input type="checkbox" data-est="${e.id}"${c.estados.includes(e.id) ? ' checked' : ''}>${esc(e.nombre)}</label>`).join('')}</div>
        </fieldset>`;
        const leer = (cont) => [...cont.querySelectorAll('.kb-cfg-col')].map((f) => ({
            clave: f.dataset.clave,
            color: filas.find((x) => x.clave === f.dataset.clave).color,
            titulo: f.querySelector('[data-f=titulo]').value.trim(),
            moraMin: f.querySelector('[data-f=moraMin]').value,
            moraMax: f.querySelector('[data-f=moraMax]').value,
            contacto: f.querySelector('[data-f=contacto]').value,
            estados: [...f.querySelectorAll('[data-est]:checked')].map((x) => Number(x.dataset.est)),
        }));
        const r = await Swal.fire({
            title: 'Configurar columnas',
            width: 760,
            showCancelButton: true,
            confirmButtonText: 'Guardar',
            cancelButtonText: 'Cancelar',
            showDenyButton: true,
            denyButtonText: 'Restablecer',
            html: `<p class="kb-cfg-ayuda">Cada solicitud cae en la <b>primera</b> columna (de arriba abajo) cuyas condiciones cumple. Usa las flechas para dar prioridad.</p><div class="kb-cfg" data-cfg>${filas.map(pintarFila).join('')}</div>`,
            didOpen: (el) => {
                const cont = el.querySelector('[data-cfg]');
                cont.addEventListener('click', (ev) => {
                    const b = ev.target.closest('[data-mv]');
                    if (!b) return;
                    const actual = leer(cont);
                    const i = Number(b.closest('.kb-cfg-col').dataset.i);
                    const j = i + Number(b.dataset.mv);
                    [actual[i], actual[j]] = [actual[j], actual[i]];
                    filas.splice(0, filas.length, ...actual.map((x) => ({ ...x, estados: x.estados })));
                    cont.innerHTML = filas.map(pintarFila).join('');
                });
            },
            preConfirm: () => leer(Swal.getHtmlContainer().querySelector('[data-cfg]')),
        });
        if (r.isDismissed) return;
        try {
            await pedir(config.tablero.columnasUrl, 'PUT', { columnas: r.isDenied ? ['atrasadas', 'atencion', 'contactar', 'aldia'].map((k) => ({ clave: k })) : r.value });
            location.reload();
        } catch (err) {
            toast.fire({ icon: 'error', title: err.message });
        }
    });

    let iniciado = false;
    return {
        /** Se llama al mostrar el tablero; la primera vez trae los datos. */
        mostrar() { if (!iniciado) { iniciado = true; cargarTodas(); } },
        recargar: cargarTodas,
        /** Las tarjetas de estadísticas dejan ver solo los estados de su etapa ('' = todos). */
        soloEtapa(clave) {
            grupo = ETAPAS[clave] ?? []; // sin etapa (o las órdenes de compra, que no se trabajan aquí) = todas
            selEstado.value = '';
            filtros.estados = grupo;
            if (iniciado) cargarTodas();
        },
    };
}
