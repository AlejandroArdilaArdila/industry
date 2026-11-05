// Tablero (kanban) de solicitudes por urgencia: Atrasadas, Atención, Por contactar y Al día (días de mora).
// Arrastrar a «Por contactar» programa el contacto para hoy; sacarla de allí a otra columna lo reprograma.
// El estado real de la solicitud (activa, en cotización, importación…) se cambia desde el menú de la tarjeta.
// Las columnas cargan de a poco con «Ver más».
import '../../css/solicitudes-tablero.css';
import Swal from 'sweetalert2';
import { toast } from '../panel/ficha-modal.js';
import { esc } from '../panel/dashboard-util.js';

const MORA = {
    verde: { txt: 'Al día', hasta: 15 },
    ambar: { txt: 'Atención', hasta: 30 },
    rojo: { txt: 'Atrasada', hasta: Infinity },
};
const nivelMora = (d) => (d <= MORA.verde.hasta ? 'verde' : d <= MORA.ambar.hasta ? 'ambar' : 'rojo');
// Color de la etiqueta de estado de la solicitud.
const COLOR_ESTADO = { 1: '#2563eb', 2: '#0891b2', 3: '#0891b2', 6: '#0891b2', 4: '#7c3aed', 5: '#059669' };

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
    const filtros = { q: '', estados: [] };
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
        const nivel = nivelMora(s.mora);
        const vencido = s.contactoVencido;
        const ultimo = s.ultimoSeguimiento;
        return `<article class="kb-card mora-${nivel}" draggable="true" data-id="${s.id}">
            <div class="kb-card-top">
                <a href="#" class="kb-ref" data-acc="editar" title="Abrir la solicitud"><i class="fa-solid fa-inbox"></i>${esc(s.ref)}</a>
                <span class="kb-mora" title="Días desde la creación">${esc(MORA[nivel].txt)} · ${s.mora} d</span>
                <button type="button" class="kb-mas" data-acc="menu" title="Más acciones" aria-label="Más acciones"><i class="fa-solid fa-ellipsis-vertical"></i></button>
            </div>
            <div class="kb-cliente"><i class="fa-solid fa-building"></i><span>${esc(s.cliente || 'Sin cliente')}</span></div>
            <p class="kb-asunto" title="${esc(s.asunto)}">${esc(s.asunto) || '<em>Sin asunto</em>'}</p>
            <div class="kb-meta">
                <span class="kb-chip estado" style="--e:${COLOR_ESTADO[s.estado] ?? '#64748b'}" title="Estado de la solicitud">${esc(s.estadoNombre)}</span>
                ${s.productos ? `<span class="kb-chip" title="Productos"><i class="fa-solid fa-box"></i>${s.productos}</span>` : ''}
                <span class="kb-chip ${s.correoEnviado ? 'ok' : ''}" title="${s.correoEnviado ? 'Correo enviado al cliente' : 'Aún sin correo al cliente'}"><i class="fa-solid ${s.correoEnviado ? 'fa-envelope-circle-check' : 'fa-envelope'}"></i></span>
                ${s.ultimaRespuesta ? `<span class="kb-chip" title="Última respuesta"><i class="fa-solid fa-reply"></i>${fechaCorta(s.ultimaRespuesta)}</span>` : ''}
            </div>
            ${ultimo ? `<p class="kb-seg" title="${esc(ultimo.texto)}"><i class="fa-solid fa-comment-dots"></i><span>${esc(ultimo.texto)}</span>${ultimo.fecha ? `<time>${fechaCorta(ultimo.fecha)}</time>` : ''}</p>` : '<p class="kb-seg vacio"><i class="fa-regular fa-comment"></i><span>Sin seguimientos</span></p>'}
            <footer class="kb-pie">
                <span class="kb-avatar" title="${esc(s.creador || '')}">${esc(iniciales(s.creador))}</span>
                <span class="kb-prox ${vencido ? 'vencido' : ''}" title="Próximo contacto">
                    <i class="fa-regular ${vencido ? 'fa-bell' : 'fa-calendar'}"></i>${s.proximoContacto ? (vencido ? `Contactar · ${fechaCorta(s.proximoContacto)}` : fechaCorta(s.proximoContacto)) : 'Sin programar'}
                </span>
                <button type="button" class="kb-seguir" data-acc="seguimiento" title="Registrar seguimiento"><i class="fa-solid fa-comment-medical"></i>Seguimiento</button>
            </footer>
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
            : `<div class="kb-vacio"><i class="fa-solid ${def.icono}"></i><span>${filtros.q || filtros.estados.length ? 'Nada con esos filtros' : 'Sin solicitudes'}</span></div>`;
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

    /** Observación + próximo contacto en un solo paso, sin salir del tablero. */
    function seguimientoRapido(ancla, s, origen) {
        const f = flotante(ancla, `
            <form class="kb-form">
                <b>Seguimiento · ${esc(s.ref)}</b>
                <textarea name="observacion" rows="3" maxlength="5000" placeholder="¿Qué pasó con el cliente?" required></textarea>
                <small>Próximo contacto</small>
                <div class="kb-dias">
                    <button type="button" data-dias="">Sin fecha</button><button type="button" data-dias="1">Mañana</button>
                    <button type="button" data-dias="3">3 días</button><button type="button" data-dias="7">1 semana</button>
                </div>
                <input type="date" name="fecha" min="${sumarDias(0)}">
                <div class="kb-form-pie"><button type="button" data-cancelar>Cancelar</button><button type="submit">Guardar</button></div>
            </form>`);
        const form = f.querySelector('form');
        const fecha = form.elements.fecha;
        form.querySelector('textarea').focus();
        f.querySelectorAll('[data-dias]').forEach((b) => b.addEventListener('click', () => {
            fecha.value = b.dataset.dias === '' ? '' : sumarDias(Number(b.dataset.dias));
            f.querySelectorAll('[data-dias]').forEach((x) => x.classList.toggle('on', x === b));
        }));
        f.querySelector('[data-cancelar]').addEventListener('click', cerrarFlotantes);
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const obs = form.elements.observacion.value.trim();
            if (!obs) return;
            const envio = form.querySelector('[type=submit]');
            envio.disabled = true;
            try {
                const r = await pedir(config.tablero.seguimientoUrl, 'POST', {
                    modulo: 'solicitud', id: s.id, observacion: obs,
                    ...(fecha.value ? { programar: true, fecha: fecha.value, hora: '08:00', titulo: `Seguimiento ${s.ref}` } : {}),
                });
                cerrarFlotantes();
                toast.fire({ icon: 'success', title: r.message });
                cargar(origen);
                onCambio?.();
            } catch (err) {
                envio.disabled = false;
                toast.fire({ icon: 'error', title: err.message });
            }
        });
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
        if (acc === 'menu') menu(a, hallada.s, hallada.origen);
        else if (acc === 'seguimiento') seguimientoRapido(a, hallada.s, hallada.origen);
        else ficha.abrir(id);
    });
    document.addEventListener('click', (ev) => { if (!ev.target.closest('.kb-flotante, [data-acc=menu], [data-acc=seguimiento]')) cerrarFlotantes(); });
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
