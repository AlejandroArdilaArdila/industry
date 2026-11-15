// Seguimiento de una solicitud: bitácora + próximo contacto + (opcional) correo al cliente, con borrador de la IA.
// Es el equivalente al formulario de seguimiento de Dolibarr, en un diálogo. Se abre desde el tablero.
import { esc } from '../panel/dashboard-util.js';
import { toast as toastEstandar } from '../panel/ficha-modal.js';

const hoy = (n = 0) => {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};
const reemplazos = (txt, ctx, contacto) => String(txt ?? '')
    .replaceAll('__REF__', ctx.solicitud.ref)
    .replaceAll('__CLIENTE__', ctx.solicitud.cliente ?? '')
    .replaceAll('__CONTACTO__', contacto ?? '');

/**
 * @param {{ s: {id: number, ref: string, cliente?: string}, urls: {ctx: string, guardar: string, ia: string}, pedir: Function, toast: any, onGuardado?: Function }} o
 */
export async function abrirSeguimiento({ s, urls, pedir, toast, onGuardado }) {
    const url = (u) => u.replace('__ID__', s.id);
    const fondo = document.createElement('div');
    fondo.className = 'sg-fondo';
    fondo.innerHTML = '<div class="sg sg-carga" role="dialog" aria-modal="true"><div class="sg-spin"></div><p>Preparando el seguimiento…</p></div>';
    document.body.appendChild(fondo);
    const cerrar = () => { fondo.remove(); document.removeEventListener('keydown', alEscape); };
    const alEscape = (ev) => { if (ev.key === 'Escape') cerrar(); };
    document.addEventListener('keydown', alEscape);
    fondo.addEventListener('mousedown', (ev) => { if (ev.target === fondo && !fondo.querySelector('textarea').value.trim()) cerrar(); });

    let ctx;
    try {
        ctx = await pedir(url(urls.ctx));
    } catch (err) {
        cerrar();
        toast.fire({ icon: 'error', title: err.message });
        return;
    }

    const contactos = ctx.contactos;
    fondo.innerHTML = `
    <form class="sg" role="dialog" aria-modal="true" aria-label="Seguimiento de ${esc(ctx.solicitud.ref)}">
        <header class="sg-cab">
            <span class="sg-ico"><i class="fa-solid fa-comment-medical"></i></span>
            <div><h3>Seguimiento</h3><p><b>${esc(ctx.solicitud.ref)}</b> · ${esc(ctx.solicitud.cliente || '')}</p></div>
            <button type="button" class="sg-x" data-cerrar aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="sg-cuerpo">
            <section class="sg-sec">
                <label class="sg-tit" for="sgObs"><i class="fa-regular fa-note-sticky"></i>¿Qué pasó con el cliente?</label>
                <textarea id="sgObs" name="observacion" rows="3" maxlength="5000" placeholder="Escribe la observación para la bitácora…"></textarea>
                ${ctx.ultimos.length ? `<details class="sg-ult"><summary>Últimos seguimientos (${ctx.ultimos.length})</summary>
                    ${ctx.ultimos.map((u) => `<p><b>${esc(u.fecha)}</b>${u.usuario ? ` · ${esc(u.usuario)}` : ''}<br>${esc(u.texto)}</p>`).join('')}</details>` : ''}
            </section>

            <section class="sg-sec">
                <span class="sg-tit"><i class="fa-regular fa-calendar-check"></i>Próximo contacto${ctx.solicitud.proximoContacto ? `<small>Actual: ${esc(ctx.solicitud.proximoContacto)}</small>` : ''}</span>
                <div class="sg-fila">
                    <div class="sg-chips" data-dias>
                        <button type="button" data-d="" class="on">Sin fecha</button><button type="button" data-d="1">Mañana</button>
                        <button type="button" data-d="3">3 días</button><button type="button" data-d="7">1 semana</button><button type="button" data-d="15">15 días</button>
                    </div>
                    <input type="date" name="fecha" min="${hoy()}" aria-label="Fecha del próximo contacto">
                    <input type="time" name="hora" value="08:00" aria-label="Hora">
                </div>
            </section>

            <section class="sg-sec sg-correo">
                <label class="sg-switch">
                    <input type="checkbox" name="enviar">
                    <span class="sg-sw"></span>
                    <span class="sg-tit"><i class="fa-regular fa-envelope"></i>Enviar también un correo al cliente</span>
                </label>
                <div class="sg-mail" hidden>
                    <p class="sg-de"><i class="fa-solid fa-paper-plane"></i>Se envía desde <b>${esc(ctx.de || 'el remitente configurado')}</b>${ctx.asesor ? ` · ${esc(ctx.asesor)}` : ''}</p>
                    ${ctx.plantillas.length ? `<label class="sg-campo">Plantilla
                        <select name="plantilla"><option value="">— Sin plantilla —</option>${ctx.plantillas.map((p) => `<option value="${p.id}">${esc(p.label)}</option>`).join('')}</select></label>` : ''}
                    <div class="sg-campo"><span>Para <small>(busca un contacto o escribe un correo y pulsa Enter)</small></span>
                        <div class="sg-ms" data-ms>
                            <div class="sg-ms-caja"><span data-chips></span><input type="text" data-q placeholder="Buscar contacto o escribir correo…" autocomplete="off" aria-label="Destinatarios"></div>
                            <div class="sg-ms-lista" data-lista hidden></div>
                        </div>
                    </div>
                    <label class="sg-campo">Con copia (CC)<input type="text" name="cc" placeholder="correo@dominio.com, otro@dominio.com" autocomplete="off"></label>
                    <label class="sg-campo">Asunto<input type="text" name="asunto" maxlength="250" value="${esc(`SEGUIMIENTO ${ctx.solicitud.ref}${ctx.solicitud.asunto ? ' · ' + ctx.solicitud.asunto.slice(0, 80) : ''}`)}"></label>
                    <div class="sg-ia">
                        <input type="text" name="instrucciones" maxlength="1000" placeholder="Indicaciones para la IA (opcional): tono, qué pedir, qué recordar…">
                        <button type="button" class="sg-btn-ia" data-ia><i class="fa-solid fa-wand-magic-sparkles"></i><span>Redactar con IA</span></button>
                    </div>
                    <div class="sg-campo"><span>Mensaje</span>
                        <div class="sg-editor">
                            <div class="sg-barra">
                                <button type="button" data-cmd="bold" title="Negrita"><i class="fa-solid fa-bold"></i></button>
                                <button type="button" data-cmd="italic" title="Cursiva"><i class="fa-solid fa-italic"></i></button>
                                <button type="button" data-cmd="insertUnorderedList" title="Viñetas"><i class="fa-solid fa-list-ul"></i></button>
                                <button type="button" data-cmd="removeFormat" title="Quitar formato"><i class="fa-solid fa-eraser"></i></button>
                            </div>
                            <div class="sg-texto" contenteditable="true" data-cuerpo data-vacio="Escribe el mensaje o pídele un borrador a la IA a partir de tu observación…"></div>
                        </div>
                    </div>
                    ${ctx.firma ? '<label class="sg-firma"><input type="checkbox" name="incluir_firma" checked>Añadir mi firma al final</label>' : ''}
                </div>
            </section>
        </div>
        <footer class="sg-pie">
            <button type="button" class="sg-sec-btn" data-cerrar>Cancelar</button>
            <button type="submit" class="sg-ok"><i class="fa-solid fa-check"></i><span data-etq>Guardar seguimiento</span></button>
        </footer>
    </form>`;

    const form = fondo.querySelector('form');
    const q = (sel) => form.querySelector(sel);
    const fecha = form.elements.fecha;
    const enviar = form.elements.enviar;
    const mail = q('.sg-mail');
    const cuerpo = q('[data-cuerpo]');
    const etq = q('[data-etq]');
    q('#sgObs').focus();

    form.querySelectorAll('[data-cerrar]').forEach((b) => b.addEventListener('click', cerrar));
    form.querySelectorAll('[data-d]').forEach((b) => b.addEventListener('click', () => {
        fecha.value = b.dataset.d === '' ? '' : hoy(Number(b.dataset.d));
        form.querySelectorAll('[data-d]').forEach((x) => x.classList.toggle('on', x === b));
    }));
    fecha.addEventListener('input', () => form.querySelectorAll('[data-d]').forEach((x) => x.classList.remove('on')));
    enviar.addEventListener('change', () => {
        mail.hidden = !enviar.checked;
        etq.textContent = enviar.checked ? 'Guardar y enviar correo' : 'Guardar seguimiento';
        if (enviar.checked) cuerpo.focus();
    });
    form.querySelectorAll('[data-cmd]').forEach((b) => b.addEventListener('mousedown', (ev) => { ev.preventDefault(); document.execCommand(b.dataset.cmd); }));

    // Destinatarios: select múltiple con búsqueda; también acepta correos escritos a mano.
    const sel = new Map(contactos.filter((c) => c.sugerido).map((c) => [c.email.toLowerCase(), { email: c.email, nombre: c.nombre, id: c.id }]));
    const ms = q('[data-ms]');
    const chips = ms.querySelector('[data-chips]');
    const lista = ms.querySelector('[data-lista]');
    const buscar = ms.querySelector('[data-q]');
    const esCorreo = (v) => /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]+$/.test(v);
    const pintarMs = () => {
        chips.innerHTML = [...sel.values()].map((c) => `<span class="sg-chip" title="${esc(c.email)}">${esc(c.nombre || c.email)}<button type="button" data-quitar="${esc(c.email.toLowerCase())}" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></span>`).join('');
        const term = buscar.value.trim().toLowerCase();
        const vis = contactos.filter((c) => !term || `${c.nombre} ${c.email} ${c.cargo}`.toLowerCase().includes(term));
        lista.innerHTML = vis.length
            ? vis.map((c) => `<button type="button" class="sg-op${sel.has(c.email.toLowerCase()) ? ' on' : ''}" data-op="${esc(c.email)}"><i class="fa-solid ${sel.has(c.email.toLowerCase()) ? 'fa-square-check' : 'fa-square'}"></i>
                <span><b>${esc(c.nombre)}</b><small>${esc(c.email)}${c.cargo ? ` · ${esc(c.cargo)}` : ''}</small></span></button>`).join('')
            : `<p class="sg-nada">${esCorreo(term) ? 'Pulsa Enter para añadir este correo' : 'Sin coincidencias'}</p>`;
    };
    const alternar = (email) => {
        const k = email.toLowerCase();
        if (sel.has(k)) sel.delete(k);
        else { const c = contactos.find((x) => x.email.toLowerCase() === k); sel.set(k, { email, nombre: c?.nombre ?? '', id: c?.id }); }
        pintarMs();
    };
    const abrirLista = (v) => { lista.hidden = !v; };
    buscar.addEventListener('focus', () => { abrirLista(true); pintarMs(); });
    buscar.addEventListener('input', () => { abrirLista(true); pintarMs(); });
    buscar.addEventListener('keydown', (ev) => {
        const v = buscar.value.trim();
        if ((ev.key === 'Enter' || ev.key === ',' || ev.key === ';') && v) {
            ev.preventDefault();
            const k = v.toLowerCase();
            const hallado = contactos.filter((c) => `${c.nombre} ${c.email}`.toLowerCase().includes(k));
            if (esCorreo(v)) { if (!sel.has(k)) alternar(v); }
            else if (hallado.length === 1 && !sel.has(hallado[0].email.toLowerCase())) alternar(hallado[0].email);
            buscar.value = '';
            pintarMs();
        } else if (ev.key === 'Backspace' && !buscar.value && sel.size) {
            sel.delete([...sel.keys()].pop());
            pintarMs();
        } else if (ev.key === 'Escape' && !lista.hidden) { ev.stopPropagation(); abrirLista(false); }
    });
    ms.addEventListener('mousedown', (ev) => {
        const quitar = ev.target.closest('[data-quitar]');
        const op = ev.target.closest('[data-op]');
        if (quitar) { ev.preventDefault(); sel.delete(quitar.dataset.quitar); pintarMs(); }
        else if (op) { ev.preventDefault(); alternar(op.dataset.op); buscar.focus(); }
        else if (!ev.target.closest('.sg-ms-lista')) buscar.focus();
    });
    form.addEventListener('mousedown', (ev) => { if (!ev.target.closest('[data-ms]')) abrirLista(false); });
    pintarMs();

    const primerContacto = () => [...sel.values()][0]?.nombre ?? '';
    const plantilla = form.elements.plantilla;
    plantilla?.addEventListener('change', () => {
        const p = ctx.plantillas.find((x) => String(x.id) === plantilla.value);
        if (!p) return;
        if (p.topic) form.elements.asunto.value = reemplazos(p.topic, ctx, primerContacto());
        cuerpo.innerHTML = reemplazos(p.content, ctx, primerContacto());
    });

    const btnIa = q('[data-ia]');
    btnIa.addEventListener('click', async () => {
        const nota = (q('#sgObs').value.trim() || cuerpo.innerText.trim());
        if (!nota && !form.elements.instrucciones.value.trim()) {
            toast.fire({ icon: 'info', title: 'Escribe primero la observación o una indicación para la IA.' });
            q('#sgObs').focus();
            return;
        }
        const antes = btnIa.innerHTML;
        btnIa.disabled = true;
        btnIa.classList.add('cargando');
        btnIa.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i><span>Redactando…</span>';
        try {
            const r = await pedir(url(urls.ia), 'POST', { nota, instrucciones: form.elements.instrucciones.value.trim() || undefined, contacto_id: [...sel.values()].find((c) => c.id)?.id });
            if (r.asunto) form.elements.asunto.value = r.asunto;
            cuerpo.innerHTML = r.cuerpo;
        } catch (err) {
            toast.fire({ icon: 'error', title: err.message });
        }
        btnIa.disabled = false;
        btnIa.classList.remove('cargando');
        btnIa.innerHTML = antes;
    });

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const conCorreo = enviar.checked;
        const obs = form.elements.observacion.value.trim();
        const pendiente = buscar.value.trim();
        if (esCorreo(pendiente) && !sel.has(pendiente.toLowerCase())) alternar(pendiente);
        const para = [...sel.values()].map((c) => c.email);
        if (!conCorreo && !obs) { q('#sgObs').focus(); toast.fire({ icon: 'info', title: 'Escribe qué pasó con el cliente.' }); return; }
        if (conCorreo && !para.length) { toast.fire({ icon: 'info', title: 'Elige al menos un destinatario.' }); return; }
        const btn = q('.sg-ok');
        btn.disabled = true;
        etq.textContent = conCorreo ? 'Enviando…' : 'Guardando…';
        try {
            const r = await pedir(url(urls.guardar), 'POST', {
                observacion: obs || undefined,
                ...(fecha.value ? { fecha: fecha.value, hora: form.elements.hora.value || '08:00' } : {}),
                enviar: conCorreo,
                ...(conCorreo ? {
                    para, cc: form.elements.cc.value.trim(), asunto: form.elements.asunto.value.trim(), cuerpo: cuerpo.innerHTML,
                    incluir_firma: !!form.elements.incluir_firma?.checked,
                } : {}),
            });
            cerrar();
            toast.fire({ icon: 'success', title: r.message });
            onGuardado?.(r);
        } catch (err) {
            btn.disabled = false;
            etq.textContent = conCorreo ? 'Guardar y enviar correo' : 'Guardar seguimiento';
            toast.fire({ icon: 'error', title: err.message });
        }
    });
}

/**
 * Seguimiento ESTÁNDAR de una solicitud: el mismo diálogo desde cualquier pantalla (tablero, panel, fichas).
 * Las rutas y el token salen de las etiquetas <meta> del layout, así que no hay que pasar nada más que la solicitud.
 */
export function seguimientoSolicitud({ id, ref, cliente, onGuardado }) {
    const cfg = JSON.parse(document.querySelector('meta[name="solicitud-seguimiento"]')?.content || '{}');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const pedir = async (url, metodo = 'GET', datos = null) => {
        const r = await fetch(url, {
            method: metodo,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', ...(datos ? { 'Content-Type': 'application/json' } : {}) },
            body: datos ? JSON.stringify(datos) : undefined,
        });
        const json = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(json.message || Object.values(json.errors ?? {})[0]?.[0] || 'No se pudo completar la acción.');
        return json;
    };

    return abrirSeguimiento({ s: { id, ref, cliente }, urls: cfg, pedir, toast: toastEstandar, onGuardado });
}
