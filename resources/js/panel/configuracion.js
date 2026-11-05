// Configuración (administradores): pestañas, tokens de IA por proveedor y parametrización del asistente de terceros.
import Swal from 'sweetalert2';

const datos = JSON.parse(document.getElementById('cfg-datos').textContent);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const clon = (o) => JSON.parse(JSON.stringify(o));
const INPUT = 'w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary';
const BTN = 'rounded-lg border border-border px-4 py-2 text-sm font-medium hover:bg-muted transition-colors disabled:opacity-50';
const BTN_PRI = 'rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 transition-opacity disabled:opacity-50';

async function api(url, method, body) {
    const res = await fetch(url, {
        method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': datos.csrf },
        body: body ? JSON.stringify(body) : undefined,
    });
    const j = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(j.errors ? Object.values(j.errors)[0][0] : (j.mensaje || j.message || `Error ${res.status}`));
    return j;
}
const aviso = (icon, text) => Swal.fire({ icon, text, timer: icon === 'success' ? 1800 : undefined, showConfirmButton: icon !== 'success' });

// ------------------------------------------------------------------ pestañas (se recuerdan en el hash: #ia/terceros)
function pestanas() {
    const root = document.getElementById('cfg');
    const marcar = (botones, activo, attr) => botones.forEach((b) => {
        const on = b.dataset[attr] === activo;
        b.classList.toggle('bg-accent', on);
        b.classList.toggle('text-accent-foreground', on);
        b.classList.toggle('text-muted-foreground', !on);
    });
    function ir(tab, sub) {
        marcar(root.querySelectorAll('[data-cfg-tab]'), tab, 'cfgTab');
        root.querySelectorAll('[data-cfg-panel]').forEach((p) => p.classList.toggle('hidden', p.dataset.cfgPanel !== tab));
        if (sub) {
            marcar(root.querySelectorAll('[data-cfg-sub]'), sub, 'cfgSub');
            root.querySelectorAll('[data-cfg-subpanel]').forEach((p) => p.classList.toggle('hidden', p.dataset.cfgSubpanel !== sub));
        }
        history.replaceState(null, '', `#${tab}${sub ? `/${sub}` : ''}`);
    }
    const [t0, s0] = location.hash.slice(1).split('/');
    let tab = ['perfil', 'panel', 'ia'].includes(t0) ? t0 : 'perfil';
    if (root.dataset.abrirPerfil === '1') tab = 'perfil';
    let sub = ['proveedores', 'terceros', 'solicitudes', 'productos', 'cotizaciones'].includes(s0) ? s0 : 'proveedores';
    ir(tab, sub);
    root.querySelectorAll('[data-cfg-tab]').forEach((b) => b.addEventListener('click', () => { tab = b.dataset.cfgTab; ir(tab, sub); }));
    root.querySelectorAll('[data-cfg-sub]').forEach((b) => b.addEventListener('click', () => { sub = b.dataset.cfgSub; ir('ia', sub); }));
}

// ------------------------------------------------------------------ proveedores y tokens
// Colores corporativos de cada proveedor (c = principal, g = degradado de la franja superior) y una marca simplificada.
const MARCAS = {
    anthropic: {
        c: '#d97757', g: 'linear-gradient(90deg,#d97757,#cc785c)',
        svg: '<path d="M12 2l1.6 6.2L19 4.5l-3.2 5.4 6.2 1.6-6.2 1.6L19 19.5l-5.4-3.7L12 22l-1.6-6.2L5 19.5l3.2-5.4L2 12.5l6.2-1.6L5 4.5l5.4 3.7z"/>',
    },
    openai: {
        c: '#10a37f', g: 'linear-gradient(90deg,#10a37f,#1a7f64)',
        svg: '<path d="M12 2.5l8 4.6v9.8l-8 4.6-8-4.6V7.1zm0 3.2L6.8 8.7v6.6L12 18.3l5.2-3V8.7z" fill-rule="evenodd"/><circle cx="12" cy="12" r="2.4"/>',
    },
    gemini: {
        c: '#4285f4', g: 'linear-gradient(90deg,#4285f4,#9b72cb 55%,#d96570)',
        svg: '<path d="M12 2c.4 5.6 4.4 9.6 10 10-5.6.4-9.6 4.4-10 10-.4-5.6-4.4-9.6-10-10 5.6-.4 9.6-4.4 10-10z"/>',
    },
};

function proveedores() {
    const cont = document.getElementById('cfg-proveedores');
    let ia = clon(datos.ia);

    function pintar() {
        cont.innerHTML = `
            <p class="mb-4 text-sm text-muted-foreground">Registra el token (API key) de cada proveedor. Se guardan <b>cifrados</b> y no vuelven a mostrarse; para cambiarlo, escribe uno nuevo. El proveedor predeterminado se usa en todos los asistentes, salvo que un módulo elija otro.</p>
            <div class="grid gap-4 lg:grid-cols-3">${Object.entries(ia.proveedores).map(([id, p]) => {
                const m = MARCAS[id];
                const pred = ia.predeterminado === id;
                return `
                <div class="relative overflow-hidden rounded-xl border bg-card p-4 pt-5 transition-shadow" style="--c:${m.c};border-color:${pred ? m.c : 'var(--border)'};box-shadow:${pred ? `0 10px 28px -16px ${m.c}` : 'none'}" data-prov="${id}">
                    <div class="absolute inset-x-0 top-0 h-1.5" style="background:${m.g}"></div>
                    <div class="mb-4 flex items-center gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-white" style="background:${m.g}"><svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor" aria-hidden="true">${m.svg}</svg></span>
                        <div class="min-w-0 flex-1"><b class="block truncate text-sm">${esc(p.nombre)}</b>
                            <span class="text-[11px] ${p.tieneToken ? '' : 'text-muted-foreground'}" style="${p.tieneToken ? `color:${m.c}` : ''}"><i class="fa-solid ${p.tieneToken ? 'fa-circle-check' : 'fa-circle-minus'}"></i> ${p.tieneToken ? 'Token configurado' : 'Sin token'}</span></div>
                        <label class="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold" style="border-color:${m.c};${pred ? `background:${m.c};color:#fff` : `color:${m.c}`}" data-pred-lbl>
                            <input type="radio" name="pred" value="${id}" class="hidden" ${pred ? 'checked' : ''}> ${pred ? '<i class="fa-solid fa-star"></i> Predeterminado' : 'Usar como predeterminado'}</label>
                    </div>
                    <label class="mb-1 block text-xs font-semibold text-muted-foreground">Modelo</label>
                    <input class="${INPUT} cfg-prov-in mb-3" data-f="modelo" value="${esc(p.modelo)}" placeholder="${esc(p.modeloDefecto)}">
                    <label class="mb-1 block text-xs font-semibold text-muted-foreground">Token / API key</label>
                    <input type="password" autocomplete="new-password" class="${INPUT} cfg-prov-in" data-f="token" placeholder="${p.tieneToken ? `Guardado · termina en …${esc(p.tokenFinal)}` : 'Pega aquí el token'}">
                    ${p.tieneToken ? '<div class="mt-2 text-right text-xs"><button type="button" class="text-red-600 hover:underline" data-borrar>Quitar token</button></div>' : ''}
                    <p class="mt-2 text-[11px] text-muted-foreground">${p.soportaWeb ? '<i class="fa-solid fa-globe"></i> Permite búsqueda en internet.' : 'Sin búsqueda en internet en esta versión.'}</p>
                    <button type="button" class="mt-3 w-full rounded-lg border px-4 py-2 text-sm font-semibold transition-colors disabled:opacity-50" style="border-color:${m.c};color:${m.c}" data-probar ${p.tieneToken ? '' : 'disabled'}><i class="fa-solid fa-plug"></i> Probar conexión</button>
                </div>`;
            }).join('')}
            </div>
            <div class="mt-5 border-t border-border pt-4"><button type="button" class="${BTN_PRI}" data-guardar><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>`;
        cont.querySelectorAll('.cfg-prov-in').forEach((i) => {
            const c = i.closest('[data-prov]').style.getPropertyValue('--c');
            i.addEventListener('focus', () => { i.style.borderColor = c; });
            i.addEventListener('blur', () => { i.style.borderColor = ''; });
        });
    }

    function leer() {
        const out = { predeterminado: cont.querySelector('[name=pred]:checked')?.value || ia.predeterminado, proveedores: {} };
        cont.querySelectorAll('[data-prov]').forEach((c) => {
            out.proveedores[c.dataset.prov] = { modelo: c.querySelector('[data-f=modelo]').value, token: c.querySelector('[data-f=token]').value };
        });
        return out;
    }

    async function guardar(extra = {}) {
        const cuerpo = leer();
        Object.entries(extra).forEach(([id, v]) => Object.assign(cuerpo.proveedores[id], v));
        const j = await api(datos.urls.ia, 'PUT', cuerpo);
        ia = j.ia;
        pintar();
    }

    cont.addEventListener('click', async (ev) => {
        const card = ev.target.closest('[data-prov]');
        try {
            if (ev.target.closest('[data-guardar]')) { await guardar(); aviso('success', 'Parámetros guardados'); }
            else if (ev.target.closest('[data-borrar]')) {
                if ((await Swal.fire({ icon: 'warning', text: '¿Quitar el token de este proveedor?', showCancelButton: true, confirmButtonText: 'Quitar', cancelButtonText: 'Cancelar' })).isConfirmed) {
                    await guardar({ [card.dataset.prov]: { borrar_token: true } });
                }
            } else if (ev.target.closest('[data-probar]')) {
                const btn = ev.target.closest('[data-probar]');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Probando…';
                const id = card.dataset.prov;
                try {
                    await guardar();
                    const r = await api(datos.urls.probar, 'POST', { proveedor: id });
                    aviso('success', r.mensaje);
                } finally { pintar(); }
            }
        } catch (err) { aviso('error', err.message); }
    });
    // Cambiar el predeterminado repinta las tarjetas conservando lo escrito (modelo y token sin guardar).
    cont.addEventListener('change', (ev) => {
        if (ev.target.name !== 'pred') return;
        const pendiente = leer();
        ia.predeterminado = ev.target.value;
        Object.entries(pendiente.proveedores).forEach(([id, v]) => { ia.proveedores[id].modelo = v.modelo; });
        pintar();
        Object.entries(pendiente.proveedores).forEach(([id, v]) => { cont.querySelector(`[data-prov="${id}"] [data-f=token]`).value = v.token; });
    });
    pintar();
}

// ------------------------------------------------------------------ asistente de terceros (prompt parametrizable)
function terceros() {
    const cont = document.getElementById('cfg-terceros');
    let cfg = clon(datos.terceros);
    const cat = datos.catalogo;
    const grupos = [...new Set(Object.values(cat).map((m) => m.grupo))];
    const filtro = { grupo: '', activos: false };
    const num = (v) => (v === null || v === undefined ? '' : v);

    function fila(f, i) {
        const m = cat[f.clave];
        if (!m || (filtro.grupo && m.grupo !== filtro.grupo) || (filtro.activos && !f.activo)) return '';
        const bloq = m.bloqueado;
        const dis = (x) => (x ? 'disabled' : '');
        const esLista = ['lista', 'multilista', 'booleano'].includes(m.tipo);
        const tipos = esLista
            ? `<option>${{ lista: 'Lista del catálogo', multilista: 'Varias del catálogo', booleano: 'Sí / No' }[m.tipo]}</option>`
            : Object.entries(datos.tiposDato).map(([k, t]) => `<option value="${k}" ${f.tipo === k ? 'selected' : ''}>${esc(t)}</option>`).join('');
        const rg = 'w-full rounded-md border border-border bg-background px-2 py-1 text-xs outline-none focus:border-primary disabled:opacity-60';
        const lab = 'mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-muted-foreground';
        return `
        <div class="rounded-xl border ${bloq ? 'border-amber-500/40 bg-amber-500/5' : 'border-border'} p-3 ${f.activo ? '' : 'opacity-60'}" data-i="${i}">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div class="flex gap-1">
                    <button type="button" class="${BTN} !px-2 !py-1" data-mover="-1" title="Subir"><i class="fa-solid fa-arrow-up"></i></button>
                    <button type="button" class="${BTN} !px-2 !py-1" data-mover="1" title="Bajar"><i class="fa-solid fa-arrow-down"></i></button></div>
                <label class="flex items-center gap-1.5 text-xs font-medium"><input type="checkbox" data-c="activo" ${f.activo ? 'checked' : ''} ${dis(bloq)}> Pedir</label>
                <label class="flex items-center gap-1.5 text-xs font-medium"><input type="checkbox" data-c="obligatorio" ${f.obligatorio ? 'checked' : ''} ${dis(bloq)}> Obligatorio</label>
                ${m.web ? `<label class="flex items-center gap-1.5 text-xs font-medium" title="Puede sugerirlo buscando en internet"><input type="checkbox" data-c="web" ${f.web ? 'checked' : ''}> 🌐 Web</label>` : ''}
                <input class="${INPUT} !w-64 !py-1.5" data-c="etiqueta" value="${esc(f.etiqueta)}" title="Nombre con el que el asistente le pide el dato al usuario">
                <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">${esc(m.grupo)}</span>
                ${bloq ? '<span class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[11px] font-semibold text-amber-700" title="Obligatorio en la ficha original: no se puede quitar ni relajar"><i class="fa-solid fa-lock"></i> Obligatorio de la ficha</span>' : ''}
                <code class="text-[11px] text-muted-foreground">${esc(f.clave)}</code>
            </div>
            <div class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-[1.4fr_auto_90px_90px_1.2fr]">
                <label><span class="${lab}">Tipo de dato</span><select class="${rg}" data-c="tipo" ${dis(bloq || esLista)}>${tipos}</select></label>
                <label class="flex items-end gap-1.5 pb-1 text-xs" title="${m.unicoFijo ? 'Regla fija: no puede repetirse' : m.unicoPosible ? 'No puede existir otro tercero con el mismo valor' : 'No aplica a este dato'}"><input type="checkbox" data-c="unico" ${f.unico ? 'checked' : ''} ${dis(bloq || m.unicoFijo || !m.unicoPosible)}> Único</label>
                <label><span class="${lab}">Long. mín.</span><input type="number" min="0" class="${rg}" data-c="min" value="${num(f.min)}" ${dis(bloq || esLista)}></label>
                <label><span class="${lab}">Long. máx.${m.max ? ` (≤${m.max})` : ''}</span><input type="number" min="0" ${m.max ? `max="${m.max}"` : ''} class="${rg}" data-c="max" value="${num(f.max)}" ${dis(bloq || esLista)}></label>
                <label><span class="${lab}">Patrón (regex, opcional)</span><input class="${rg} font-mono" data-c="patron" value="${esc(f.patron)}" placeholder="^[0-9]{10}$" ${dis(bloq || esLista)}></label>
            </div>
            <div class="mt-2 grid gap-2 md:grid-cols-2">
                <label><span class="${lab}">Cómo pedirlo (parámetros de entrada)</span><textarea rows="2" class="${rg}" data-c="instrucciones" placeholder="Ej.: pregúntalo después del NIT; acepta «Acme» y completa con la razón social">${esc(f.instrucciones)}</textarea></label>
                <label><span class="${lab}">Validación adicional para la IA</span><textarea rows="2" class="${rg}" data-c="validacion" placeholder="Ej.: debe ser un correo corporativo, no gmail/hotmail">${esc(f.validacion)}</textarea></label>
            </div>
            <p class="mt-1.5 text-[11px] text-muted-foreground">Formato esperado: ${esc(m.formato)}</p>
        </div>`;
    }

    const chk = (k, texto, ayuda) => `
        <label class="flex items-start gap-2 rounded-lg border border-border p-3 text-sm"><input type="checkbox" class="mt-0.5" data-g="${k}" ${cfg[k] ? 'checked' : ''}>
            <span><b class="font-medium">${texto}</b><small class="block text-xs text-muted-foreground">${ayuda}</small></span></label>`;
    const area = (k, texto, filas = 2) => `<label class="block"><span class="mb-1 block text-xs font-semibold text-muted-foreground">${texto}</span><textarea rows="${filas}" class="${INPUT}" data-g="${k}">${esc(cfg[k])}</textarea></label>`;

    function pintar() {
        const proveedores = Object.entries(datos.ia.proveedores).map(([id, p]) => `<option value="${id}" ${cfg.proveedor === id ? 'selected' : ''}>${esc(p.nombre)}</option>`).join('');
        cont.innerHTML = `
            <p class="mb-4 text-sm text-muted-foreground">Define cómo el asistente de <b>Creación con IA</b> del listado de Terceros conversa con el usuario: qué datos pide, en qué orden, con qué formato y validaciones. El servidor además valida siempre las reglas de la ficha, el dígito de verificación del NIT y los duplicados.</p>

            <div class="mb-5 grid gap-4 lg:grid-cols-2">
                ${area('rol', 'Rol e instrucciones generales', 4)}
                <div class="space-y-3">
                    ${area('tono', 'Tono de la conversación')}
                    ${area('saludo', 'Mensaje de bienvenida')}
                </div>
                ${area('reglas_extra', 'Reglas adicionales (opcional) — ej.: «Si el cliente es del exterior, usa NIE y pide el país en observaciones»', 3)}
                <label class="block"><span class="mb-1 block text-xs font-semibold text-muted-foreground">Proveedor de IA para este módulo</span>
                    <select class="${INPUT}" data-g="proveedor"><option value="">Usar el predeterminado</option>${proveedores}</select></label>
            </div>

            <div class="mb-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                ${chk('un_dato_a_la_vez', 'Un dato a la vez', 'Pregunta un solo dato por mensaje; si no, agrupa los relacionados.')}
                ${chk('aceptar_varios_datos', 'Aceptar varios datos en una frase', 'Extrae todo lo que el usuario diga junto («NIT 900123456, Acme SAS»).')}
                ${chk('confirmar_antes_de_crear', 'Confirmar antes de crear', 'Muestra un resumen y el usuario pulsa «Crear tercero».')}
                ${chk('permitir_rut', 'Permitir adjuntar el RUT', 'El usuario sube el RUT (PDF o imagen); la IA lee los datos y el servidor los valida (NIT/DV, duplicados, ciudad). El archivo se envía al proveedor de IA elegido y no se guarda.')}
                ${chk('detectar_duplicados', 'Detectar duplicados', 'Bloquea documentos ya registrados y avisa de nombres repetidos.')}
                ${chk('mayusculas_nombre', 'Nombres en MAYÚSCULAS', 'Normaliza razón social y alias.')}
                ${chk('buscar_web', 'Permitir búsqueda en internet', 'Para sugerir datos (dirección, correo, sitio web…) de campos marcados con 🌐. Solo Claude y Gemini.')}
            </div>

            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h4 class="text-sm font-bold">Datos del formulario de terceros: qué pedir, en qué orden y con qué reglas</h4>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <select class="${INPUT} !w-auto !py-1.5" data-filtro-grupo><option value="">Todos los grupos</option>${grupos.map((g) => `<option value="${esc(g)}" ${filtro.grupo === g ? 'selected' : ''}>${esc(g)}</option>`).join('')}</select>
                    <label class="flex items-center gap-1.5"><input type="checkbox" data-filtro-activos ${filtro.activos ? 'checked' : ''}> Solo los que se piden</label>
                </div>
            </div>
            <p class="mb-3 rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground"><i class="fa-solid fa-lock"></i> Los datos marcados con candado son <b>obligatorios en la ficha original</b>: siempre se piden y sus reglas no se pueden relajar, para garantizar que el tercero se cree. El estado «Activo» y «Responsable de IVA» se asignan automáticamente. Todo lo que el asistente recoja se vuelve a validar en el servidor con las reglas de la ficha.</p>
            <div class="space-y-2">${cfg.campos.map((f, i) => fila(f, i)).filter(Boolean).join('')}</div>

            <div class="mt-5 flex flex-wrap gap-2 border-t border-border pt-4">
                <button type="button" class="${BTN_PRI}" data-guardar><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
                <button type="button" class="${BTN}" data-restablecer><i class="fa-solid fa-rotate-left"></i> Restablecer valores de fábrica</button>
            </div>`;
    }

    // Vuelca a cfg lo que hay en pantalla antes de reordenar o guardar.
    function leer() {
        cont.querySelectorAll('[data-g]').forEach((el) => { cfg[el.dataset.g] = el.type === 'checkbox' ? el.checked : el.value; });
        cont.querySelectorAll('[data-i]').forEach((tr) => {
            const f = cfg.campos[+tr.dataset.i];
            tr.querySelectorAll('[data-c]').forEach((el) => { f[el.dataset.c] = el.type === 'checkbox' ? el.checked : el.value; });
        });
    }

    cont.addEventListener('change', (ev) => {
        if (ev.target.dataset.c === 'activo') ev.target.closest('[data-i]').classList.toggle('opacity-60', !ev.target.checked);
        if ('filtroGrupo' in ev.target.dataset || 'filtroActivos' in ev.target.dataset) {
            leer();
            filtro.grupo = cont.querySelector('[data-filtro-grupo]').value;
            filtro.activos = cont.querySelector('[data-filtro-activos]').checked;
            pintar();
        }
    });
    cont.addEventListener('click', async (ev) => {
        const mover = ev.target.closest('[data-mover]');
        try {
            if (mover) {
                leer();
                const i = +mover.closest('[data-i]').dataset.i;
                const paso = +mover.dataset.mover;
                // Con filtros activos se intercambia con el vecino visible más cercano.
                let j = i + paso;
                while (cfg.campos[j] && !fila(cfg.campos[j], j)) j += paso;
                if (!cfg.campos[j]) return;
                [cfg.campos[i], cfg.campos[j]] = [cfg.campos[j], cfg.campos[i]];
                pintar();
            } else if (ev.target.closest('[data-guardar]')) {
                leer();
                const j = await api(datos.urls.terceros, 'PUT', cfg);
                cfg = j.terceros;
                pintar();
                aviso('success', 'Parametrización guardada');
            } else if (ev.target.closest('[data-restablecer]')) {
                if (!(await Swal.fire({ icon: 'warning', text: '¿Volver a los valores de fábrica del asistente de terceros?', showCancelButton: true, confirmButtonText: 'Restablecer', cancelButtonText: 'Cancelar' })).isConfirmed) return;
                cfg = (await api(datos.urls.tercerosRestablecer, 'DELETE')).terceros;
                pintar();
            }
        } catch (err) { aviso('error', err.message); }
    });
    pintar();
}

// ------------------------------------------------------------------ panel: vista solo comercial por grupos
function panel() {
    const cont = document.getElementById('cfg-panel');
    let p = clon(datos.panel);

    function pintar() {
        const mis = p.misGrupos.join(', ');
        cont.innerHTML = `
            <h3 class="mb-1 text-sm font-bold">Vista «solo comercial» del panel</h3>
            <p class="mb-4 text-sm text-muted-foreground">Los <b>negociadores</b> ven el panel sin los insights ni los KPIs generales (conservan la cabecera, la línea de proceso y el Tablero comercial). Igual que en el sistema anterior, es negociador quien está en un <b>grupo activo de Olimpiadas</b> (Informes → Configuración), excepto el grupo RETIRADOS; no depende de los grupos de usuarios.</p>
            <h4 class="mb-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">Grupos de Olimpiadas que cuentan como negociadores</h4>
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">${p.grupos.map((g) => `
                <div class="flex items-center justify-between rounded-lg border border-border px-3 py-2 text-sm"><span>${esc(g.nombre)}</span><span class="text-xs text-muted-foreground">${g.integrantes} integrante${g.integrantes === 1 ? '' : 's'}</span></div>`).join('') || '<p class="text-sm text-muted-foreground">No encuentro grupos activos de Olimpiadas (tablas informessolu_olimpiadas_*).</p>'}</div>
            <div class="mt-4 rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">Tu usuario: ${p.soyAdmin ? '<b>administrador</b> · ' : ''}grupos de Olimpiadas: <b>${esc(mis || 'ninguno')}</b> · vista solo comercial: <b>${p.activaParaMi ? 'sí' : 'no'}</b></div>
            <p class="mt-3 text-xs text-muted-foreground">Un administrador que sea negociador también ve esta vista reducida; puede abrir el panel completo con el botón «Ver panel completo».</p>

            <h4 class="mb-1 mt-6 text-xs font-bold uppercase tracking-wide text-muted-foreground">Usuarios que siempre ven el panel completo</h4>
            <p class="mb-2 text-xs text-muted-foreground">Excepción a la regla de arriba: estos usuarios ven el saludo, los insights y los KPIs aunque estén en un grupo de negociadores.</p>
            <input type="search" class="${INPUT} mb-2 !w-full sm:!w-72" placeholder="Buscar usuario…" data-buscar>
            <div class="grid max-h-64 gap-1.5 overflow-y-auto sm:grid-cols-2 xl:grid-cols-3" data-usuarios>${p.usuarios.map((u) => `
                <label class="flex items-center gap-2 rounded-lg border border-border px-3 py-1.5 text-sm" data-nombre="${esc((u.nombre + ' ' + u.login).toLowerCase())}"><input type="checkbox" data-u="${u.id}" ${p.completos.includes(u.id) ? 'checked' : ''}> <span class="truncate">${esc(u.nombre)} <small class="text-muted-foreground">${esc(u.login)}</small></span></label>`).join('')}</div>
            <div class="mt-4 border-t border-border pt-4"><button type="button" class="${BTN_PRI}" data-guardar><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>`;
    }

    cont.addEventListener('input', (ev) => {
        if (!ev.target.matches('[data-buscar]')) return;
        const q = ev.target.value.trim().toLowerCase();
        cont.querySelectorAll('[data-nombre]').forEach((l) => l.classList.toggle('hidden', q && !l.dataset.nombre.includes(q)));
    });
    cont.addEventListener('click', async (ev) => {
        if (!ev.target.closest('[data-guardar]')) return;
        try {
            const j = await api(datos.urls.panel, 'PUT', { usuarios: [...cont.querySelectorAll('[data-u]:checked')].map((i) => +i.dataset.u) });
            p = j.panel;
            pintar();
            aviso('success', 'Guardado');
        } catch (err) { aviso('error', err.message); }
    });
    pintar();
}

// ------------------------------------------------------------------ línea de proceso comercial: fuente, estados y semáforo por etapa
function proceso() {
    const cont = document.getElementById('cfg-proceso');
    let etapas = clon(datos.panel.proceso);
    let semaforo = clon(datos.panel.semaforo);
    let sel = 0;
    const fuentes = datos.panel.fuentes;
    const rg = 'w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm outline-none focus:border-primary';
    const lab = 'mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-muted-foreground';

    /** Color del círculo de la etapa: el de la card del tablero (por defecto) o uno propio. */
    function colorEtapa(e, i) {
        const defecto = datos.panel.procesoDefectos?.[e.clave] ?? null;
        const propio = e.color || null;
        const mostrado = propio || defecto || '#94a3b8';
        return `<div class="mt-2 flex flex-wrap items-center gap-3 text-xs">
            <span class="${lab} !mb-0">Color de la etapa</span>
            <input type="color" value="${mostrado}" data-kc="${i}" class="h-8 w-12 cursor-pointer rounded border border-border bg-background p-0.5" title="Elegir color">
            <span class="text-muted-foreground">${propio ? `Personalizado ${propio}` : (defecto ? `Por defecto (card del tablero) ${defecto}` : 'Sin color: la etapa se ve gris hasta que le elijas uno')}</span>
            ${propio ? '<button type="button" class="font-semibold text-primary hover:underline" data-color-defecto>Usar el por defecto</button>' : ''}
        </div>`;
    }

    function bloqueSemaforo() {
        const campo = (k, nombre) => `<label class="flex items-center gap-2 text-xs"><input type="color" value="${semaforo[k]}" data-sem="${k}" class="h-8 w-12 cursor-pointer rounded border border-border bg-background p-0.5"> ${nombre}</label>`;
        return `<div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border border-border p-3">
            <span class="${lab} !mb-0">Colores del semáforo</span>
            ${campo('verde', 'Verde')}${campo('amarillo', 'Amarillo')}${campo('rojo', 'Rojo')}
            <span class="text-[11px] text-muted-foreground">Se usan en los puntos y líneas entre etapas y en la tarjeta «Etapa más atrasada».</span>
        </div>`;
    }

    function fila(e, i) {
        const f = e.fuente ? fuentes[e.fuente] : null;
        return `
        <div class="rounded-xl border border-border p-3" data-i="${i}">
            <div class="grid gap-2 md:grid-cols-[1.2fr_1.2fr_1fr_110px_110px]">
                <label><span class="${lab}">Etapa</span><input class="${rg}" data-k="nombre" value="${esc(e.nombre)}"></label>
                <label><span class="${lab}">Documentos de donde sale</span><select class="${rg}" data-k="fuente"><option value="">— Sin definir —</option>${Object.entries(fuentes).map(([k, v]) => `<option value="${k}" ${e.fuente === k ? 'selected' : ''}>${esc(v.nombre)}</option>`).join('')}</select></label>
                <label><span class="${lab}">Fecha que se muestra</span><select class="${rg}" data-k="fecha"><option value="antigua" ${e.fecha === 'antigua' ? 'selected' : ''}>La más antigua</option><option value="ultima" ${e.fecha === 'ultima' ? 'selected' : ''}>La última</option></select></label>
                <label><span class="${lab}"><span class="text-emerald-600">●</span> Verde hasta (días)</span><input type="number" min="0" class="${rg}" data-k="verde" value="${e.verde}"></label>
                <label><span class="${lab}"><span class="text-amber-500">●</span> Amarillo hasta</span><input type="number" min="0" class="${rg}" data-k="amarillo" value="${e.amarillo}"></label>
            </div>
            ${colorEtapa(e, i)}
            <p class="mt-1.5 text-[11px] text-muted-foreground"><span class="text-emerald-600">● Verde</span> 0–${e.verde} días · <span class="text-amber-500">● Amarillo</span> ${e.verde + 1}–${e.amarillo} · <span class="text-red-600">● Rojo</span> más de ${e.amarillo} días, contados desde la fecha que se muestra.</p>
            ${f ? `<div class="mt-2"><span class="${lab}">Estados que cuentan en esta etapa</span>
                <div class="grid max-h-40 gap-1 overflow-y-auto sm:grid-cols-2 xl:grid-cols-3">${f.estados.map((s) => `
                    <label class="flex items-center gap-2 rounded-md border border-border px-2 py-1 text-xs"><input type="checkbox" data-estado="${esc(s.id)}" ${e.estados.map(String).includes(String(s.id)) ? 'checked' : ''}> ${esc(s.nombre)}</label>`).join('')}</div></div>`
                : '<p class="mt-2 text-xs text-muted-foreground">Sin definir: la etapa se muestra en 0 hasta que elijas los documentos y estados de donde sale.</p>'}
        </div>`;
    }

    function pintar() {
        cont.innerHTML = `
            <h3 class="mb-1 text-sm font-bold">Línea de proceso comercial</h3>
            <p class="mb-4 text-sm text-muted-foreground">Para cada etapa define de qué documentos sale, qué estados cuentan y cuándo se pone en <b>verde, amarillo o rojo</b> según los días de la fecha que muestra. Solo cuenta documentos desde el año configurado en <code>INFORMESSOLU_ANIO_DESDE</code>. Las etapas «sin definir» se activan eligiendo documentos y estados.</p>
            ${bloqueSemaforo()}
            <label class="mb-3 block max-w-sm"><span class="${lab}">Etapa a configurar</span><select class="${rg}" data-etapa>${etapas.map((e, i) => `<option value="${i}" ${i === sel ? 'selected' : ''}>${i + 1}. ${esc(e.nombre)}</option>`).join('')}</select></label>
            ${fila(etapas[sel], sel)}
            <div class="mt-4 flex flex-wrap gap-2 border-t border-border pt-4">
                <button type="button" class="${BTN_PRI}" data-guardar><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
                <button type="button" class="${BTN}" data-restablecer><i class="fa-solid fa-rotate-left"></i> Restablecer valores de fábrica</button>
            </div>`;
    }

    // Vuelca a `etapas` lo que hay en pantalla (sin perder lo escrito al cambiar de fuente).
    function leer() {
        cont.querySelectorAll('[data-i]').forEach((el) => {
            const e = etapas[+el.dataset.i];
            el.querySelectorAll('[data-k]').forEach((c) => { e[c.dataset.k] = c.type === 'number' ? Math.max(0, +c.value || 0) : c.value; });
            if (e.fuente) e.estados = [...el.querySelectorAll('[data-estado]:checked')].map((c) => (e.fuente === 'guia' ? c.dataset.estado : +c.dataset.estado));
            e.fuente = e.fuente || null;
            if (e.amarillo < e.verde) e.amarillo = e.verde;
        });
    }

    cont.addEventListener('input', (ev) => {
        if (ev.target.matches('[data-sem]')) semaforo[ev.target.dataset.sem] = ev.target.value;
    });
    cont.addEventListener('change', (ev) => {
        if (ev.target.matches('[data-sem]')) { semaforo[ev.target.dataset.sem] = ev.target.value; return; }
        if (ev.target.matches('[data-kc]')) { leer(); etapas[+ev.target.dataset.kc].color = ev.target.value; pintar(); return; }
        if (ev.target.matches('[data-etapa]')) { leer(); sel = +ev.target.value; pintar(); return; }
        if (!ev.target.closest('[data-i]')) return;
        const i = +ev.target.closest('[data-i]').dataset.i;
        const antes = etapas[i].fuente;
        leer();
        // Al cambiar de fuente se proponen los estados «activos» de esa fuente (en guías, ninguno).
        if (ev.target.dataset.k === 'fuente' && etapas[i].fuente !== antes) etapas[i].estados = etapas[i].fuente ? [...fuentes[etapas[i].fuente].activos] : [];
        if (ev.target.dataset.k && ev.target.dataset.k !== 'nombre') pintar();
    });
    cont.addEventListener('click', async (ev) => {
        try {
            if (ev.target.closest('[data-color-defecto]')) {
                leer();
                etapas[sel].color = null;
                pintar();
            } else if (ev.target.closest('[data-guardar]')) {
                leer();
                datos.panel = (await api(datos.urls.proceso, 'PUT', { etapas, semaforo })).panel;
                etapas = clon(datos.panel.proceso);
                semaforo = clon(datos.panel.semaforo);
                pintar();
                aviso('success', 'Línea de proceso guardada');
            } else if (ev.target.closest('[data-restablecer]')) {
                if (!(await Swal.fire({ icon: 'warning', text: '¿Volver a los valores de fábrica de la línea de proceso?', showCancelButton: true, confirmButtonText: 'Restablecer', cancelButtonText: 'Cancelar' })).isConfirmed) return;
                datos.panel = (await api(datos.urls.proceso, 'PUT', { etapas, semaforo, restablecer: true })).panel;
                etapas = clon(datos.panel.proceso);
                semaforo = clon(datos.panel.semaforo);
                pintar();
            }
        } catch (err) { aviso('error', err.message); }
    });
    pintar();
}

// ------------------------------------------------------------------ instrucciones de la IA de solicitudes
function solicitudesIa() {
    const cont = document.getElementById('cfg-solicitudes');
    if (!cont) return;
    let prompts = clon(datos.promptsSolicitud);
    const AREA = `${INPUT} min-h-48 font-mono text-[13px] leading-relaxed`;
    const info = {
        productos: 'Se usa al agregar un producto y con «Analizar con IA» en el paso Productos: define cómo analiza el producto y propone dónde comprarlo.',
        asistente: 'Se usa en el asistente «Crear con asistente» del listado de solicitudes: analiza el elemento que busca el cliente (foto, referencia o descripción) junto con el historial de compras. También se puede editar desde el propio asistente.',
        localizacion: 'Se usa en el módulo Localización: redacta el correo de solicitud de cotización a cada proveedor (idioma, piezas, qué se le pide y qué no se le puede decir). También se puede editar desde el propio módulo.',
        correo: 'Se usa con «Redactar con IA» en el paso Detalle y correo: define el tono, la estructura y lo que puede o no decir el correo al cliente.',
    };

    function pintar() {
        cont.innerHTML = `
            <h3 class="mb-1 text-sm font-bold">IA de solicitudes: instrucciones</h3>
            <p class="mb-4 text-sm text-muted-foreground">Edita lo que se le pide a la IA en la ficha de solicitudes. Usa el proveedor predeterminado de «Proveedores y tokens». El formato de la respuesta lo añade el sistema, así que puedes cambiar el texto libremente. Dejar un texto vacío vuelve al valor por defecto.</p>
            ${Object.entries(prompts).map(([k, p]) => `
                <div class="mb-5 rounded-xl border border-border p-4" data-prompt="${k}">
                    <div class="mb-1 flex flex-wrap items-center gap-2">
                        <h4 class="text-sm font-semibold">${esc(p.nombre)}</h4>
                        ${p.personalizado ? '<span class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary">Personalizado</span>' : '<span class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">Por defecto</span>'}
                    </div>
                    <p class="mb-2 text-xs text-muted-foreground">${esc(info[k])}</p>
                    <textarea class="${AREA}" data-texto maxlength="6000">${esc(p.texto)}</textarea>
                    <p class="mt-1 text-[11px] text-muted-foreground"><b>Formato fijo que se añade:</b> ${esc(p.formato)}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" class="${BTN_PRI}" data-guardar="${k}"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
                        <button type="button" class="${BTN}" data-defecto="${k}"><i class="fa-solid fa-rotate-left"></i> Volver al texto por defecto</button>
                    </div>
                </div>`).join('')}`;
    }

    cont.addEventListener('click', async (ev) => {
        const g = ev.target.closest('[data-guardar]');
        const d = ev.target.closest('[data-defecto]');
        const k = g?.dataset.guardar ?? d?.dataset.defecto;
        if (!k) return;
        try {
            if (d && !(await Swal.fire({ icon: 'warning', text: '¿Volver al texto por defecto de esta instrucción?', showCancelButton: true, confirmButtonText: 'Volver', cancelButtonText: 'Cancelar' })).isConfirmed) return;
            const texto = d ? '' : cont.querySelector(`[data-prompt="${k}"] [data-texto]`).value;
            prompts = (await api(datos.urls.promptsSolicitud, 'PUT', { [k]: texto })).prompts;
            pintar();
            aviso('success', 'Instrucción guardada');
        } catch (err) { aviso('error', err.message); }
    });
    pintar();
}

pestanas();
solicitudesIa();
proceso();
panel();
proveedores();
terceros();
