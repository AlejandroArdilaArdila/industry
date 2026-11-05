// Localización: de una solicitud toma los productos que hay que cotizar y se los pide por correo a uno o varios proveedores.
//   1 Productos → 2 Proveedores (sugeridos por compras previas, búsqueda, nuevos; varios correos por proveedor) →
//   3 Correos (uno por proveedor, redactado con IA) → 4 Revisión y envío.
import '../../css/dashboard.css';
import '../../css/localizacion.css';
import Swal from 'sweetalert2';
import { toast } from '../panel/ficha-modal.js';
import { esc } from '../panel/dashboard-util.js';
import { iniciarKanban, iniciales } from '../panel/kanban-lectura.js';
import { iniciarProceso } from '../panel/dashboard-proceso.js';
import { ActividadFichaModal } from '../panel/actividades-ficha.js';

const config = window.LOCALIZACION_CONFIG;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const CLAVE_LADO = 'ficha-panel-lado'; // el mismo lado que eligió el usuario en las demás fichas
const PASOS = ['Productos', 'Proveedores', 'Correos', 'Envío'];
const ICONOS = ['fa-boxes-stacked', 'fa-industry', 'fa-envelope', 'fa-paper-plane'];
const DIAS_ESPERA = 3;
const COLOR_ESTADO = { 1: '#2563eb', 2: '#0891b2', 3: '#0891b2', 6: '#0891b2' };
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const fechaTxt = (iso) => (iso ? new Date(`${iso.slice(0, 10)}T00:00:00`).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

async function pedir(url, metodo = 'GET', datos = null) {
    const r = await fetch(url, {
        method: metodo,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', ...(datos ? { 'Content-Type': 'application/json' } : {}) },
        body: datos ? JSON.stringify(datos) : undefined,
    });
    const json = await r.json().catch(() => ({}));
    if (!r.ok) {
        const e = new Error(json.message || Object.values(json.errors ?? {})[0]?.[0] || `Error ${r.status}`);
        e.datos = json;
        throw e;
    }
    return json;
}

/** Deja solo HTML de formato (sin scripts, estilos ni manejadores de eventos). */
function limpiarHtml(html) {
    const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
    const raiz = doc.body.firstElementChild;
    raiz.querySelectorAll('script,style,iframe,object,embed,link,meta,form,input,button,img,svg').forEach((n) => n.remove());
    raiz.querySelectorAll('*').forEach((n) => {
        [...n.attributes].forEach((a) => {
            if (!(a.name.toLowerCase() === 'href' && /^(https?:|mailto:|tel:)/i.test(a.value.trim()))) n.removeAttribute(a.name);
        });
    });
    return raiz.innerHTML;
}

// ------------------------------------------------------------------ tablero

function tarjeta(s) {
    const c = COLOR_ESTADO[s.estado] ?? '#64748b';
    const nivel = s.correos === 0 ? (s.productos ? 'ambar' : 'rojo') : s.diasEnvio > DIAS_ESPERA ? 'rojo' : 'verde';
    const ultimo = s.ultimoSeguimiento;

    return `<article class="kb-card mora-${nivel}" data-id="${s.id}">
        <div class="kb-card-top">
            <a href="#" class="kb-ref" data-acc="abrir" title="Ver seguimiento de la solicitud"><i class="fa-solid fa-location-dot"></i>${esc(s.ref)}</a>
            <span class="kb-mora" title="Días desde la creación de la solicitud">${s.dias} d</span>
        </div>
        <div class="kb-cliente"><i class="fa-solid fa-building"></i><span>${esc(s.cliente || 'Sin cliente')}</span></div>
        <p class="kb-asunto" title="${esc(s.asunto)}">${esc(s.asunto) || '<em>Sin asunto</em>'}</p>
        <div class="kb-meta">
            <span class="kb-chip estado" style="--e:${c}">${esc(s.estadoNombre)}</span>
            <span class="kb-chip" title="Productos"><i class="fa-solid fa-box"></i>${s.productos}</span>
            ${s.correos ? `<span class="kb-chip ok" title="Último correo a proveedores: ${esc(fechaTxt(s.ultimoEnvio))}"><i class="fa-solid fa-paper-plane"></i>${s.correos} · hace ${s.diasEnvio} d</span>` : '<span class="kb-chip" title="Aún sin correo a proveedores"><i class="fa-regular fa-envelope"></i></span>'}
        </div>
        ${ultimo ? `<p class="kb-seg" title="${esc(ultimo.texto)}"><i class="fa-solid fa-comment-dots"></i><span>${esc(ultimo.texto)}</span>${ultimo.fecha ? `<time>${fechaTxt(ultimo.fecha).replace(/ de? \d{4}$/, '')}</time>` : ''}</p>` : '<p class="kb-seg vacio"><i class="fa-regular fa-comment"></i><span>Sin seguimientos</span></p>'}
        <footer class="kb-pie">
            <span class="kb-avatar" title="${esc(s.creador || '')}">${esc(iniciales(s.creador))}</span>
            <button type="button" class="kb-seguir" data-acc="seguimiento" title="Registrar seguimiento"><i class="fa-solid fa-comment-medical"></i></button>
            <button type="button" class="kb-seguir lleno" data-acc="localizar" ${s.productos ? '' : 'disabled title="Agrega productos a la solicitud"'}><i class="fa-solid fa-location-dot"></i>Localizar</button>
        </footer>
    </article>`;
}

// ------------------------------------------------------------------ asistente

const provVacio = (o) => ({
    key: Math.random().toString(36).slice(2, 8), id: null, nombre: '', pais: null, exterior: false, nuevo: false, correos: [], compras: null,
    idioma: 'es', instrucciones: '', asunto: '', cuerpo: '', estado: 'pendiente', error: null, ...o,
});

class Asistente {
    constructor(id, onListo) {
        Object.assign(this, { id, onListo, paso: 0, sol: null, prods: [], provs: [], sugeridos: [], resultados: null, firma: '', incluirFirma: true, busqueda: [], enviando: false, cargando: true });
    }

    q(s) { return this.dlg.querySelector(s); }

    abrir() {
        let lado = 'derecha';
        try { lado = localStorage.getItem(CLAVE_LADO) === 'izquierda' ? 'izquierda' : 'derecha'; } catch { /* sin almacenamiento */ }
        this.abierto = true;
        this.overlay = document.createElement('div');
        this.overlay.className = 'ficha-overlay';
        this.overlay.dataset.lado = lado;
        this.overlay.innerHTML = `<div role="dialog" aria-modal="true" aria-label="Localización de proveedores" class="ficha-dialog loc-panel flex flex-col overflow-hidden border border-border bg-card text-card-foreground">
            <header class="loc-cab"><div class="loc-cab-txt"><b data-titulo>Localización</b><small data-sub></small></div>
                <nav class="loc-pasos" data-pasos></nav>
                <div class="loc-ctl">
                    <button type="button" data-accion-lado title="Mover el panel al otro lado"><i class="fa-solid fa-arrow-right-arrow-left"></i></button>
                    <button type="button" data-accion-ancho="compacto" title="Reducir el panel"><i class="fa-solid fa-compress"></i></button>
                    <button type="button" data-accion-ancho="maximo" title="Maximizar"><i class="fa-solid fa-maximize"></i></button>
                    <button type="button" data-cerrar title="Cerrar" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </header>
            <div class="loc-cuerpo" data-cuerpo></div>
            <footer class="loc-pie"><button type="button" class="loc-btn" data-atras><i class="fa-solid fa-arrow-left"></i>Atrás</button><span data-aviso></span><button type="button" class="loc-btn primario" data-sig>Continuar<i class="fa-solid fa-arrow-right"></i></button></footer>
        </div>`;
        this.dlg = this.overlay.firstElementChild;
        this.overlay.addEventListener('mousedown', (e) => { if (e.target === this.overlay) this.cerrar(); });
        this.dlg.addEventListener('click', (e) => this.alClic(e));
        this.dlg.addEventListener('input', (e) => this.alEscribir(e));
        this.dlg.addEventListener('change', (e) => this.alCambiar(e));
        this.dlg.addEventListener('paste', (e) => {
            if (!e.target.closest?.('[data-editor]')) return;
            e.preventDefault();
            document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
        });
        this.tecla = (e) => { if (e.key === 'Escape' && !document.querySelector('.swal2-container')) this.cerrar(); };
        document.addEventListener('keydown', this.tecla);
        document.body.appendChild(this.overlay);
        document.documentElement.style.overflow = 'hidden';
        requestAnimationFrame(() => requestAnimationFrame(() => { this.overlay.classList.add('is-open'); this.dlg.classList.add('is-open'); }));
        this.pintar();
        this.cargar();
    }

    async cargar() {
        try {
            const [sol, ctx] = await Promise.all([pedir(config.solicitudUrl.replace('__ID__', this.id)), pedir(config.contextoUrl).catch(() => ({ firma: '' }))]);
            this.sol = sol;
            this.firma = ctx.firma ?? '';
            this.prods = sol.productos.map((p) => ({ ...p, on: true }));
        } catch (e) {
            this.errorCarga = e.message;
        }
        this.cargando = false;
        this.pintar();
    }

    async cerrar() {
        if (this.provs.length && !this.resultados && !this.provs.some((p) => p.estado === 'enviado')) {
            const r = await Swal.fire({ icon: 'question', text: 'Hay proveedores sin enviar. ¿Salir de todos modos?', showCancelButton: true, confirmButtonText: 'Salir', cancelButtonText: 'Seguir aquí' });
            if (!r.isConfirmed) return;
        }
        if (!this.abierto) return;
        this.abierto = false;
        document.removeEventListener('keydown', this.tecla);
        this.overlay.classList.remove('is-open');
        this.dlg.classList.remove('is-open');
        setTimeout(() => { this.overlay.remove(); document.documentElement.style.overflow = ''; }, 420);
        this.onListo?.();
    }

    cambiarLado() {
        const nuevo = this.overlay.dataset.lado === 'derecha' ? 'izquierda' : 'derecha';
        this.dlg.classList.remove('is-open');
        setTimeout(() => {
            this.overlay.dataset.lado = nuevo;
            try { localStorage.setItem(CLAVE_LADO, nuevo); } catch { /* sin almacenamiento */ }
            void this.dlg.offsetWidth;
            this.dlg.classList.add('is-open');
        }, 320);
    }

    /** Alterna el ancho: por defecto ocupa todo menos una franja; «compacto» lo reduce y «maximo» usa toda la ventana. */
    cambiarAncho(modo) {
        this.dlg.dataset.ancho = this.dlg.dataset.ancho === modo ? '' : modo;
        const a = this.dlg.dataset.ancho;
        this.q('[data-accion-ancho=compacto] i').className = `fa-solid ${a === 'compacto' ? 'fa-expand' : 'fa-compress'}`;
        this.q('[data-accion-ancho=compacto]').title = a === 'compacto' ? 'Ampliar el panel' : 'Reducir el panel';
        this.q('[data-accion-ancho=maximo] i').className = `fa-solid ${a === 'maximo' ? 'fa-minimize' : 'fa-maximize'}`;
        this.q('[data-accion-ancho=maximo]').title = a === 'maximo' ? 'Restaurar' : 'Maximizar';
    }

    // ------------------------------------------------------------ pintado

    get piezas() { return this.prods.filter((p) => p.on); }
    get provsActivos() { return this.provs.filter((p) => p.estado !== 'enviado'); }
    correosDe(p) { return p.correos.filter((c) => c.on).map((c) => c.email); }

    pintar() {
        const sol = this.sol;
        this.q('[data-titulo]').textContent = sol ? `Localizar ${sol.ref}` : 'Localización';
        this.q('[data-sub]').textContent = sol ? `${sol.cliente ?? ''} · ${sol.asunto || 'Sin asunto'}` : '';
        this.q('[data-pasos]').innerHTML = PASOS.map((n, i) => `<button type="button" class="loc-paso ${i === this.paso ? 'on' : ''} ${i < this.paso ? 'hecho' : ''}" data-ir="${i}" ${sol ? '' : 'disabled'}><i class="fa-solid ${ICONOS[i]}"></i><span>${n}</span></button>`).join('');
        const cuerpo = this.q('[data-cuerpo]');
        const scroll = cuerpo.scrollTop;
        if (this.cargando) cuerpo.innerHTML = '<div class="loc-vacio"><i class="fa-solid fa-circle-notch fa-spin"></i></div>';
        else if (this.errorCarga) cuerpo.innerHTML = `<div class="loc-vacio error">${esc(this.errorCarga)}</div>`;
        else cuerpo.innerHTML = [this.vistaProductos, this.vistaProveedores, this.vistaCorreos, this.vistaEnvio][this.paso].call(this);
        cuerpo.scrollTop = scroll;
        this.q('[data-atras]').style.visibility = this.paso === 0 || this.resultados ? 'hidden' : 'visible';
        const sig = this.q('[data-sig]');
        sig.innerHTML = this.paso === 3 ? '<i class="fa-solid fa-paper-plane"></i>Enviar correos' : 'Continuar<i class="fa-solid fa-arrow-right"></i>';
        sig.style.display = this.resultados ? 'none' : '';
        sig.disabled = this.cargando || !!this.errorCarga || this.enviando;
    }

    vistaProductos() {
        const enviados = this.sol.enviados;
        const previos = enviados.length
            ? `<div class="loc-aviso"><i class="fa-solid fa-circle-info"></i><div><b>Ya se pidió cotización de esta solicitud a ${new Set(enviados.map((e) => e.email)).size} correo(s):</b>
                ${enviados.slice(0, 8).map((e) => `<span class="loc-mini">${esc(e.proveedor ?? e.email)} · ${fechaTxt(e.fecha)}</span>`).join('')}${enviados.length > 8 ? `<span class="loc-mini">+${enviados.length - 8} más</span>` : ''}</div></div>`
            : '';

        return `${previos}<h3 class="loc-h">¿Qué productos se van a cotizar?</h3>
            <div class="loc-prods">${this.prods.map((p, i) => `
                <div class="loc-prod ${p.on ? 'on' : ''}">
                    <label class="loc-chk"><input type="checkbox" data-prod-on="${i}" ${p.on ? 'checked' : ''}><span></span></label>
                    <div class="loc-prod-txt"><b>${esc(p.descripcion || p.ref || 'Sin descripción')}</b><small>${[p.ref, p.modelo].filter(Boolean).map(esc).join(' · ') || 'Sin referencia'}</small></div>
                    <label class="loc-cant">Cant.<input type="number" min="0" step="any" value="${p.cantidad}" data-prod-cant="${i}"></label>
                </div>`).join('') || '<div class="loc-vacio">La solicitud no tiene productos.</div>'}</div>`;
    }

    vistaProveedores() {
        const ya = new Set(this.provs.map((p) => p.id).filter(Boolean));
        const tarjetaBusqueda = (p, i, lista) => `<div class="loc-res ${p.compras?.ordenes || p.compras?.productos ? 'comprado' : ''} ${ya.has(p.id) ? 'agregado' : ''}">
            <div class="loc-res-txt"><b>${p.compras?.productos ? '<i class="fa-solid fa-star"></i>' : ''}${esc(p.nombre)}</b>
                <small>${[p.pais, p.ciudad].filter(Boolean).map(esc).join(' · ') || 'Sin país'}${p.exterior ? ' · exterior' : ''}</small>
                ${this.etiquetaCompra(p)}</div>
            <button type="button" class="loc-btn ${ya.has(p.id) ? '' : 'primario'}" data-add-prov="${lista}:${i}" ${ya.has(p.id) ? 'disabled' : ''}>${ya.has(p.id) ? '<i class="fa-solid fa-check"></i>Agregado' : '<i class="fa-solid fa-plus"></i>Agregar'}</button>
        </div>`;
        const sug = this.sugeridos.length
            ? `<h3 class="loc-h"><i class="fa-solid fa-star"></i> Ya nos vendieron estos productos</h3><div class="loc-res-lista">${this.sugeridos.map((p, i) => tarjetaBusqueda(p, i, 's')).join('')}</div>`
            : '';

        return `<div class="loc-dos">
            <section>
                ${sug}
                <h3 class="loc-h">Buscar proveedor</h3>
                <label class="loc-buscar grande"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-prov-q placeholder="Nombre, NIT o correo del proveedor" autocomplete="off"></label>
                <div class="loc-res-lista" data-busqueda>${this.htmlBusqueda(tarjetaBusqueda)}</div>
                <button type="button" class="loc-btn" data-nuevo-prov><i class="fa-solid fa-plus"></i>Proveedor nuevo (no está en el sistema)</button>
            </section>
            <section>
                <h3 class="loc-h">Proveedores a los que se pedirá cotización (${this.provs.length})</h3>
                ${this.provs.map((p) => this.tarjetaProveedor(p)).join('') || '<div class="loc-vacio chico">Agrega uno o varios proveedores. Al elegir uno del sistema se sugieren sus correos.</div>'}
            </section>
        </div>`;
    }

    htmlBusqueda(tarjeta) {
        if (this.cargandoBusqueda) return '<div class="loc-vacio chico"><i class="fa-solid fa-circle-notch fa-spin"></i></div>';
        if (this.busquedaTxt && !this.busqueda.length) return '<div class="loc-vacio chico">Sin resultados. Puedes crear el proveedor nuevo.</div>';
        const ya = new Set(this.provs.map((p) => p.id).filter(Boolean));
        return this.busqueda.map((p, i) => `<div class="loc-res ${p.compras?.productos ? 'comprado' : ''} ${ya.has(p.id) ? 'agregado' : ''}">
            <div class="loc-res-txt"><b>${p.compras?.productos ? '<i class="fa-solid fa-star"></i>' : ''}${esc(p.nombre)}</b><small>${[p.pais, p.ciudad].filter(Boolean).map(esc).join(' · ') || 'Sin país'}</small>${this.etiquetaCompra(p)}</div>
            <button type="button" class="loc-btn ${ya.has(p.id) ? '' : 'primario'}" data-add-prov="b:${i}" ${ya.has(p.id) ? 'disabled' : ''}>${ya.has(p.id) ? '<i class="fa-solid fa-check"></i>Agregado' : '<i class="fa-solid fa-plus"></i>Agregar'}</button></div>`).join('');
    }

    etiquetaCompra(p) {
        const c = p.compras;
        if (!c || (!c.productos && !c.ordenes)) return '';
        const que = c.detalle.slice(0, 2).map((d) => esc(d.ref || d.label)).join(', ') + (c.detalle.length > 2 ? ` y ${c.detalle.length - 2} más` : '');

        return `<span class="loc-compra"><i class="fa-solid fa-cart-shopping"></i>${c.ordenes ? `${c.ordenes} orden${c.ordenes === 1 ? '' : 'es'} de compra · ` : ''}${Object.values(c.origen).includes('Proveedor de la marca') && !c.ordenes ? 'Proveedor de la marca · ' : ''}${que}${c.ultima ? ` · ${fechaTxt(c.ultima)}` : ''}</span>`;
    }

    tarjetaProveedor(p) {
        return `<div class="loc-prov ${p.compras?.productos ? 'comprado' : ''}" data-prov="${p.key}">
            <div class="loc-prov-cab">
                ${p.nuevo ? `<input class="loc-in" data-prov-nombre placeholder="Nombre del proveedor" value="${esc(p.nombre)}">` : `<b>${p.compras?.productos ? '<i class="fa-solid fa-star"></i>' : ''}${esc(p.nombre)}</b>`}
                ${p.nuevo ? '<span class="loc-tag">Nuevo</span>' : ''}${p.exterior ? '<span class="loc-tag">Exterior</span>' : ''}
                <button type="button" class="loc-icono" data-quitar-prov="${p.key}" title="Quitar proveedor"><i class="fa-solid fa-trash-can"></i></button>
            </div>
            ${this.etiquetaCompra(p)}
            <div class="loc-correos">${p.correos.map((c, i) => `<label class="loc-correo ${c.on ? 'on' : ''}" title="${esc(c.nombre)}"><input type="checkbox" data-correo-on="${p.key}:${i}" ${c.on ? 'checked' : ''}><span>${esc(c.email)}</span><small>${esc(c.nombre)}</small></label>`).join('') || '<small class="loc-sin">Sin correos: agrega al menos uno.</small>'}</div>
            <div class="loc-agregar-correo"><input class="loc-in" type="email" data-correo-nuevo="${p.key}" placeholder="Agregar correo (Enter)" maxlength="120"><button type="button" class="loc-btn" data-correo-add="${p.key}"><i class="fa-solid fa-plus"></i></button></div>
        </div>`;
    }

    vistaCorreos() {
        if (!this.provs.length) return '<div class="loc-vacio">Primero agrega al menos un proveedor.</div>';
        const pend = this.provs.filter((p) => !p.cuerpo).length;

        return `<div class="loc-barra-ia"><div><b>Un correo por proveedor.</b> La IA lo arma con las piezas elegidas${'' /* sin datos del cliente */}, en español o inglés, y agradece lo que ya nos vendió ese proveedor.</div>
            <button type="button" class="loc-btn" data-editar-prompt title="Instrucciones de la IA para estos correos"><i class="fa-solid fa-sliders"></i>Instrucciones de la IA</button>
            <button type="button" class="loc-btn primario" data-redactar-todos ${pend ? '' : 'disabled'}><i class="fa-solid fa-wand-magic-sparkles"></i>Redactar los ${pend || ''} pendientes con IA</button></div>
            ${this.provs.map((p) => this.tarjetaCorreo(p)).join('')}`;
    }

    tarjetaCorreo(p) {
        const redactando = p.estado === 'redactando';

        return `<div class="loc-mail ${p.estado}" data-mail="${p.key}">
            <div class="loc-mail-cab">
                <b>${p.compras?.productos ? '<i class="fa-solid fa-star"></i>' : ''}${esc(p.nombre || 'Proveedor sin nombre')}</b>
                <small>${esc(this.correosDe(p).join(', ') || 'sin correos')}</small>
                <div class="loc-idioma" role="group" aria-label="Idioma">
                    <button type="button" class="${p.idioma === 'es' ? 'on' : ''}" data-idioma="${p.key}:es">ES</button><button type="button" class="${p.idioma === 'en' ? 'on' : ''}" data-idioma="${p.key}:en">EN</button>
                </div>
            </div>
            <div class="loc-ia-fila">
                <input class="loc-in" data-instr="${p.key}" placeholder="Indicaciones opcionales para la IA (ej. pedir también el peso y el incoterm FCA)" value="${esc(p.instrucciones)}" maxlength="1000">
                <button type="button" class="loc-btn primario" data-redactar="${p.key}" ${redactando ? 'disabled' : ''}>${redactando ? '<i class="fa-solid fa-circle-notch fa-spin"></i>Redactando…' : `<i class="fa-solid fa-wand-magic-sparkles"></i>${p.cuerpo ? 'Redactar de nuevo' : 'Redactar con IA'}`}</button>
            </div>
            ${p.error ? `<div class="loc-error"><i class="fa-solid fa-triangle-exclamation"></i>${esc(p.error)}</div>` : ''}
            <input class="loc-in" data-asunto="${p.key}" placeholder="Asunto" value="${esc(p.asunto)}" maxlength="250">
            <div class="loc-editor" contenteditable="true" data-editor="${p.key}" data-ph="El correo aparecerá aquí. Puedes escribirlo tú o pedirle a la IA que lo redacte.">${p.cuerpo}</div>
        </div>`;
    }

    vistaEnvio() {
        const lis = this.provs;
        const sinDatos = lis.filter((p) => p.estado !== 'enviado' && (!p.cuerpo.trim() || !p.asunto.trim() || !this.correosDe(p).length));
        if (this.resultados) {
            return `<h3 class="loc-h">Resultado del envío</h3>${lis.map((p) => `<div class="loc-res-env ${p.estado === 'enviado' ? 'ok' : 'mal'}"><i class="fa-solid ${p.estado === 'enviado' ? 'fa-circle-check' : 'fa-circle-xmark'}"></i>
                <div><b>${esc(p.nombre)}</b><small>${p.estado === 'enviado' ? `Enviado a ${esc(this.correosDe(p).join(', '))}` : esc(p.error || 'No se envió')}</small></div></div>`).join('')}
                <div class="loc-fin"><button type="button" class="loc-btn primario" data-cerrar>Listo</button>${lis.some((p) => p.estado !== 'enviado') ? '<button type="button" class="loc-btn" data-reintentar><i class="fa-solid fa-rotate-right"></i>Reintentar los fallidos</button>' : ''}</div>`;
        }

        return `<h3 class="loc-h">Revisa antes de enviar</h3>
            ${sinDatos.length ? `<div class="loc-aviso warn"><i class="fa-solid fa-triangle-exclamation"></i><div>Falta asunto, correo o correos en: <b>${sinDatos.map((p) => esc(p.nombre || 'proveedor')).join(', ')}</b>. Vuelve al paso anterior.</div></div>` : ''}
            <div class="loc-rev">${lis.filter((p) => p.estado !== 'enviado').map((p) => `<div class="loc-rev-fila"><div><b>${esc(p.nombre)}</b><small>${esc(this.correosDe(p).join(', '))}</small></div><div class="loc-rev-asunto">${esc(p.asunto || '—')}</div>
                <button type="button" class="loc-btn" data-ver="${p.key}"><i class="fa-regular fa-eye"></i>Ver</button></div>`).join('')}</div>
            <label class="loc-check-firma"><input type="checkbox" data-firma ${this.incluirFirma ? 'checked' : ''}> Agregar mi firma al final de cada correo</label>
            <p class="loc-nota"><i class="fa-solid fa-circle-info"></i> Se enviará un correo por proveedor (con todos sus destinatarios) y la solicitud pasará a «en solicitud de cotización» ${this.provs.some((p) => p.exterior) ? 'exterior' : 'local'}.</p>`;
    }

    // ------------------------------------------------------------ eventos

    alClic(e) {
        const b = (s) => e.target.closest(s);
        if (b('[data-cerrar]')) return this.cerrar();
        if (b('[data-accion-lado]')) return this.cambiarLado();
        let w;
        if ((w = b('[data-accion-ancho]'))) return this.cambiarAncho(w.dataset.accionAncho);
        if (b('[data-atras]')) return this.ir(this.paso - 1);
        if (b('[data-sig]')) return this.paso === 3 ? this.enviar() : this.ir(this.paso + 1);
        let x;
        if ((x = b('[data-ir]'))) return this.ir(Number(x.dataset.ir));
        if ((x = b('[data-add-prov]'))) return this.agregarProveedor(x.dataset.addProv);
        if (b('[data-nuevo-prov]')) return this.nuevoProveedor();
        if ((x = b('[data-quitar-prov]'))) { this.provs = this.provs.filter((p) => p.key !== x.dataset.quitarProv); return this.pintar(); }
        if ((x = b('[data-correo-add]'))) return this.agregarCorreo(x.dataset.correoAdd);
        if ((x = b('[data-idioma]'))) { const [k, i] = x.dataset.idioma.split(':'); this.prov(k).idioma = i; return this.pintar(); }
        if ((x = b('[data-redactar]'))) return this.redactar(x.dataset.redactar);
        if (b('[data-redactar-todos]')) return this.redactarTodos();
        if (b('[data-editar-prompt]')) return this.editarPrompt();
        if ((x = b('[data-ver]'))) return this.vistaPrevia(x.dataset.ver);
        if (b('[data-reintentar]')) { this.resultados = null; this.provs.forEach((p) => { if (p.estado === 'error') p.estado = 'listo'; }); return this.enviar(); }
    }

    alEscribir(e) {
        const t = e.target;
        if (t.matches('[data-prod-cant]')) this.prods[Number(t.dataset.prodCant)].cantidad = Number(t.value) || 0;
        else if (t.matches('[data-prov-q]')) this.buscarProveedor(t.value);
        else if (t.matches('[data-prov-nombre]')) this.prov(t.closest('[data-prov]').dataset.prov).nombre = t.value;
        else if (t.matches('[data-instr]')) this.prov(t.dataset.instr).instrucciones = t.value;
        else if (t.matches('[data-asunto]')) this.prov(t.dataset.asunto).asunto = t.value;
        else if (t.matches('[data-editor]')) { const p = this.prov(t.dataset.editor); p.cuerpo = t.innerHTML; p.estado = p.cuerpo.trim() ? 'listo' : 'pendiente'; }
    }

    alCambiar(e) {
        const t = e.target;
        if (t.matches('[data-prod-on]')) { this.prods[Number(t.dataset.prodOn)].on = t.checked; this.pintar(); }
        else if (t.matches('[data-correo-on]')) { const [k, i] = t.dataset.correoOn.split(':'); this.prov(k).correos[Number(i)].on = t.checked; this.pintar(); }
        else if (t.matches('[data-firma]')) this.incluirFirma = t.checked;
    }

    prov(k) { return this.provs.find((p) => p.key === k); }

    async ir(n) {
        if (n > this.paso) {
            if (this.paso === 0) {
                if (!this.piezas.length) return this.aviso('Elige al menos un producto.');
                this.cargarSugeridos();
            }
            if (this.paso === 1) {
                if (!this.provs.length) return this.aviso('Agrega al menos un proveedor.');
                const mal = this.provs.find((p) => !p.nombre.trim() || !this.correosDe(p).length);
                if (mal) return this.aviso(`«${mal.nombre.trim() || 'Proveedor nuevo'}» necesita nombre y al menos un correo marcado.`);
            }
            if (this.paso === 2 && this.provs.some((p) => p.estado === 'redactando')) return this.aviso('Espera a que termine la IA.');
        }
        this.paso = Math.max(0, Math.min(3, n));
        this.pintar();
        if (this.paso === 1 && !this.sugeridosCargados) this.cargarSugeridos();
    }

    aviso(txt) {
        const a = this.q('[data-aviso]');
        a.textContent = txt;
        clearTimeout(this.tAviso);
        this.tAviso = setTimeout(() => { a.textContent = ''; }, 4000);
    }

    // ------------------------------------------------------------ proveedores

    async cargarSugeridos() {
        const ids = this.piezas.map((p) => p.fk_product).filter(Boolean);
        this.sugeridosCargados = true;
        try {
            this.sugeridos = (await pedir(config.sugeridosUrl, 'POST', { productos: ids })).items;
        } catch { this.sugeridos = []; }
        if (this.paso === 1) this.pintar();
    }

    buscarProveedor(txt) {
        clearTimeout(this.tBusca);
        this.busquedaTxt = txt.trim();
        if (this.busquedaTxt.length < 2) { this.busqueda = []; this.refrescarBusqueda(); return; }
        this.tBusca = setTimeout(async () => {
            this.cargandoBusqueda = true;
            this.refrescarBusqueda();
            try {
                const ids = this.piezas.map((p) => p.fk_product).filter(Boolean).join(',');
                this.busqueda = (await pedir(`${config.proveedoresUrl}?${new URLSearchParams({ q: this.busquedaTxt, productos: ids })}`)).items;
            } catch { this.busqueda = []; }
            this.cargandoBusqueda = false;
            this.refrescarBusqueda();
        }, 300);
    }

    // Solo se repinta la lista de resultados: repintar todo el paso le quitaría el foco al buscador.
    refrescarBusqueda() {
        const c = this.q('[data-busqueda]');
        if (c) c.innerHTML = this.htmlBusqueda();
    }

    async agregarProveedor(ref) {
        const [lista, i] = ref.split(':');
        const origen = (lista === 's' ? this.sugeridos : this.busqueda)[Number(i)];
        if (!origen || this.provs.some((p) => p.id === origen.id)) return;
        // Trae la ficha completa (contactos y correos usados antes) para sugerir los correos.
        let f = origen;
        try { f = await pedir(`${config.proveedorUrl.replace('__ID__', origen.id)}?productos=${this.piezas.map((p) => p.fk_product).filter(Boolean).join(',')}`); } catch { /* se usa lo que ya se tiene */ }
        // Se marcan los correos de contactos y del tercero (máximo 3); los demás quedan a un clic.
        let marcados = 0;
        const correos = f.correos.map((c) => ({ ...c, on: (c.origen !== 'envio' || !f.correos.some((x) => x.origen !== 'envio')) && marcados++ < 3 }));
        this.provs.push(provVacio({ id: f.id, nombre: f.nombre, pais: f.pais, exterior: f.exterior, idioma: f.exterior ? 'en' : 'es', compras: f.compras, correos }));
        this.pintar();
    }

    nuevoProveedor() {
        this.provs.push(provVacio({ nuevo: true }));
        this.pintar();
        this.dlg.querySelector('[data-prov]:last-of-type [data-prov-nombre]')?.focus();
    }

    agregarCorreo(k) {
        const inp = this.dlg.querySelector(`[data-correo-nuevo="${k}"]`);
        const v = inp.value.trim().toLowerCase();
        if (!EMAIL.test(v)) { toast.fire({ icon: 'warning', title: 'Correo no válido' }); return; }
        const p = this.prov(k);
        const ya = p.correos.find((c) => c.email === v);
        if (ya) ya.on = true; else p.correos.push({ email: v, nombre: 'Agregado por ti', origen: 'manual', on: true });
        this.pintar();
        this.dlg.querySelector(`[data-correo-nuevo="${k}"]`)?.focus();
    }

    // ------------------------------------------------------------ correos con IA

    async redactar(k) {
        const p = this.prov(k);
        if (!p || p.estado === 'redactando') return;
        const nombre = (p.nombre || '').trim();
        if (!nombre) { this.aviso('El proveedor necesita nombre.'); return; }
        if (p.cuerpo.trim() && !(await Swal.fire({ icon: 'question', text: 'Esto reemplaza el correo actual. ¿Continuar?', showCancelButton: true, confirmButtonText: 'Redactar', cancelButtonText: 'Cancelar' })).isConfirmed) return;
        p.estado = 'redactando';
        p.error = null;
        this.pintarMail(p);
        try {
            const comprados = (p.compras?.detalle ?? []).map((d) => [d.ref, d.label].filter(Boolean).join(' · '));
            const r = await pedir(config.redactarUrl.replace('__ID__', this.id), 'POST', {
                proveedor: { nombre, pais: p.pais, comprado: comprados },
                piezas: this.piezas.map((x) => ({ descripcion: x.descripcion || x.ref, ref: x.ref, modelo: x.modelo, cantidad: x.cantidad })),
                idioma: p.idioma,
                instrucciones: p.instrucciones || null,
            });
            p.asunto = r.asunto || p.asunto;
            p.cuerpo = limpiarHtml(r.cuerpo);
            p.estado = 'listo';
        } catch (err) {
            p.estado = p.cuerpo.trim() ? 'listo' : 'pendiente';
            p.error = err.message;
        }
        if (this.paso === 2) this.pintar();
    }

    pintarMail(p) {
        // Repinta solo esa tarjeta para no perder lo que se escribe en las otras.
        const el = this.dlg.querySelector(`[data-mail="${p.key}"]`);
        if (el) el.outerHTML = this.tarjetaCorreo(p);
    }

    async redactarTodos() {
        const pend = this.provs.filter((p) => !p.cuerpo.trim() && p.nombre.trim());
        // De a dos a la vez: más rápido que uno por uno sin saturar la IA.
        for (let i = 0; i < pend.length; i += 2) {
            await Promise.all(pend.slice(i, i + 2).map((p) => this.redactar(p.key)));
        }
        this.pintar();
    }

    async editarPrompt() {
        let d;
        try { d = await pedir(config.promptUrl); } catch (e) { return toast.fire({ icon: 'error', title: e.message }); }
        const r = await Swal.fire({
            title: 'Instrucciones de la IA', width: 720, input: 'textarea', inputValue: d.texto, inputAttributes: { rows: 12 },
            html: '<p style="font-size:13px;text-align:left;margin:0 0 6px">Define cómo se redacta el correo a los proveedores. El formato de la respuesta lo añade el sistema. Déjalo vacío para volver al texto por defecto.</p>',
            showCancelButton: true, showDenyButton: d.personalizado, denyButtonText: 'Restablecer', confirmButtonText: 'Guardar', cancelButtonText: 'Cancelar',
            inputValidator: () => null,
        });
        if (r.isDismissed) return;
        try {
            await pedir(config.promptUrl, 'PUT', { texto: r.isDenied ? '' : r.value });
            toast.fire({ icon: 'success', title: 'Instrucciones guardadas' });
        } catch (e) { toast.fire({ icon: 'error', title: e.message }); }
    }

    // ------------------------------------------------------------ envío

    htmlFinal(p) {
        return limpiarHtml(p.cuerpo) + (this.incluirFirma && this.firma ? `<br><br>${this.firma}` : '');
    }

    vistaPrevia(k) {
        const p = this.prov(k);
        const marco = document.createElement('div');
        marco.innerHTML = `<iframe sandbox="" style="width:100%;height:60vh;border:1px solid #e5e7eb;border-radius:8px;background:#fff" srcdoc="${esc(`<body style="font:14px/1.5 Arial,sans-serif;color:#111;margin:12px">${this.htmlFinal(p)}</body>`)}"></iframe>`;
        Swal.fire({ title: esc(p.asunto || 'Sin asunto'), html: marco, width: 760, showConfirmButton: false, showCloseButton: true });
    }

    async enviar() {
        const lista = this.provs.filter((p) => p.estado !== 'enviado');
        const mal = lista.find((p) => !p.cuerpo.trim() || !p.asunto.trim() || !this.correosDe(p).length || !p.nombre.trim());
        if (mal) return this.aviso(`Completa asunto, correo y mensaje de «${mal.nombre || 'proveedor'}».`);
        const n = lista.reduce((a, p) => a + this.correosDe(p).length, 0);
        const ok = await Swal.fire({ icon: 'question', title: `Enviar a ${lista.length} proveedor${lista.length === 1 ? '' : 'es'}`, text: `Se enviarán ${lista.length} correo${lista.length === 1 ? '' : 's'} (${n} destinatario${n === 1 ? '' : 's'}).`, showCancelButton: true, confirmButtonText: 'Enviar', cancelButtonText: 'Revisar' });
        if (!ok.isConfirmed) return;
        this.enviando = true;
        this.pintar();
        this.aviso('Enviando…');
        try {
            const r = await pedir(config.enviarUrl.replace('__ID__', this.id), 'POST', {
                incluir_firma: this.incluirFirma,
                envios: lista.map((p) => ({ proveedor_id: p.id, nombre: p.nombre.trim(), correos: this.correosDe(p), asunto: p.asunto.trim(), cuerpo: limpiarHtml(p.cuerpo), idioma: p.idioma })),
            });
            lista.forEach((p, i) => {
                const x = r.resultados[i];
                p.estado = x?.ok ? 'enviado' : 'error';
                p.error = x?.ok ? null : (x?.error || 'No se pudo enviar.');
            });
            this.resultados = r;
            const buenos = lista.filter((p) => p.estado === 'enviado').length;
            toast.fire({ icon: buenos === lista.length ? 'success' : 'warning', title: `${buenos} de ${lista.length} enviados` });
        } catch (e) {
            toast.fire({ icon: 'error', title: e.message });
        }
        this.enviando = false;
        this.pintar();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const raiz = document.querySelector('.loc');
    if (!raiz) return;
    // Barra de pasos del proceso comercial: estamos en «Localización»; los demás pasos abren sus documentos en el panel de seguimiento.
    const panel = new ActividadFichaModal({ panelUrl: config.panelUrl, csrfToken: csrf, onSaved: () => tablero.recargar() });
    const tablero = iniciarKanban({
        raiz, columnas: config.columnas, urlColumna: config.columnaUrl, csrfToken: csrf, vacio: 'Sin solicitudes',
        seguimiento: { url: config.seguimientoUrl, modulo: 'solicitud' },
        tarjeta,
        alAccion: (acc, s) => {
            if (acc === 'localizar') { if (s.productos) new Asistente(s.id, () => tablero.recargar()).abrir(); } else panel.abrirDocumento('solicitud', s.id);
        },
    });
    iniciarProceso({
        url: config.procesoUrl,
        urlDocumentos: config.procesoDocsUrl,
        getAlcance: () => 'todos',
        activa: 'localizacion',
        compacta: true,
        onAbrir: (modulo, id, lista) => { panel.lista = lista ? { ...lista, vistos: new Set() } : null; panel.abrirDocumento(modulo, id); },
    }).cargar();
});
