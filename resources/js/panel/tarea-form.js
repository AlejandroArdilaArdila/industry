// Formulario de nueva tarea del programador (panel lateral) y detalle de una tarea.
// Mínimo diligenciamiento: dictado por voz + IA que llena el formulario, relacionar con cualquier elemento por búsqueda,
// atajos de fecha/hora y, al guardar, un siguiente paso claro.
import Swal from 'sweetalert2';
import { toast, normalizar } from './ficha-modal.js';
import { esc, isoLocal } from './dashboard-util.js';

const Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;
const JSON_HEADERS = (csrf) => ({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' });
const fechaLarga = (iso) => new Date(`${iso.slice(0, 10)}T${iso.slice(11, 16) || '00:00'}`).toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' });

/** Preloading sobre todo el panel: nada se puede tocar ni cerrar hasta que se llame a lo que devuelve. */
function velar(modal, mensaje) {
    modal.guardando = true;
    const velo = document.createElement('div');
    velo.className = 'tf-velo';
    velo.setAttribute('role', 'status');
    velo.innerHTML = `<div class="tf-velo-caja"><span class="tf-spin"></span><b>${esc(mensaje)}</b><small>No cierres esta ventana</small></div>`;
    modal.root.classList.add('relative');
    modal.root.appendChild(velo);
    return () => { modal.guardando = false; velo.remove(); };
}

export function iniciarTareaForm(modal) {
    const root = modal.root;
    const form = root.querySelector('form[data-step="tarea"]');
    const cat = modal.catalogos;
    const $ = (s) => form.querySelector(s);
    const yo = Number(root.dataset.yo);
    const csrf = modal.opts.csrfToken;
    const nombreTipo = (t) => $(`[data-tipos] [data-tipo="${t}"]`)?.textContent.trim() ?? t;

    /** Cambia un campo y avisa al modal (estado «cambios sin guardar»). */
    const poner = (name, valor) => {
        const el = form.elements[name];
        if (!el) return;
        el.value = valor;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    };

    // ------------------------------------------------------------ voz
    const dictar = (btn, destino, alTerminar) => {
        if (!Reconocimiento) { toast.fire({ icon: 'info', title: 'Tu navegador no permite dictar. Usa Chrome o Edge.' }); return; }
        if (btn._rec) { btn._rec.stop(); return; }
        const rec = new Reconocimiento();
        rec.lang = 'es-CO'; rec.interimResults = true; rec.continuous = false;
        const base = destino.value ? `${destino.value.trimEnd()} ` : '';
        rec.onresult = (ev) => {
            destino.value = base + [...ev.results].map((r) => r[0].transcript).join('');
            destino.dispatchEvent(new Event('input', { bubbles: true }));
        };
        rec.onerror = (ev) => { if (ev.error === 'not-allowed') toast.fire({ icon: 'error', title: 'Permite el micrófono en el navegador (requiere https).' }); };
        rec.onend = () => { btn.classList.remove('on'); btn._rec = null; alTerminar?.(); };
        btn._rec = rec; btn.classList.add('on');
        rec.start();
    };
    if (!Reconocimiento) {
        form.querySelectorAll('[data-dictar]').forEach((b) => b.classList.add('hidden'));
        $('[data-ia-ayuda]').textContent = 'Escribe lo que necesitas y la IA llena el formulario (para dictar usa Chrome o Edge).';
        $('[data-ia-mic]').classList.add('opacity-40');
    }
    form.querySelectorAll('[data-dictar]').forEach((b) => b.addEventListener('click', () => dictar(b, $(b.dataset.dictar))));

    // ------------------------------------------------------------ fecha y hora
    const fecha = $('#tf_fecha'), hora = $('#tf_hora');
    const marcarFecha = () => form.querySelectorAll('[data-atajos] [data-dias]').forEach((b) => {
        const d = new Date(); d.setDate(d.getDate() + +b.dataset.dias);
        b.classList.toggle('on', fecha.value === isoLocal(d));
    });
    const marcarHora = () => form.querySelectorAll('[data-horas] [data-hora]').forEach((b) => b.classList.toggle('on', hora.value === b.dataset.hora));
    form.querySelectorAll('[data-atajos] [data-dias]').forEach((b) => b.addEventListener('click', () => {
        const d = new Date(); d.setDate(d.getDate() + +b.dataset.dias);
        poner('fecha', isoLocal(d)); marcarFecha();
    }));
    form.querySelectorAll('[data-horas] [data-hora]').forEach((b) => b.addEventListener('click', () => { poner('hora', b.dataset.hora); marcarHora(); }));
    fecha.addEventListener('change', marcarFecha); hora.addEventListener('change', marcarHora);
    marcarFecha(); marcarHora();

    // ------------------------------------------------------------ prioridad, recurrencia, quién cambia el estado
    const grupo = (sel, attr, campo) => {
        const pintar = (v) => form.querySelectorAll(`${sel} [${attr}]`).forEach((b) => b.classList.toggle('on', b.getAttribute(attr) === String(v)));
        form.querySelectorAll(`${sel} [${attr}]`).forEach((b) => b.addEventListener('click', () => { poner(campo, b.getAttribute(attr)); pintar(b.getAttribute(attr)); }));
        return pintar;
    };
    const pintarPrio = grupo('[data-prioridades]', 'data-prio', 'prioridad');
    const pintarRec = grupo('[data-recurrencias]', 'data-rec', 'recurrencia');
    grupo('[data-estado-cambio]', 'data-sc', 'state_change');
    $('[data-teams]')?.addEventListener('change', (ev) => poner('teams', ev.target.checked ? '1' : '0'));

    // ------------------------------------------------------------ relacionar con un elemento
    const buscarWrap = $('[data-rel-buscar]'), relQ = $('[data-rel-q]'), relLista = $('[data-rel-lista]'), elegido = $('[data-rel-elegido]');
    let tipo = '', secuencia = 0, espera, tituloAuto = '', ultimos = [];

    const tarjeta = (x, i) => `<button type="button" data-el="${i}" class="act-doc" style="--c:var(--primary);--k:${Math.min(i, 8)}">
        <span class="act-doc-ico"><i class="fa-solid ${x.icono}"></i></span>
        <span class="min-w-0 flex-1 text-left"><b class="block truncate text-sm">${esc(x.ref)}</b><span class="block truncate text-xs text-muted-foreground">${esc(x.empresa || x.titulo || x.tipoNombre)}</span></span>
        <span class="text-right text-[11px] text-muted-foreground">${esc(x.estado || x.tipoNombre)}</span></button>`;
    const pintarLista = (items) => {
        ultimos = items;
        relLista.innerHTML = items.length ? items.map(tarjeta).join('') : '<p class="rounded-xl border border-dashed border-border p-4 text-center text-xs text-muted-foreground">No encontré resultados. Prueba con otra referencia o con el nombre del cliente.</p>';
    };
    async function buscar() {
        if (!tipo) return;
        const n = ++secuencia;
        relLista.innerHTML = Array(3).fill('<div class="h-[58px] animate-pulse rounded-xl bg-muted"></div>').join('');
        try {
            const res = await fetch(`${root.dataset.elementosUrl}?${new URLSearchParams({ tipo, q: relQ.value })}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const { items } = await res.json();
            if (n === secuencia) pintarLista(items);
        } catch (e) { if (n === secuencia) relLista.innerHTML = `<p class="text-xs text-destructive">No se pudo buscar: ${esc(e.message)}</p>`; }
    }
    function elegirTipo(t) {
        tipo = t;
        poner('tipo', t); poner('ref', '');
        form.querySelectorAll('[data-tipos] [data-tipo]').forEach((b) => b.classList.toggle('on', b.dataset.tipo === t));
        elegido.classList.add('hidden'); elegido.innerHTML = '';
        buscarWrap.classList.toggle('hidden', !t);
        relLista.innerHTML = '';
        if (t) {
            relQ.value = '';
            relQ.placeholder = ['cliente', 'producto'].includes(t) ? `Escribe el nombre del ${t}…` : 'Busca por referencia o por cliente…';
            buscar(); setTimeout(() => relQ.focus({ preventScroll: true }), 50);
        }
    }
    function elegirElemento(x) {
        tipo = x.tipo;
        form.querySelectorAll('[data-tipos] [data-tipo]').forEach((b) => b.classList.toggle('on', b.dataset.tipo === x.tipo));
        poner('tipo', x.tipo); poner('ref', x.id);
        buscarWrap.classList.add('hidden');
        elegido.classList.remove('hidden');
        elegido.innerHTML = `<div class="act-doc" style="--c:var(--primary);cursor:default"><span class="act-doc-ico"><i class="fa-solid ${x.icono}"></i></span>
            <span class="min-w-0 flex-1 text-left"><b class="block truncate text-sm">${esc(x.ref)}</b><span class="block truncate text-xs text-muted-foreground">${esc(x.tipoNombre)}${x.empresa && x.empresa !== x.ref ? ` · ${esc(x.empresa)}` : ''}</span></span>
            <button type="button" data-rel-cambiar class="text-xs font-semibold text-primary hover:underline">Cambiar</button>
            <button type="button" data-rel-quitar class="ml-2 text-xs font-semibold text-muted-foreground hover:text-destructive" title="Quitar"><i class="fa-solid fa-xmark"></i></button></div>`;
        // Asunto sugerido (solo si el usuario no escribió uno propio).
        const titulo = form.elements.titulo;
        if (!titulo.value.trim() || titulo.value === tituloAuto) {
            tituloAuto = `Seguimiento de ${x.tipoNombre.toLowerCase()} ${x.ref}`.slice(0, 120);
            poner('titulo', tituloAuto);
        }
    }
    form.querySelectorAll('[data-tipos] [data-tipo]').forEach((b) => b.addEventListener('click', () => elegirTipo(b.dataset.tipo)));
    relQ.addEventListener('input', () => { clearTimeout(espera); espera = setTimeout(buscar, 280); });
    relLista.addEventListener('click', (e) => { const b = e.target.closest('[data-el]'); if (b) elegirElemento(ultimos[Number(b.dataset.el)]); });
    elegido.addEventListener('click', (e) => {
        if (e.target.closest('[data-rel-cambiar]')) elegirTipo(tipo);
        else if (e.target.closest('[data-rel-quitar]')) elegirTipo('');
    });

    // ------------------------------------------------------------ integrantes
    const usuarios = cat.usuarios ?? [];
    const seleccion = new Map();
    const chips = $('[data-int-chips]'), intQ = $('[data-int-q]'), intLista = $('[data-int-lista]');
    const pintarIntegrantes = () => {
        form.querySelectorAll('input[name="integrantes[]"]').forEach((i) => i.remove());
        chips.innerHTML = [...seleccion.values()].map((u) => `<span class="inline-flex items-center gap-2 rounded-full bg-muted py-1 pr-2.5 pl-1 text-xs font-medium"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-primary-foreground">${esc(u.nombre.charAt(0).toUpperCase())}</span>${esc(u.nombre)}${u.id === yo ? '<span class="text-muted-foreground">(tú)</span>' : `<button type="button" data-int-quitar="${u.id}" class="text-muted-foreground hover:text-destructive" title="Quitar"><i class="fa-solid fa-xmark"></i></button>`}</span>`).join('');
        [...seleccion.keys()].filter((id) => id !== yo).forEach((id) => {
            const h = document.createElement('input'); h.type = 'hidden'; h.name = 'integrantes[]'; h.value = id; form.appendChild(h);
        });
        form.dispatchEvent(new Event('input', { bubbles: true }));
    };
    const agregar = (u) => { if (u) { seleccion.set(u.id, u); pintarIntegrantes(); } };
    const yoU = usuarios.find((u) => u.id === yo);
    if (yoU) seleccion.set(yo, yoU);
    const mostrarUsuarios = () => {
        const t = normalizar(intQ.value);
        const res = usuarios.filter((u) => !seleccion.has(u.id) && (!t || normalizar(u.nombre).includes(t))).slice(0, 8);
        intLista.classList.toggle('hidden', !res.length);
        intLista.innerHTML = res.map((u) => `<button type="button" data-int-add="${u.id}" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-sm hover:bg-muted"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary/15 text-[10px] font-bold text-primary">${esc(u.nombre.charAt(0).toUpperCase())}</span>${esc(u.nombre)}</button>`).join('');
    };
    intQ.addEventListener('input', mostrarUsuarios); intQ.addEventListener('focus', mostrarUsuarios);
    intLista.addEventListener('mousedown', (e) => { const b = e.target.closest('[data-int-add]'); if (b) { e.preventDefault(); agregar(usuarios.find((u) => u.id === Number(b.dataset.intAdd))); intQ.value = ''; mostrarUsuarios(); } });
    intQ.addEventListener('blur', () => setTimeout(() => intLista.classList.add('hidden'), 120));
    chips.addEventListener('click', (e) => { const b = e.target.closest('[data-int-quitar]'); if (b) { seleccion.delete(Number(b.dataset.intQuitar)); pintarIntegrantes(); } });
    pintarIntegrantes();

    // ------------------------------------------------------------ IA: dictar / escribir → formulario
    const iaTexto = $('[data-ia-texto]'), iaEnviar = $('[data-ia-enviar]'), iaRes = $('[data-ia-resultado]');
    const aviso = (html, tipoAviso = 'ok') => { iaRes.className = `mt-2 text-xs ${tipoAviso === 'error' ? 'text-destructive' : 'text-muted-foreground'}`; iaRes.innerHTML = html; };

    async function interpretar() {
        const texto = iaTexto.value.trim();
        if (!texto) { iaTexto.focus(); return; }
        iaEnviar.disabled = true;
        iaEnviar.querySelector('i').className = 'fa-solid fa-circle-notch fa-spin';
        aviso('Entendiendo lo que necesitas…');
        try {
            const res = await fetch(modal.root.dataset.interpretarUrl, { method: 'POST', headers: JSON_HEADERS(csrf), body: JSON.stringify({ texto }) });
            const r = await res.json().catch(() => ({}));
            if (!res.ok || !r.ok) throw new Error(r.errors ? Object.values(r.errors)[0][0] : r.message || `Error ${res.status}`);
            aplicar(r);
        } catch (e) {
            aviso(`<i class="fa-solid fa-triangle-exclamation"></i> ${esc(e.message)}`, 'error');
        } finally {
            iaEnviar.disabled = false;
            iaEnviar.querySelector('i').className = 'fa-solid fa-wand-magic-sparkles';
        }
    }

    function aplicar(r) {
        const llenos = [];
        if (r.titulo) { tituloAuto = ''; poner('titulo', r.titulo); llenos.push('asunto'); }
        if (r.descripcion) { poner('descripcion', r.descripcion); llenos.push('descripción'); }
        if (r.fecha) { poner('fecha', r.fecha); marcarFecha(); llenos.push('fecha'); }
        if (r.hora) { poner('hora', r.hora); marcarHora(); llenos.push('hora'); }
        if (r.prioridad) { poner('prioridad', r.prioridad); pintarPrio(r.prioridad); llenos.push('prioridad'); }
        if (r.recurrencia && r.recurrencia !== 1) { poner('recurrencia', r.recurrencia); pintarRec(r.recurrencia); llenos.push('recurrencia'); }
        (r.integrantes ?? []).forEach((u) => { agregar(u); llenos.push(`integrante ${u.nombre.split(' ')[0]}`); });
        if (r.teams !== null && form.elements.teams) { poner('teams', r.teams ? '1' : '0'); const c = $('[data-teams]'); if (c) c.checked = !!r.teams; }
        let extra = '';
        if (r.elemento) { elegirElemento(r.elemento); llenos.push(r.elemento.ref); }
        else if (r.candidatos?.length) {
            elegirTipo(r.candidatos[0].tipo);
            pintarLista(r.candidatos);
            extra = ' <b>Elige el elemento correcto</b> en la lista de abajo.';
        }
        if (r.noEncontrados?.length) extra += ` No encontré a: ${r.noEncontrados.map(esc).join(', ')}.`;
        aviso(llenos.length ? `<i class="fa-solid fa-circle-check text-emerald-600"></i> Llené: ${llenos.map(esc).join(', ')}. Revisa y pulsa <b>Crear tarea</b>.${extra}` : `No pude sacar datos de ese texto.${extra}`);
        form.dispatchEvent(new Event('input', { bubbles: true }));
    }

    iaEnviar.addEventListener('click', interpretar);
    iaTexto.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); interpretar(); } });
    $('[data-ia-mic]').addEventListener('click', (e) => dictar(e.currentTarget, iaTexto, () => { if (iaTexto.value.trim()) interpretar(); }));

    // ------------------------------------------------------------ elemento precargado (abierto desde un documento)
    if (cat.previo) elegirElemento(cat.previo);
    setTimeout(() => form.elements.titulo.focus({ preventScroll: true }), 450);
}

/** Guarda la tarea y muestra el siguiente paso. */
export async function guardarTarea(modal, form) {
    if (modal.guardando) return; // evita doble envío (clic repetido o Ctrl+S)
    const boton = form.querySelector('[data-step-save]');
    modal.limpiarErrores(form);
    modal.cargando(boton, true);
    const quitarVelo = velar(modal, 'Creando tu tarea…');
    const body = new FormData(form);
    if (!body.get('tipo')) { body.delete('tipo'); body.delete('ref'); }
    try {
        const res = await fetch(modal.root.dataset.storeUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': modal.opts.csrfToken, 'X-Requested-With': 'XMLHttpRequest' }, body });
        const json = await res.json().catch(() => ({}));
        if (res.status === 422) { modal.mostrarErrores(form, json.errors ?? {}); toast.fire({ icon: 'error', title: 'Revisa los campos marcados' }); return; }
        if (!res.ok || json.ok !== true) throw new Error(json.message || `HTTP ${res.status}`);

        modal.opts.onSaved?.(json);
        modal.forms = []; // ya no hay cambios pendientes
        const ms = json.microsoft ?? {};
        const nInt = form.querySelectorAll('input[name="integrantes[]"]').length + 1;
        const chips = [
            ms.calendario ? '<span class="inline-flex items-center gap-1.5 rounded-full bg-[#0078d4]/10 px-2.5 py-1 font-semibold text-[#0078d4]"><i class="fa-brands fa-microsoft"></i> En tu Outlook</span>' : '',
            ms.tarea ? '<span class="inline-flex items-center gap-1.5 rounded-full bg-[#0078d4]/10 px-2.5 py-1 font-semibold text-[#0078d4]"><i class="fa-solid fa-list-check"></i> En To Do</span>' : '',
            ms.teams ? `<a href="${esc(ms.teams)}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-full bg-[#5b5fc7]/10 px-2.5 py-1 font-semibold text-[#5b5fc7]"><i class="fa-solid fa-video"></i> Reunión de Teams</a>` : '',
        ].join('');
        const scroll = modal.root.querySelector('[data-ficha-scroll]');
        scroll.innerHTML = `<div class="flex min-h-full flex-col items-center justify-center gap-4 p-8 text-center">
            <span class="tf-ok"><i class="fa-solid fa-check"></i></span>
            <div><h3 class="text-lg font-semibold">Tarea creada</h3><p class="mt-1 text-sm">${esc(body.get('titulo'))}</p>
                <p class="mt-1 text-sm text-muted-foreground capitalize">${esc(fechaLarga(json.programada))} · ${esc(json.programada.slice(11, 16))}${nInt > 1 ? ` · ${nInt} integrantes` : ''}</p></div>
            <div class="flex flex-wrap justify-center gap-2 text-xs">${chips}</div>
            <p class="text-xs text-muted-foreground">¿Qué sigue?</p>
            <div class="flex flex-wrap justify-center gap-2">
                <button type="button" data-otra class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground transition hover:opacity-90"><i class="fa-solid fa-plus"></i> Crear otra tarea</button>
                <button type="button" data-ver class="inline-flex h-10 items-center gap-2 rounded-lg border border-border px-4 text-sm font-medium transition hover:bg-muted"><i class="fa-solid fa-eye"></i> Ver la tarea</button>
                <button type="button" data-ficha-close class="inline-flex h-10 items-center rounded-lg px-3 text-sm font-medium text-muted-foreground transition hover:bg-muted">Listo</button>
            </div></div>`;
        scroll.querySelector('[data-otra]').addEventListener('click', () => modal.abrirNueva());
        scroll.querySelector('[data-ver]').addEventListener('click', () => modal.abrirTarea(json.id));
        scroll.querySelector('[data-ficha-close]').addEventListener('click', () => modal.cerrar(true));
        toast.fire({ icon: 'success', title: json.message });
    } catch (e) {
        console.error(e);
        Swal.fire({ icon: 'error', title: 'No se pudo crear la tarea', text: e.message });
    } finally {
        quitarVelo();
        modal.cargando(boton, false);
    }
}

/** Detalle de una tarea: completar, reabrir, posponer y abrir el elemento relacionado. */
export function iniciarTareaDetalle(modal) {
    const root = modal.root;
    const csrf = modal.opts.csrfToken;
    const put = async (url, datos) => {
        const res = await fetch(url, { method: 'PUT', headers: JSON_HEADERS(csrf), body: JSON.stringify(datos) });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json.errors ? Object.values(json.errors)[0][0] : json.message || `HTTP ${res.status}`);
        return json;
    };
    const accion = async (fn, mensaje = 'Guardando…') => {
        if (modal.guardando) return false;
        const quitarVelo = velar(modal, mensaje);
        try { const j = await fn(); toast.fire({ icon: 'success', title: j.message }); modal.opts.onSaved?.(j); return true; }
        catch (e) { Swal.fire({ icon: 'error', title: 'No se pudo', text: e.message }); return false; }
        finally { quitarVelo(); }
    };

    root.querySelector('[data-tarea-eliminar]')?.addEventListener('click', async () => {
        const { isConfirmed } = await Swal.fire({
            title: '¿Eliminar esta tarea?', icon: 'warning', showCancelButton: true, reverseButtons: true, confirmButtonColor: '#dc2626',
            confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar',
            text: 'Se quitará para todos los integrantes y también de su Outlook y Microsoft To Do. No se puede deshacer.',
        });
        if (!isConfirmed) return;
        const ok = await accion(async () => {
            const res = await fetch(root.dataset.eliminarUrl, { method: 'DELETE', headers: JSON_HEADERS(csrf) });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(json.message || `HTTP ${res.status}`);
            return json;
        }, 'Eliminando la tarea…');
        if (ok) modal.cerrar(true);
    });
    root.querySelector('[data-tarea-rechazar]')?.addEventListener('click', async () => {
        const { isConfirmed } = await Swal.fire({
            title: '¿Rechazar esta tarea?', icon: 'question', showCancelButton: true, reverseButtons: true, confirmButtonColor: '#9333ea',
            confirmButtonText: 'Sí, rechazar', cancelButtonText: 'Cancelar',
            text: 'Dejarás de ser integrante. Quien la creó y los demás la conservan, y se quitará de tu Outlook y To Do.',
        });
        if (!isConfirmed) return;
        const ok = await accion(async () => {
            const res = await fetch(root.dataset.rechazarUrl, { method: 'POST', headers: JSON_HEADERS(csrf) });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(json.message || `HTTP ${res.status}`);
            return json;
        }, 'Rechazando la tarea…');
        if (ok) modal.cerrar(true);
    });
    root.querySelector('[data-abrir-doc]')?.addEventListener('click', (e) => { const [m, id] = e.currentTarget.dataset.abrirDoc.split(':'); modal.abrirDocumento(m, id); });
    root.querySelector('[data-tarea-hecho]')?.addEventListener('click', async () => { if (await accion(() => put(root.dataset.estadoUrl, { cerrada: true }), 'Completando la tarea…')) modal.cerrar(true); });
    root.querySelector('[data-tarea-reabrir]')?.addEventListener('click', async () => { if (await accion(() => put(root.dataset.estadoUrl, { cerrada: false }), 'Reabriendo la tarea…')) modal.abrirTarea(root.dataset.tareaDetalle); });
    const posponer = async (iso) => { if (await accion(() => put(root.dataset.posponerUrl, { fecha: iso }), 'Reprogramando la tarea…')) modal.abrirTarea(root.dataset.tareaDetalle); };
    root.querySelectorAll('[data-posponer]').forEach((b) => b.addEventListener('click', () => {
        const d = new Date(); d.setDate(d.getDate() + +b.dataset.posponer); posponer(isoLocal(d));
    }));
    root.querySelector('[data-posponer-fecha]')?.addEventListener('change', (e) => { if (e.target.value) posponer(e.target.value); });
}
