// «Crear con asistente»: crea una solicitud paso a paso o contándosela al asistente.
//   1 Cliente (con sus solicitudes y su estado) → 2 Contacto → 3 Pieza(s) que busca (foto, referencia o nombre, descripción dictada)
//   con el historial de compras a la izquierda y el análisis de IA a la derecha → 4 Correo al cliente (plantilla o IA, con vista previa y firma)
//   → 5 Revisión y creación.
// En cualquier momento se le puede escribir o dictar algo como «para el cliente X el producto Y para el contacto Z»: el asistente
// llena los pasos y el usuario solo navega para comprobar. Se vive en un panel lateral (como la ficha) que se puede mover y maximizar.
// La solicitud se crea con los mismos endpoints de la ficha (store + productos), así que queda igual que una creada a mano.
import '../../css/solicitud-asistente.css';
import Swal from 'sweetalert2';
import { toast } from '../panel/ficha-modal.js';
import { esc } from '../panel/dashboard-util.js';

const PASOS = ['Cliente', 'Contacto', 'Pieza', 'Correo', 'Revisión'];
const ICONOS_PASO = ['fa-building', 'fa-address-book', 'fa-box-open', 'fa-envelope', 'fa-clipboard-check'];
const PASO_CORREO = 3;
const PASO_REVISION = 4;
const COLOR_ESTADO = { 1: '#2563eb', 2: '#0891b2', 3: '#0891b2', 6: '#0891b2', 4: '#7c3aed', 5: '#059669', 9: '#64748b' };
const Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;
const CLAVE_LADO = 'ficha-panel-lado'; // el mismo lado que eligió el usuario en las demás fichas
const nf = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 });
const nf2 = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 2 });
// El año de nacimiento suele no estar o ser falso: solo día y mes.
const cumpleTxt = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('es-CO', { day: 'numeric', month: 'long' });
const dinero = (v) => (v >= 1e6 ? `$${nf.format(Math.round(v / 1e6))} M` : `$${nf.format(v)}`);
const fechaTxt = (iso) => (iso ? new Date(`${iso.slice(0, 10)}T00:00:00`).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const hoyIso = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const sumarDias = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
const normalizar = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

const correoVacio = () => ({ asunto: '', cuerpo: '', instrucciones: '', plantilla: '', enviar: false, manual: false, ctx: null, cargando: false, redactando: false, error: null });

/** Deja solo HTML de formato (sin scripts, estilos, manejadores de eventos ni enlaces javascript:). */
function limpiarHtml(html) {
    const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
    const raiz = doc.body.firstElementChild;
    raiz.querySelectorAll('script,style,iframe,object,embed,link,meta,form,input,button,img,svg').forEach((n) => n.remove());
    raiz.querySelectorAll('*').forEach((n) => {
        [...n.attributes].forEach((a) => {
            const permitido = a.name.toLowerCase() === 'href' && /^(https?:|mailto:|tel:)/i.test(a.value.trim());
            if (!permitido) n.removeAttribute(a.name);
        });
        if (n.tagName === 'A') { n.setAttribute('target', '_blank'); n.setAttribute('rel', 'noopener noreferrer'); }
    });

    return raiz.innerHTML;
}
/** Texto o HTML de una plantilla, siempre como HTML limpio. */
const comoHtml = (txt) => (/<[a-z][\s\S]*>/i.test(txt) ? limpiarHtml(txt) : esc(txt).replace(/\r?\n/g, '<br>'));

const itemVacio = () => ({
    id: Math.random().toString(36).slice(2, 8), referencia: '', nombre: '', descripcion: '', cantidad: 1, fk_product: null, vinculo: null,
    foto: null, historial: null, ia: null, iaError: null, cargandoIa: false, cargandoHistorial: false,
});

export class SolicitudAsistente {
    constructor({ urls, csrfToken, onCreada }) {
        Object.assign(this, { urls, csrfToken, onCreada });
    }

    // ------------------------------------------------------------ red

    async pedir(url, metodo = 'GET', datos = null) {
        const r = await fetch(url, {
            method: metodo,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest', ...(datos ? { 'Content-Type': 'application/json' } : {}) },
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

    // ------------------------------------------------------------ ciclo del panel

    abrir() {
        Object.assign(this, {
            paso: 0, maxPaso: 0, cliente: null, datos: null, contacto: null, relacionados: new Set(), items: [itemVacio()], actual: 0, asunto: '', proximo: '',
            creando: false, coinc: {}, claveCoinc: '', cargandoMasPiezas: false, prompts: {}, promptAbierto: false, correo: correoVacio(), pendiente: null, mensaje: '', hablando: false, interpretando: false, abierto: true,
        });
        let lado = 'derecha';
        try { lado = localStorage.getItem(CLAVE_LADO) === 'izquierda' ? 'izquierda' : 'derecha'; } catch { /* sin almacenamiento */ }
        this.overlay = document.createElement('div');
        this.overlay.className = 'ficha-overlay';
        this.overlay.dataset.lado = lado;
        this.overlay.innerHTML = `
            <div role="dialog" aria-modal="true" aria-label="Crear solicitud con asistente" class="ficha-dialog asi flex flex-col overflow-hidden border border-border bg-card text-card-foreground">
                <header class="asi-cab">
                    <div class="asi-titulo"><span class="asi-logo"><i class="fa-solid fa-wand-magic-sparkles"></i></span><div><b>Crear solicitud con asistente</b><small data-sub></small></div></div>
                    <ol class="asi-pasos" data-pasos></ol>
                    <div class="asi-ctl">
                        <button type="button" data-accion-lado title="Mover el panel al otro lado"><i class="fa-solid fa-arrow-right-arrow-left"></i></button>
                        <button type="button" data-accion-ancho="compacto" title="Reducir el panel"><i class="fa-solid fa-compress"></i></button>
                        <button type="button" data-accion-ancho="maximo" title="Maximizar"><i class="fa-solid fa-maximize"></i></button>
                        <button type="button" data-cerrar title="Cerrar" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </header>
                <div class="asi-cuerpo" data-cuerpo></div>
                <div class="asi-habla">
                    <div class="asi-habla-msg" data-habla-msg></div>
                    <div class="asi-habla-fila">
                        <button type="button" class="asi-mic grande" data-mic-habla title="${Reconocimiento ? 'Hablarle al asistente' : 'Tu navegador no permite dictado: usa Chrome o Edge'}" ${Reconocimiento ? '' : 'disabled'}><i class="fa-solid fa-microphone"></i></button>
                        <textarea data-habla rows="1" maxlength="4000" placeholder="Díselo al asistente: «para el cliente… el producto… para el contacto…» o corrige algo («cambia el contacto a…», «agrega un producto…»)"></textarea>
                        <button type="button" class="asi-btn primario" data-enviar-habla><i class="fa-solid fa-paper-plane"></i>Enviar</button>
                    </div>
                </div>
                <footer class="asi-pie"><button type="button" class="asi-btn" data-atras><i class="fa-solid fa-arrow-left"></i>Atrás</button><span data-aviso></span><button type="button" class="asi-btn primario" data-sig>Continuar<i class="fa-solid fa-arrow-right"></i></button></footer>
            </div>`;
        this.dlg = this.overlay.firstElementChild;
        this.overlay.addEventListener('mousedown', (e) => { if (e.target === this.overlay) this.cerrar(); });
        this.dlg.addEventListener('click', (e) => this.alClic(e));
        this.dlg.addEventListener('input', (e) => this.alEscribir(e));
        this.dlg.addEventListener('change', (e) => this.alCambiar(e));
        this.dlg.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey && e.target.matches('[data-habla]')) { e.preventDefault(); this.enviarHabla(); }
        });
        // La barra del editor de correo no debe quitarle el foco al texto (por eso mousedown y no click).
        this.dlg.addEventListener('mousedown', (e) => {
            const b = e.target.closest('[data-cmd]');
            if (!b) return;
            e.preventDefault();
            let valor = null;
            if (b.dataset.cmd === 'createLink') {
                valor = window.prompt('Dirección del enlace (https://…)');
                if (!valor || !/^(https?:|mailto:|tel:)/i.test(valor.trim())) return;
            }
            this.q('[data-editor]')?.focus();
            document.execCommand(b.dataset.cmd, false, valor);
            this.q('[data-editor]')?.dispatchEvent(new Event('input', { bubbles: true }));
        });
        // Pegar siempre como texto plano: evita arrastrar estilos y HTML ajeno al correo.
        this.dlg.addEventListener('paste', (e) => {
            if (!e.target.closest?.('[data-editor]')) return;
            e.preventDefault();
            document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
        });
        this.tecla = (e) => { if (e.key === 'Escape' && !document.querySelector('.swal2-container')) { e.preventDefault(); this.cerrar(); } };
        document.addEventListener('keydown', this.tecla);
        document.body.appendChild(this.overlay);
        document.documentElement.style.overflow = 'hidden';
        this.pintar();
        requestAnimationFrame(() => requestAnimationFrame(() => { this.overlay.classList.add('is-open'); this.dlg.classList.add('is-open'); }));
        ['asistente', 'correo'].forEach((c) => this.pedir(`${this.urls.promptUrl}?clave=${c}`).then((p) => { this.prompts[c] = p; this.pintarPrompt(); }).catch(() => {}));
    }

    async cerrar() {
        const hayDatos = this.cliente || this.items.some((i) => this.itemLleno(i));
        if (hayDatos && !(await Swal.fire({ icon: 'question', title: '¿Salir del asistente?', text: 'Se perderá lo que llevas.', showCancelButton: true, confirmButtonText: 'Salir', cancelButtonText: 'Seguir aquí', reverseButtons: true })).isConfirmed) return;
        this.cerrarYa();
    }

    cerrarYa() {
        if (!this.abierto) return;
        this.abierto = false;
        this.dictado?.stop();
        document.removeEventListener('keydown', this.tecla);
        this.overlay.classList.remove('is-open');
        this.dlg.classList.remove('is-open');
        setTimeout(() => { this.overlay.remove(); document.documentElement.style.overflow = ''; }, 420);
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

    /** Alterna el ancho del panel: por defecto ocupa todo menos una franja; «compacto» lo reduce y «maximo» usa toda la ventana. */
    cambiarAncho(modo) {
        this.dlg.dataset.ancho = this.dlg.dataset.ancho === modo ? '' : modo;
        const a = this.dlg.dataset.ancho;
        this.q('[data-accion-ancho=compacto] i').className = `fa-solid ${a === 'compacto' ? 'fa-expand' : 'fa-compress'}`;
        this.q('[data-accion-ancho=compacto]').title = a === 'compacto' ? 'Ampliar el panel' : 'Reducir el panel';
        this.q('[data-accion-ancho=maximo] i').className = `fa-solid ${a === 'maximo' ? 'fa-minimize' : 'fa-maximize'}`;
        this.q('[data-accion-ancho=maximo]').title = a === 'maximo' ? 'Restaurar' : 'Maximizar';
    }

    q(sel) { return this.dlg.querySelector(sel); }

    // ------------------------------------------------------------ marco y navegación

    /** Piezas con algo escrito, o con foto. */
    piezas() { return this.items.filter((i) => this.itemLleno(i)); }

    /** «Pieza» o «Piezas» según cuántas lleve la solicitud. */
    nombrePaso(i) { return i === 2 && this.piezas().length > 1 ? 'Piezas' : PASOS[i]; }

    /** Qué falta para entrar a un paso (null = se puede entrar): cada paso pide lo de los anteriores. */
    motivoBloqueo(n) {
        if (n >= 1 && !this.datos) return 'Primero elige el cliente.';
        if (n >= 2 && !this.contacto) return 'Elige el contacto principal.';
        if (n >= 3 && !this.piezas().length) return 'Agrega al menos una pieza: referencia, nombre, foto o descripción.';

        return null;
    }

    puedeIr(n) { return this.motivoBloqueo(n) === null; }

    /** Paso con sus datos completos (el círculo lleva un chulo verde). */
    completo(n) {
        return n === 0 ? !!this.datos : n === 1 ? !!this.contacto : n === 2 ? this.piezas().length > 0 : n === PASO_CORREO ? this.correo.cuerpo.trim() !== '' : false;
    }

    htmlPasos() {
        return PASOS.map((p, i) => {
            const hecho = i !== this.paso && this.completo(i);
            const cls = [i === this.paso ? 'on' : '', hecho ? 'hecho' : '', this.puedeIr(i) ? '' : 'bloq'].filter(Boolean).join(' ');

            return `<li><button type="button" data-ir="${i}" class="${cls}" ${i === this.paso ? 'aria-current="step"' : ''} title="${this.puedeIr(i) ? this.nombrePaso(i) : esc(this.motivoBloqueo(i))}">
                <span class="asi-pn"><i class="fa-solid ${ICONOS_PASO[i]}"></i>${hecho ? '<em class="asi-chulo"><i class="fa-solid fa-check"></i></em>' : ''}</span><b>${this.nombrePaso(i)}</b></button></li>`;
        }).join('');
    }

    /** Aviso corto en el pie (por qué no se puede ir a un paso); se borra solo. */
    avisar(txt) {
        const a = this.q('[data-aviso]');
        a.textContent = txt || '';
        clearTimeout(this.tAviso);
        if (txt) this.tAviso = setTimeout(() => { if (this.abierto) a.textContent = ''; }, 4000);
    }

    pintar() {
        this.maxPaso = Math.max(this.maxPaso, this.paso);
        this.q('[data-pasos]').innerHTML = this.htmlPasos();
        this.q('[data-sub]').textContent = this.cliente ? this.cliente.nombre : 'Paso a paso, o cuéntamelo todo de una vez';
        this.q('[data-atras]').style.visibility = this.paso === 0 ? 'hidden' : 'visible';
        const sig = this.q('[data-sig]');
        sig.innerHTML = this.paso === PASO_REVISION ? '<i class="fa-solid fa-circle-check"></i>Crear solicitud' : 'Continuar<i class="fa-solid fa-arrow-right"></i>';
        sig.disabled = this.creando || !this.puedeAvanzar();
        this.q('[data-cuerpo]').innerHTML = [this.vistaCliente, this.vistaContacto, this.vistaElementos, this.vistaCorreo, this.vistaRevision][this.paso].call(this);
        this.q('[data-cuerpo]').dataset.paso = this.paso;
        if (this.paso === 2) { this.pintarResultados(); this.pintarPrompt(); }
        if (this.paso === PASO_CORREO) this.iniciarCorreo();
        if (this.paso === 0 || this.paso === 2) this.cargarCoincidencias();
        this.pintarMensaje();
        this.q('[data-cuerpo]').querySelector('[autofocus]')?.focus();
    }

    puedeAvanzar() {
        if (this.paso === 0) return !!this.datos;
        if (this.paso === 1) return !!this.contacto;
        if (this.paso === 2) return this.piezas().length > 0;
        if (this.paso === PASO_CORREO) return true; // el correo es opcional; si se va a enviar, siguiente() valida que esté completo
        return this.asunto.trim().length > 0;
    }

    itemLleno(i) { return !!(i.referencia.trim() || i.nombre.trim() || i.descripcion.trim() || i.foto); }

    irA(n) {
        if (n === this.paso) return;
        const motivo = this.motivoBloqueo(n);
        if (motivo) { this.avisar(motivo); return; }
        this.avisar('');
        this.paso = n;
        if (n >= PASO_CORREO) { this.items = this.piezas(); this.actual = Math.min(this.actual, this.items.length - 1); }
        if (n === PASO_REVISION && !this.asunto) this.asunto = this.asuntoSugerido();
        this.pintar();
    }

    siguiente() {
        if (this.paso === PASO_CORREO) {
            const m = this.motivoCorreo();
            if (this.correo.enviar && m) { this.avisar(m); return null; }
        }
        if (this.paso === PASO_REVISION) {
            const m = this.correo.enviar ? this.motivoCorreo() : null;
            if (m) { this.avisar(`El correo no está listo: ${m}`); return null; }
            return this.crear();
        }
        return this.irA(this.paso + 1);
    }

    asuntoSugerido() {
        const nombres = this.items.map((i) => i.ia?.identificacion?.nombre || i.nombre || i.referencia || i.descripcion.slice(0, 40)).filter(Boolean);
        const lista = nombres.slice(0, 2).join(' y ');

        return (lista ? `${lista}${nombres.length > 2 ? ` y ${nombres.length - 2} más` : ''}` : 'Solicitud de cotización').slice(0, 150);
    }

    // ------------------------------------------------------------ paso 1: cliente

    vistaCliente() {
        const d = this.datos;

        return `<div class="asi-p1">
            <div class="asi-p1-i">
                <h3>¿Para qué cliente es la solicitud?</h3>
                <div class="asi-buscar"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-q-cliente placeholder="Escribe el nombre, NIT o código del cliente" autocomplete="off" value="${esc(this.cliente?.nombre ?? '')}" ${d ? '' : 'autofocus'}><div class="asi-sugs" data-sugs hidden></div></div>
                ${d ? this.tarjetaCliente(d) + this.seccionPiezas(d) : '<p class="asi-ayuda"><i class="fa-regular fa-lightbulb"></i>Al elegirlo te muestro las solicitudes que ya tiene y en qué estado está cada una, para no duplicar. También puedes decirme todo abajo, con la voz o escribiendo.</p>'}
            </div>
            ${d ? this.listaSolicitudes(d) : ''}
        </div>`;
    }

    tarjetaCliente(d) {
        const r = d.resumen;
        const sub = [d.cliente.nit, d.cliente.ciudad].filter(Boolean).map(esc).join(' · ');
        const hace = r?.ultimaGestion ? Math.max(0, Math.round((Date.now() - new Date(`${r.ultimaGestion}T00:00:00`)) / 86400000)) : null;
        const tipos = r ? [
            { t: 'solicitud', ico: 'fa-inbox', n: r.solicitudes, rot: 'Solicitudes', ult: r.ultimaSolicitud, monto: '' },
            { t: 'cotizacion', ico: 'fa-file-invoice-dollar', n: r.cotizaciones, rot: 'Cotizaciones', ult: r.ultimaCotizacion, monto: r.montoCotizado ? `${dinero(r.montoCotizado)} cotizado` : '' },
            { t: 'orden', ico: 'fa-cart-shopping', n: r.ordenes, rot: 'Órdenes de compra', ult: r.ultimaOrden, monto: r.montoOrdenes ? `${dinero(r.montoOrdenes)} ganado` : '' },
        ] : [];

        // El nombre ya está en el buscador de arriba: aquí solo lo que lo complementa.
        return `<div class="asi-cliente">
            <div class="asi-cliente-cab"><span class="asi-ico"><i class="fa-solid fa-building"></i></span>
                <div><small>${sub || 'Sin datos de contacto'}</small>${r?.ultimaGestion ? `<span class="asi-gestion"><i class="fa-regular fa-clock"></i>Última gestión: <b>${fechaTxt(r.ultimaGestion)}</b>${hace !== null ? ` · ${hace === 0 ? 'hoy' : `hace ${nf.format(hace)} día${hace === 1 ? '' : 's'}`}` : ''}</span>` : ''}</div></div>
            ${tipos.length ? `<div class="asi-tipos-kpi">${tipos.map((k) => `<div class="asi-tipo" style="${this.estiloTipo(d, k.t)}"><span class="asi-tipo-ico"><i class="fa-solid ${k.ico}"></i></span><div><b>${nf.format(k.n)}</b><span>${k.rot}</span>${k.ult ? `<small>última ${fechaTxt(k.ult)}</small>` : '<small>sin movimientos</small>'}${k.monto ? `<em>${k.monto}</em>` : ''}</div></div>`).join('')}</div>` : ''}
            ${this.vistaEfectividad()}
            <p class="asi-ayuda"><i class="fa-solid fa-rotate"></i>Si eliges otro cliente, el contacto, el análisis y el correo se reinician.</p>
        </div>`;
    }

    // ------------------------------------------------------------ efectividad del cliente

    vistaEfectividad() {
        const e = this.efec ?? (this.efec = { periodo: '3m', desde: '', hasta: '', datos: null, cargando: false, error: null });
        const opc = [['1m', 'Último mes'], ['3m', '3 meses'], ['6m', '6 meses'], ['rango', 'Rango']];
        const pct = (v) => (v === null || v === undefined ? '—' : `${nf2.format(v)}%`);
        const r = e.datos;
        let cuerpo;
        if (e.error) cuerpo = `<p class="asi-vacio">${esc(e.error)}</p>`;
        else if (e.periodo === 'rango' && (!e.desde || !e.hasta)) cuerpo = '<p class="asi-vacio">Elige las fechas del rango.</p>';
        else if (!r || e.cargando) cuerpo = '<p class="asi-cargando"><i class="fa-solid fa-spinner fa-spin"></i>Calculando…</p>';
        else if (!r.solicitudes && !r.cotizaciones) cuerpo = '<p class="asi-vacio">Sin solicitudes ni cotizaciones del cliente en este periodo.</p>';
        else {
            const base = Math.max(r.solicitudes, r.cotizaciones, 1);
            const w = (n) => `${Math.max(n > 0 ? 3 : 0, Math.round((n / base) * 100))}%`;
            const tono = r.pctGlobal === null ? '' : r.pctGlobal >= 30 ? 'alta' : r.pctGlobal >= 10 ? 'media' : 'baja';
            cuerpo = `<div class="asi-efec-cuerpo">
                <div class="asi-efec-global ${tono}"><b>${pct(r.pctGlobal)}</b><small>de lo que pide termina en orden</small></div>
                <div class="asi-embudo">
                    <div class="asi-fila"><span>Solicitudes</span><i style="--w:${w(r.solicitudes)}"></i><b>${nf.format(r.solicitudes)}</b><em></em></div>
                    <div class="asi-fila"><span>Con cotización</span><i class="cot" style="--w:${w(r.conCotizacion)}"></i><b>${nf.format(r.conCotizacion)}</b><em>${pct(r.pctCotizadas)}</em></div>
                    <div class="asi-fila"><span>Cotizaciones</span><i class="cot" style="--w:${w(r.cotizaciones)}"></i><b>${nf.format(r.cotizaciones)}</b><em></em></div>
                    <div class="asi-fila"><span>Con orden</span><i class="ord" style="--w:${w(r.conOrden)}"></i><b>${nf.format(r.conOrden)}</b><em>${pct(r.pctCierre)}</em></div>
                </div>
            </div>
            <p class="asi-efec-pie">Cierre por valor: <b>${pct(r.pctMonto)}</b> · $${nf.format(r.montoGanado)} de $${nf.format(r.montoCotizado)} cotizados</p>`;
        }

        return `<div class="asi-efec" data-efec>
            <div class="asi-efec-cab"><h4>Efectividad</h4><div class="asi-seg" role="group" aria-label="Periodo">${opc.map(([k, t]) => `<button type="button" data-efec-p="${k}" class="${e.periodo === k ? 'on' : ''}">${t}</button>`).join('')}</div></div>
            ${e.periodo === 'rango' ? `<div class="asi-efec-rango"><label>Desde<input type="date" data-efec-f="desde" value="${esc(e.desde)}"></label><label>Hasta<input type="date" data-efec-f="hasta" value="${esc(e.hasta)}"></label></div>` : ''}
            ${cuerpo}
        </div>`;
    }

    async cargarEfectividad() {
        const e = this.efec;
        const id = this.cliente?.id;
        if (!e || !id) return;
        const n = (this.efecN = (this.efecN ?? 0) + 1);
        if (e.periodo === 'rango' && (!e.desde || !e.hasta)) { e.cargando = false; this.repintarEfectividad(); return; }
        Object.assign(e, { cargando: true, error: null });
        this.repintarEfectividad();
        try {
            const qs = new URLSearchParams({ periodo: e.periodo });
            if (e.periodo === 'rango') { qs.set('desde', e.desde); qs.set('hasta', e.hasta); }
            const r = await this.pedir(`${this.urls.clienteUrl.replace('__ID__', id)}/efectividad?${qs}`);
            if (n !== this.efecN) return;
            e.datos = r;
        } catch (err) {
            if (n !== this.efecN) return;
            e.error = err.message || 'No se pudo calcular la efectividad.';
        }
        e.cargando = false;
        this.repintarEfectividad();
    }

    repintarEfectividad() {
        const el = this.q('[data-efec]');
        if (el) el.outerHTML = this.vistaEfectividad();
    }

    /** Variables de color de un tipo (solicitud, cotización u orden): el fondo elegido en la configuración y un texto legible sobre él. */
    estiloTipo(d, tipo) {
        const c = d.colores?.[tipo] ?? '#64748b';
        const n = parseInt(c.slice(1), 16);
        const luz = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255;

        return `--m:${c};--mt:${luz > 0.65 ? '#1f2937' : '#ffffff'}`;
    }

    listaSolicitudes(d) {
        const abiertas = d.solicitudes.filter((s) => s.abierta);
        // Por el estado: 5 = cotización enviada, 10 en adelante = orden de compra; el resto sigue siendo solicitud.
        const tipo = (s) => (s.estado >= 10 ? 'orden' : s.estado === 5 ? 'cotizacion' : 'solicitud');
        const ico = { solicitud: 'fa-inbox', cotizacion: 'fa-file-invoice-dollar', orden: 'fa-cart-shopping' };

        return `<aside class="asi-cliente asi-lista-sol">
            <h4>Solicitudes del cliente <small>${d.solicitudes.length ? `las ${d.solicitudes.length} más recientes` : ''}</small></h4>
            <div class="asi-leyenda">${['solicitud', 'cotizacion', 'orden'].map((t) => `<span style="${this.estiloTipo(d, t)}"><i class="fa-solid ${ico[t]}"></i>${{ solicitud: 'Solicitud', cotizacion: 'Cotización', orden: 'Orden de compra' }[t]}</span>`).join('')}</div>
            ${abiertas.length ? `<p class="asi-alerta"><i class="fa-solid fa-circle-info"></i>Tiene ${abiertas.length} solicitud${abiertas.length === 1 ? '' : 'es'} abierta${abiertas.length === 1 ? '' : 's'}. Revisa que lo que pide no esté ya en una de ellas.</p>` : ''}
            ${d.solicitudes.length ? `<ul class="asi-sols">${d.solicitudes.map((s) => `<li class="${s.abierta ? 'abierta' : ''} ${s.estado === 9 ? 'cerrada' : ''}" style="${this.estiloTipo(d, tipo(s))}"><div>
                <b><i class="fa-solid ${ico[tipo(s)]}"></i>${esc(s.ref)}</b><span class="asi-estado">${esc(s.estadoNombre)}</span>
                <em>${esc(s.asunto) || 'Sin asunto'}</em><small>${fechaTxt(s.fecha)}${s.abierta ? ` · ${s.mora} d de mora` : ''}</small></div></li>`).join('')}</ul>` : '<p class="asi-vacio">Es la primera solicitud de este cliente.</p>'}
        </aside>`;
    }

    async buscarClientes(q) {
        const cont = this.q('[data-sugs]');
        if (!cont) return;
        if (q.trim().length < 2) { cont.hidden = true; return; }
        const n = (this.busq = (this.busq ?? 0) + 1);
        try {
            const { items } = await this.pedir(`${this.urls.clientesUrl}?q=${encodeURIComponent(q)}`);
            if (n !== this.busq || !this.q('[data-sugs]')) return;
            cont.hidden = false;
            cont.innerHTML = items.length ? items.map((c) => `<button type="button" data-cliente="${c.id}" data-nombre="${esc(c.nombre)}"><b>${esc(c.nombre)}</b><small>${[c.nit, c.ciudad].filter(Boolean).map(esc).join(' · ')}</small></button>`).join('') : '<p>Sin resultados</p>';
        } catch (e) { cont.hidden = false; cont.innerHTML = `<p>${esc(e.message)}</p>`; }
    }

    /**
     * Elige el cliente. Cambiarlo por otro reinicia lo que depende de él: contacto, relacionados y análisis (los elementos escritos se conservan).
     * @returns {Promise<boolean>} false si el usuario no confirmó o no se pudo cargar
     */
    async elegirCliente(id, nombre, { confirmar = true } = {}) {
        if (this.cliente && this.cliente.id !== id) {
            const hayAvance = this.contacto || this.items.some((i) => i.ia || i.historial);
            if (confirmar && hayAvance && !(await Swal.fire({ icon: 'warning', title: '¿Cambiar el cliente?', text: 'Se reinician el contacto, el análisis de las piezas y el correo.', showCancelButton: true, confirmButtonText: 'Cambiar cliente', cancelButtonText: 'Mantener', reverseButtons: true })).isConfirmed) { this.pintar(); return false; }
            Object.assign(this, { contacto: null, relacionados: new Set(), asunto: '', maxPaso: 0, correo: correoVacio() });
            this.items.forEach((i) => Object.assign(i, { historial: null, ia: null, iaError: null }));
        }
        this.cliente = { id, nombre };
        this.datos = null;
        this.paso = 0;
        this.pintar();
        this.q('[data-cuerpo]').insertAdjacentHTML('beforeend', '<p class="asi-cargando"><i class="fa-solid fa-spinner fa-spin"></i>Buscando sus solicitudes…</p>');
        try {
            const datos = await this.pedir(this.urls.clienteUrl.replace('__ID__', id));
            if (this.cliente?.id !== id) return false; // eligió otro mientras cargaba
            this.datos = datos;
            this.cliente = { id, nombre: datos.cliente.nombre };
            if (datos.contactos.length === 1) this.contacto = datos.contactos[0].id;
            this.efec = null;
        } catch (e) {
            this.cliente = null;
            toast.fire({ icon: 'error', title: e.message });
        }
        if (this.abierto) this.pintar();
        if (this.datos) this.cargarEfectividad();

        return !!this.datos;
    }

    // ------------------------------------------------------------ piezas del cliente (pedidas, cotizadas y vendidas)

    /** Fila del historial que coincide con alguna de las piezas que se buscan ahora (mismo producto o su referencia dentro del texto). */
    coincideFila(r) {
        const texto = normalizar(`${r.descripcion} ${r.modelo} ${r.prod_ref}`);

        return this.piezas().some((i) => (i.fk_product && i.fk_product === r.fk_product) || (normalizar(i.referencia).length >= 3 && texto.includes(normalizar(i.referencia))));
    }

    seccionPiezas(d) {
        return `<section class="asi-cliente asi-piezas">
            <h4>Piezas de este cliente <small>pedidas, cotizadas y vendidas · las más recientes</small></h4>
            <div data-coinc></div>
            <div data-filas-piezas>${this.htmlFilasPiezas(d)}</div>
        </section>`;
    }

    htmlFilasPiezas(d) {
        const ETIQUETA = { solicitud: 'En solicitud', cotizado: 'Cotizado', vendido: 'Vendido' };
        if (!d.piezas.length) return '<p class="asi-vacio">Todavía no hay piezas en solicitudes, cotizaciones ni órdenes de este cliente.</p>';

        return `<div class="asi-leyenda"><span class="solicitud">En solicitud</span><span class="cotizado">Cotizado</span><span class="vendido">Vendido</span></div>
            <ul class="asi-pzs">${d.piezas.map((r) => {
                const coincide = this.coincideFila(r);
                const codigo = [...new Set([r.modelo, r.prod_ref].filter(Boolean))].join(' · ');

                return `<li class="asi-pz ${r.grupo} ${coincide ? 'coincide' : ''}">
                    <span class="asi-pz-tag ${r.grupo}">${ETIQUETA[r.grupo]}</span>
                    <div class="asi-pz-t"><b title="${esc(r.descripcion)}">${esc(r.descripcion || 'Sin descripción')}</b>${codigo ? `<small>${esc(codigo)}</small>` : ''}</div>
                    <div class="asi-pz-d"><span>${esc(r.ref)} · ${esc(r.estadoNombre)}</span><span>${fechaTxt(r.fecha)} · ${nf2.format(r.cantidad)} und${r.valor ? ` · ${r.moneda === 'USD' ? 'US$' : '$'} ${nf2.format(r.valor)}` : ''}</span></div>
                    ${coincide ? '<em class="asi-pz-hit"><i class="fa-solid fa-bullseye"></i>Lo que busca ahora</em>' : ''}</li>`;
            }).join('')}</ul>
            ${d.piezas_mas ? `<button type="button" class="asi-mas-pz" data-mas-piezas ${this.cargandoMasPiezas ? 'disabled' : ''}>${this.cargandoMasPiezas ? '<i class="fa-solid fa-spinner fa-spin"></i>Cargando…' : '<i class="fa-solid fa-chevron-down"></i>Ver más'}</button>` : ''}`;
    }

    async verMasPiezas() {
        if (this.cargandoMasPiezas || !this.datos?.piezas_mas) return;
        this.cargandoMasPiezas = true;
        this.q('[data-filas-piezas]').innerHTML = this.htmlFilasPiezas(this.datos);
        const id = this.cliente.id;
        try {
            const r = await this.pedir(`${this.urls.clienteUrl.replace('__ID__', id)}/piezas?desde=${this.datos.piezas.length}`);
            if (this.cliente?.id === id) { this.datos.piezas = this.datos.piezas.concat(r.items); this.datos.piezas_mas = r.mas; }
        } catch (e) { toast.fire({ icon: 'error', title: e.message }); }
        this.cargandoMasPiezas = false;
        const cont = this.q('[data-filas-piezas]');
        if (cont) cont.innerHTML = this.htmlFilasPiezas(this.datos);
    }

    /** Etiquetas de «ya aparece en…» para una pieza: solicitud en azul, cotizada en naranja y vendida en verde. */
    chipsCoincidencias(filas) {
        const ETIQUETA = { solicitud: 'En solicitud', cotizado: 'Cotizado', vendido: 'Vendido' };

        return filas.map((r) => `<span class="asi-coinc-chip ${r.grupo}" title="${esc(r.descripcion)}"><b>${ETIQUETA[r.grupo]}</b>${esc(r.ref)} · ${esc(r.estadoNombre)} · ${fechaTxt(r.fecha)}</span>`).join('');
    }

    /** Pinta el aviso «esta pieza ya aparece…» en el paso del cliente (todas las piezas) y en el de la pieza (la actual). */
    pintarCoincidencias() {
        const nombre = (i) => i.nombre || i.referencia || 'Pieza';
        const cont = this.q('[data-coinc]');
        if (cont) {
            const con = this.piezas().filter((i) => this.coinc[i.id]?.length);
            cont.innerHTML = con.length ? `<div class="asi-coinc"><p><i class="fa-solid fa-bullseye"></i>${con.length === 1 ? 'La pieza que buscas ya aparece' : 'Estas piezas ya aparecen'} en el historial de este cliente</p>
                ${con.map((i) => `<div class="asi-coinc-fila"><b>${esc(nombre(i))}</b><div>${this.chipsCoincidencias(this.coinc[i.id])}</div></div>`).join('')}</div>` : '';
            const filas = this.q('[data-filas-piezas]');
            if (filas && this.datos && !this.cargandoMasPiezas) filas.innerHTML = this.htmlFilasPiezas(this.datos); // vuelve a resaltar las filas
        }
        const una = this.q('[data-coinc-pieza]');
        if (una) {
            const it = this.items[this.actual];
            const f = it ? this.coinc[it.id] : null;
            una.innerHTML = f?.length ? `<div class="asi-coinc"><p><i class="fa-solid fa-bullseye"></i>Esta pieza ya aparece en el historial de ${esc(this.cliente?.nombre ?? 'este cliente')}</p><div class="asi-coinc-fila"><div>${this.chipsCoincidencias(f)}</div></div></div>` : '';
        }
    }

    /** Busca dónde aparecen ya las piezas actuales en el historial del cliente; solo consulta si cambiaron las piezas o el cliente. */
    async cargarCoincidencias() {
        if (!this.datos || !this.cliente) return;
        const piezas = this.piezas().filter((i) => i.fk_product || i.referencia.trim().length >= 3);
        const clave = JSON.stringify([this.cliente.id, piezas.map((i) => [i.id, i.referencia.trim(), i.fk_product])]);
        if (clave === this.claveCoinc) { this.pintarCoincidencias(); return; }
        this.claveCoinc = clave;
        if (!piezas.length) { this.coinc = {}; this.pintarCoincidencias(); return; }
        try {
            const r = await this.pedir(`${this.urls.clienteUrl.replace('__ID__', this.cliente.id)}/coincidencias`, 'POST', {
                piezas: piezas.map((i) => ({ id: i.id, referencia: i.referencia.trim() || null, fk_product: i.fk_product })),
            });
            if (this.claveCoinc !== clave || !this.abierto) return;
            this.coinc = r.coincidencias;
        } catch { this.claveCoinc = ''; return; }
        this.pintarCoincidencias();
    }

    // ------------------------------------------------------------ paso 2: contacto

    vistaContacto() {
        const cs = this.datos.contactos;
        if (!cs.length) return '<div class="asi-p1"><div class="asi-p1-i"><h3>Contacto</h3><p class="asi-alerta"><i class="fa-solid fa-triangle-exclamation"></i>Este cliente no tiene contactos activos. Créale un contacto en Terceros y vuelve a intentarlo.</p></div></div>';

        return `<div class="asi-p2"><h3>¿Con quién del cliente se maneja la solicitud?</h3>
            <p class="asi-ayuda">Elige el contacto principal; puedes marcar otros para que también queden en la solicitud. Puedes volver aquí cuando quieras para cambiarlo.</p>
            <div class="asi-contactos">${cs.map((c) => `<label class="asi-contacto ${this.contacto === c.id ? 'on' : ''}">
                <input type="radio" name="contacto" value="${c.id}" ${this.contacto === c.id ? 'checked' : ''}>
                <span class="asi-contacto-cab">
                    <span class="asi-avatar">${esc((c.label.match(/\p{L}/u) ?? ['?'])[0].toUpperCase())}</span>
                    <span class="asi-contacto-t"><b>${esc(c.nombre ?? c.label.split(' — ')[0])}</b>${c.cargo ? `<small>${esc(c.cargo)}</small>` : ''}</span>
                    ${this.contacto === c.id ? '<em>Principal</em>' : `<span class="asi-rel"><input type="checkbox" data-rel="${c.id}" ${this.relacionados.has(c.id) ? 'checked' : ''}>También</span>`}
                </span>
                <span class="asi-contacto-d">
                    <span class="${c.email ? '' : 'falta'}"><i class="fa-regular fa-envelope"></i>${esc(c.email || 'Sin correo')}</span>
                    <span class="${c.telefonos?.length ? '' : 'falta'}"><i class="fa-solid fa-phone"></i>${c.telefonos?.length ? c.telefonos.map(esc).join(' · ') : 'Sin teléfono'}</span>
                    <span class="${c.cumple ? '' : 'falta'}"><i class="fa-solid fa-cake-candles"></i>${c.cumple ? `Cumpleaños: ${cumpleTxt(c.cumple)}` : 'Sin fecha de cumpleaños'}</span>
                </span>
            </label>`).join('')}</div></div>`;
    }

    /** Empareja el nombre dicho con los contactos del cliente. Devuelve el elegido o, si hay duda, los candidatos. */
    buscarContacto(texto) {
        const q = normalizar(texto).split(' ').filter((w) => w.length >= 2);
        if (!q.length || !this.datos) return { elegido: null, candidatos: [] };
        const puntuados = this.datos.contactos.map((c) => {
            const nombre = normalizar(c.label.split(' — ')[0]).split(' ');
            const aciertos = q.filter((w) => nombre.some((n) => n === w || (w.length > 3 && (n.startsWith(w) || w.startsWith(n)) && n.length > 3))).length;

            return { c, puntaje: aciertos / q.length };
        }).filter((x) => x.puntaje > 0).sort((a, b) => b.puntaje - a.puntaje);
        const mejor = puntuados[0];
        if (mejor && mejor.puntaje >= 0.66 && (!puntuados[1] || puntuados[1].puntaje < mejor.puntaje)) return { elegido: mejor.c, candidatos: [] };

        return { elegido: null, candidatos: puntuados.slice(0, 5).map((x) => x.c) };
    }

    // ------------------------------------------------------------ paso 3: piezas

    htmlTabs() {
        return `${this.items.map((x, i) => `<button type="button" class="${i === this.actual ? 'on' : ''}" data-tab="${i}">${x.cargandoIa ? '<i class="fa-solid fa-spinner fa-spin"></i>' : x.ia ? '<i class="fa-solid fa-circle-check ok"></i>' : ''}${esc(x.nombre || x.referencia || `Pieza ${i + 1}`)}${this.items.length > 1 ? `<i class="fa-solid fa-xmark" data-quitar="${i}" title="Quitar"></i>` : ''}</button>`).join('')}
            <button type="button" class="asi-mas" data-nuevo><i class="fa-solid fa-plus"></i>Agregar otra pieza</button>`;
    }

    vistaElementos() {
        const it = this.items[this.actual];
        const dictado = !!Reconocimiento;

        return `<div class="asi-p3">
            <div class="asi-tabs" data-tabs>${this.htmlTabs()}</div>
            <section class="asi-form" data-item="${it.id}">
                <h3>¿Qué está buscando el cliente?</h3>
                <div data-coinc-pieza></div>
                <p class="asi-ayuda corto">Puede ser cualquier cosa: una pieza, un repuesto, una máquina… Basta con la referencia, el nombre, una foto o una descripción.</p>
                <div class="asi-campos">
                    <label class="asi-campo">Referencia<div class="asi-rel-ref"><input data-f="referencia" value="${esc(it.referencia)}" placeholder="Ej. 6205-2RS" autocomplete="off" ${it.referencia ? '' : 'autofocus'}><div class="asi-sugs" data-sugs-prod hidden></div></div></label>
                    <label class="asi-campo">Nombre del producto<input data-f="nombre" value="${esc(it.nombre)}" placeholder="Ej. Rodamiento rígido de bolas" autocomplete="off"></label>
                    <label class="asi-campo corto">Cantidad<input type="number" min="0.01" step="any" data-f="cantidad" value="${esc(it.cantidad)}"></label>
                </div>
                ${it.vinculo ? `<p class="asi-vinculo"><i class="fa-solid fa-link"></i>Del catálogo: <b>${esc(it.vinculo)}</b><button type="button" data-desvincular title="Quitar el vínculo">&times;</button></p>` : ''}
                <div class="asi-detalle">
                    <div class="asi-foto">${it.foto ? `<img src="${it.foto}" alt="Foto de la pieza"><button type="button" data-quitar-foto title="Quitar la foto">&times;</button>`
                        : `<label class="asi-subir"><i class="fa-solid fa-camera"></i><span>Foto</span><input type="file" accept="image/*" data-foto hidden></label>
                           <label class="asi-subir camara"><i class="fa-solid fa-mobile-screen"></i><span>Tomar</span><input type="file" accept="image/*" capture="environment" data-foto hidden></label>`}</div>
                    <label class="asi-campo asi-desc">Descripción o detalle
                        <div class="asi-dictar"><textarea data-f="descripcion" rows="3" maxlength="3000" placeholder="Medidas, marca, para qué máquina, urgencia… (puedes dictarlo con el micrófono)">${esc(it.descripcion)}</textarea>
                        <button type="button" class="asi-mic ${this.dictando ? 'on' : ''}" data-mic title="${dictado ? 'Dictar con el micrófono' : 'Tu navegador no permite dictado: usa Chrome o Edge'}" ${dictado ? '' : 'disabled'}><i class="fa-solid fa-microphone"></i></button></div></label>
                </div>
                <div class="asi-acciones"><button type="button" class="asi-btn primario" data-analizar ${this.itemLleno(it) ? '' : 'disabled'}><i class="fa-solid fa-magnifying-glass-chart"></i>Buscar historial y analizar con IA</button>
                    <small>La IA usa la foto, la referencia y la descripción; el historial sale de las compras de la empresa.</small></div>
            </section>
            <div class="asi-resultados"><section class="asi-col izq" data-hist></section><section class="asi-col der" data-ia></section></div>
        </div>`;
    }

    pintarResultados() {
        if (!this.q('[data-hist]')) return; // el usuario ya cambió de paso mientras llegaba la respuesta
        const it = this.items[this.actual];
        this.q('[data-hist]').innerHTML = this.htmlHistorial(it);
        this.q('[data-ia]').innerHTML = this.htmlIa(it);
        this.pintarPrompt();
    }

    htmlHistorial(it) {
        const cab = '<header><span class="asi-ico verde"><i class="fa-solid fa-clock-rotate-left"></i></span><div><b>Historial de compras</b><small>Lo que ya sabe la empresa de este producto</small></div></header>';
        const h = it.historial;
        if (it.cargandoHistorial) return `${cab}<p class="asi-cargando"><i class="fa-solid fa-spinner fa-spin"></i>Revisando compras anteriores…</p>`;
        if (!h) return `${cab}<p class="asi-vacio">Aquí aparecerá si el producto ya se compró antes: quién, cuándo, a qué precio y cómo quedó.</p>`;
        if (!h.fk_product) return `${cab}<div class="asi-nuevo"><i class="fa-solid fa-seedling"></i><b>Producto sin registro</b><p>No lo encontramos en el catálogo ni en compras anteriores. Si es de uno que ya manejamos, búscalo por referencia o nombre para vincularlo.</p></div>`;
        const r = h.resumen?.usd ?? null;
        const rc = h.resumen?.cop ?? null;

        return `${cab}
            <p class="asi-prod"><i class="fa-solid fa-box"></i><b>${esc(h.producto.ref)}</b> ${esc(h.producto.label)}</p>
            ${h.comprado ? `<div class="asi-badge ok"><i class="fa-solid fa-circle-check"></i>Ya fue comprado · ${h.compras.length} registro${h.compras.length === 1 ? '' : 's'}</div>
                ${r || rc ? `<div class="asi-precios">${r ? `<span><small>Promedio</small><b>US$ ${nf2.format(r.promedio)}</b></span><span><small>Mín – Máx</small><b>${nf2.format(r.min)} – ${nf2.format(r.max)}</b></span>` : ''}${rc ? `<span><small>Promedio COP</small><b>$ ${nf.format(rc.promedio)}</b></span>` : ''}</div>` : ''}
                <ul class="asi-compras">${h.compras.map((c) => `<li>
                    <div class="asi-compra-t"><span class="asi-tipo ${c.tipo === 'Orden' ? 'ord' : 'cot'}">${esc(c.tipo)}</span>${c.url ? `<a href="${esc(c.url)}" target="_blank" rel="noopener">${esc(c.ref)}</a>` : `<b>${esc(c.ref)}</b>`}<span class="asi-estado" style="--e:#64748b">${esc(c.estadoNombre)}</span></div>
                    <div class="asi-compra-q"><i class="fa-solid fa-user"></i>${esc(c.tercero || 'Sin cliente')}</div>
                    <div class="asi-compra-d"><span><i class="fa-regular fa-calendar"></i>${fechaTxt(c.fecha)}</span><span>${nf2.format(c.cantidad)} und</span><b>${c.moneda ? `${c.moneda === 'USD' ? 'US$' : '$'} ${nf2.format(c.valor)}` : nf2.format(c.valor)}</b>${c.moneda === 'USD' && c.cop ? `<small>≈ $ ${nf.format(c.cop)}</small>` : ''}</div>
                </li>`).join('')}</ul>`
        : '<div class="asi-badge nuevo"><i class="fa-solid fa-seedling"></i>Está en el catálogo pero nunca se ha cotizado ni comprado</div>'}
            ${h.proveedores.length ? `<h4>Proveedores con los que se ha manejado</h4><ul class="asi-provs">${h.proveedores.map((p) => `<li><b>${esc(p.proveedor)}</b><small>${esc(p.origen)}${p.fecha ? ` · ${fechaTxt(p.fecha)}` : ''}</small><span>${nf2.format(p.precio)}</span></li>`).join('')}</ul>` : ''}`;
    }

    htmlIa(it) {
        const cab = `<header><span class="asi-ico violeta"><i class="fa-solid fa-wand-magic-sparkles"></i></span><div><b>Análisis con IA</b><small>Identificación, proveedores y comparación</small></div>
            <button type="button" class="asi-prompt-btn" data-prompt-toggle title="Ver o editar lo que se le pide a la IA"><i class="fa-solid fa-sliders"></i>Instrucciones</button></header><div class="asi-prompt" data-prompt hidden></div>`;
        if (it.cargandoIa) return `${cab}<div class="asi-pensando"><div class="asi-puntos"><i></i><i></i><i></i></div><b>Investigando el producto…</b><small>Identifico la pieza, busco proveedores y comparo costo, cumplimiento y transporte. Puede tardar hasta un minuto.</small></div>`;
        if (it.iaError) return `${cab}<p class="asi-alerta"><i class="fa-solid fa-triangle-exclamation"></i>${esc(it.iaError)}</p>`;
        if (!it.ia) return `${cab}<p class="asi-vacio">Cuando pulses «Buscar historial y analizar con IA» te muestro qué es, quién lo vende y qué conviene.</p>`;
        const a = it.ia;
        const i = a.identificacion;
        const pip = (n) => (n == null ? '<span class="asi-pips nd">sin datos</span>' : `<span class="asi-pips n${n}">${[1, 2, 3, 4, 5].map((k) => `<i class="${k <= n ? 'on' : ''}"></i>`).join('')}</span>`);

        return `${cab}
            <div class="asi-ident"><div class="asi-ident-t"><b>${esc(i.nombre || 'Pieza sin identificar')}</b>${i.confianza ? `<span class="asi-conf ${i.confianza}">confianza ${i.confianza}</span>` : ''}</div>
                <dl>${[['Marca', i.marca], ['Referencia', i.referencia], ['Parte', i.parte], ['Categoría', i.categoria]].filter(([, v]) => v).map(([k, v]) => `<div><dt>${k}</dt><dd>${esc(v)}</dd></div>`).join('')}</dl>
                ${i.descripcion ? `<p>${esc(i.descripcion)}</p>` : ''}
                ${i.especificaciones.length ? `<ul class="asi-specs">${i.especificaciones.map((e) => `<li><span>${esc(e.campo)}</span>${esc(e.valor)}</li>`).join('')}</ul>` : ''}</div>
            ${a.recomendacion ? `<div class="asi-reco"><i class="fa-solid fa-award"></i><div><small>Recomendación</small><b>${esc(a.recomendacion.proveedor || 'Según el análisis')}</b><p>${esc(a.recomendacion.motivo)}</p></div></div>` : ''}
            ${a.proveedores.length ? `<h4>Proveedores y comparación <small>puntajes estimados por la IA</small></h4><div class="asi-pv">${a.proveedores.map((p) => {
                const ns = [p.puntaje.costo, p.puntaje.cumplimiento, p.puntaje.transporte].filter((n) => n != null);
                const prom = ns.length ? (ns.reduce((x, y) => x + y, 0) / ns.length).toFixed(1) : null;
                const reco = a.recomendacion?.proveedor && p.proveedor.toLowerCase() === a.recomendacion.proveedor.toLowerCase();

                return `<article class="${reco ? 'reco' : ''}">
                    <div class="asi-pv-t"><b>${esc(p.proveedor)}</b><small>${esc(p.pais)}</small>${reco ? '<span class="asi-tag oro"><i class="fa-solid fa-award"></i>Recomendado</span>' : ''}${p.comprado_antes ? '<span class="asi-tag verde">Comprado antes</span>' : ''}<span class="asi-tag">${p.origen === 'historial' ? 'Historial' : 'Web'}</span>${prom ? `<em title="Promedio de los puntajes">${prom}<small>/5</small></em>` : ''}</div>
                    <div class="asi-pv-p">${esc(p.precio || 'No verificado')}</div>
                    <div class="asi-pv-g"><span>Costo ${pip(p.puntaje.costo)}</span><span>Cumplimiento ${pip(p.puntaje.cumplimiento)}</span><span>Transporte ${pip(p.puntaje.transporte)}</span></div>
                    <ul>${p.tiempo_entrega ? `<li><i class="fa-regular fa-clock"></i>${esc(p.tiempo_entrega)}</li>` : ''}${p.transporte ? `<li><i class="fa-solid fa-truck-fast"></i>${esc(p.transporte)}</li>` : ''}${p.cumplimiento ? `<li><i class="fa-solid fa-handshake"></i>${esc(p.cumplimiento)}</li>` : ''}${p.nota ? `<li><i class="fa-regular fa-note-sticky"></i>${esc(p.nota)}</li>` : ''}</ul>
                    ${p.url ? `<a href="${esc(p.url)}" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-arrow-up-right-from-square"></i>Ver fuente</a>` : ''}</article>`;
            }).join('')}</div>` : ''}
            ${a.alertas.length ? `<ul class="asi-alertas">${a.alertas.map((x) => `<li><i class="fa-solid fa-triangle-exclamation"></i>${esc(x)}</li>`).join('')}</ul>` : ''}
            <p class="asi-nota">Datos de internet y estimaciones de la IA: verifica precios y plazos antes de ofrecerlos al cliente.</p>`;
    }

    /** Panel con la instrucción que se le envía a la IA (la del análisis en el paso de piezas y la del correo en el paso Correo): visible para todos y, por ahora, editable por cualquiera. */
    pintarPrompt() {
        const cont = this.q('[data-prompt]');
        if (!cont) return;
        const clave = this.paso === PASO_CORREO ? 'correo' : 'asistente';
        const p = this.prompts[clave];
        cont.hidden = !this.promptAbierto;
        if (!p) { cont.innerHTML = '<p class="asi-cargando"><i class="fa-solid fa-spinner fa-spin"></i>Cargando…</p>'; return; }
        cont.innerHTML = `<p class="asi-ayuda">Esto es lo que se le pide a la IA ${clave === 'correo' ? 'para redactar el correo' : 'para analizar la pieza'}. ${p.puedeEditar ? 'Puedes ajustarlo y se aplica para todos.' : 'Solo un administrador puede cambiarlo.'}</p>
            <textarea data-prompt-texto rows="9" maxlength="6000" ${p.puedeEditar ? '' : 'readonly'}>${esc(p.texto)}</textarea>
            <p class="asi-fijo"><b>Formato fijo que añade el sistema:</b> ${esc(p.formato)}</p>
            <div class="asi-prompt-pie">${p.personalizado ? '<span class="asi-tag violeta">Personalizado</span>' : '<span class="asi-tag">Por defecto</span>'}
                ${p.puedeEditar ? '<button type="button" class="asi-btn" data-prompt-defecto><i class="fa-solid fa-rotate-left"></i>Por defecto</button><button type="button" class="asi-btn primario" data-prompt-guardar><i class="fa-solid fa-floppy-disk"></i>Guardar</button>' : ''}</div>`;
    }

    async guardarPrompt(texto) {
        const clave = this.paso === PASO_CORREO ? 'correo' : 'asistente';
        try {
            this.prompts[clave] = await this.pedir(this.urls.promptUrl, 'PUT', { clave, texto });
            toast.fire({ icon: 'success', title: 'Instrucciones guardadas' });
        } catch (e) { toast.fire({ icon: 'error', title: e.message }); }
        this.pintarPrompt();
    }

    // ------------------------------------------------------------ análisis

    async analizar(it = this.items[this.actual]) {
        if (!it || !this.itemLleno(it) || it.cargandoIa || !this.abierto) return;
        Object.assign(it, { iaError: null, ia: null, cargandoIa: true, cargandoHistorial: true });
        this.refrescarElemento(it);
        // El historial es rápido: se muestra mientras la IA sigue pensando.
        this.pedir(this.urls.historialUrl, 'POST', { fk_product: it.fk_product, referencia: it.referencia || null })
            .then((h) => { it.historial = h; this.vincular(it, h); })
            .catch(() => {})
            .finally(() => { it.cargandoHistorial = false; this.refrescarElemento(it); });
        try {
            const r = await this.pedir(this.urls.analizarUrl, 'POST', {
                referencia: it.referencia || null, nombre: it.nombre || null, descripcion: it.descripcion || null, cantidad: Number(it.cantidad) || 1,
                fk_product: it.fk_product, cliente: this.cliente?.nombre ?? null, foto: it.foto,
            });
            it.ia = r.analisis;
            it.historial = r.historial;
            this.vincular(it, r.historial);
        } catch (e) {
            it.iaError = e.message;
            if (e.datos?.historial) it.historial = e.datos.historial;
        }
        it.cargandoIa = false;
        it.cargandoHistorial = false;
        this.refrescarElemento(it);
    }

    vincular(it, h) {
        if (h?.fk_product && !it.fk_product) { it.fk_product = h.fk_product; it.vinculo = `${h.producto.ref} — ${h.producto.label}`; this.cargarCoincidencias(); }
    }

    /** Repinta lo que depende del análisis del elemento: sus resultados (si es el que se ve), las pestañas y la revisión. */
    refrescarElemento(it) {
        if (!this.abierto) return;
        if (this.paso === 2) {
            if (this.items[this.actual] === it) this.pintarResultados();
            const tabs = this.q('[data-tabs]');
            if (tabs) tabs.innerHTML = this.htmlTabs();
        } else if (this.paso === PASO_REVISION) this.pintar();
    }

    /** Analiza en segundo plano, de uno en uno, los elementos que aún no tienen análisis. */
    async analizarPendientes() {
        if (this.analizando) return;
        this.analizando = true;
        try {
            for (const it of [...this.items]) {
                if (!this.abierto) break;
                if (this.itemLleno(it) && !it.ia && !it.iaError && this.items.includes(it)) await this.analizar(it);
            }
        } finally { this.analizando = false; }
    }

    // ------------------------------------------------------------ catálogo, foto y dictado

    async buscarProductos(q) {
        const cont = this.q('[data-sugs-prod]');
        if (!cont) return;
        if (q.trim().length < 2) { cont.hidden = true; return; }
        const n = (this.busqP = (this.busqP ?? 0) + 1);
        try {
            const { items } = await this.pedir(`${this.urls.productosUrl}?q=${encodeURIComponent(q)}`);
            if (n !== this.busqP || !this.q('[data-sugs-prod]')) return;
            cont.hidden = !items.length;
            cont.innerHTML = items.map((p) => `<button type="button" data-prod="${p.id}" data-ref="${esc(p.ref)}" data-label="${esc(p.label)}"><b>${esc(p.ref)}</b><small>${esc(p.label)}</small></button>`).join('');
        } catch { cont.hidden = true; }
    }

    leerFoto(archivo) {
        if (!archivo) return;
        const img = new Image();
        const url = URL.createObjectURL(archivo);
        img.onload = () => {
            const k = Math.min(1, 1280 / Math.max(img.width, img.height));
            const c = document.createElement('canvas');
            c.width = Math.round(img.width * k);
            c.height = Math.round(img.height * k);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            URL.revokeObjectURL(url);
            let calidad = 0.82;
            let data = c.toDataURL('image/jpeg', calidad);
            while (data.length > 2_800_000 && calidad > 0.4) { calidad -= 0.1; data = c.toDataURL('image/jpeg', calidad); }
            this.items[this.actual].foto = data;
            this.pintar();
        };
        img.onerror = () => { URL.revokeObjectURL(url); toast.fire({ icon: 'error', title: 'No se pudo leer la imagen' }); };
        img.src = url;
    }

    /**
     * Dictado por voz del navegador. `destino` devuelve el texto de partida y recibe el texto dictado hasta ahora.
     * Usado en la descripción del elemento y en la barra «díselo al asistente».
     */
    dictar({ base, alCambiar, alTerminar, boton }) {
        if (!Reconocimiento) return;
        if (this.dictado) { this.dictado.stop(); return; }
        let finales = '';
        const rec = new Reconocimiento();
        Object.assign(rec, { lang: 'es-CO', continuous: true, interimResults: true });
        rec.onresult = (ev) => {
            let parcial = '';
            for (let i = ev.resultIndex; i < ev.results.length; i += 1) {
                if (ev.results[i].isFinal) finales += `${ev.results[i][0].transcript} `; else parcial += ev.results[i][0].transcript;
            }
            alCambiar(`${base}${finales}${parcial}`.slice(0, 4000));
        };
        rec.onerror = (ev) => { if (ev.error === 'not-allowed') toast.fire({ icon: 'error', title: 'Permite el micrófono en el navegador para dictar' }); };
        rec.onend = () => { this.dictado = null; this.dictando = false; document.querySelectorAll('.asi-mic.on').forEach((b) => b.classList.remove('on')); alTerminar?.(); };
        this.dictado = rec;
        this.dictando = true;
        boton?.classList.add('on');
        rec.start();
    }

    refrescarAnalizar() {
        const b = this.q('[data-analizar]');
        if (b) b.disabled = !this.itemLleno(this.items[this.actual]);
        this.q('[data-sig]').disabled = this.creando || !this.puedeAvanzar();
        this.q('[data-pasos]').innerHTML = this.htmlPasos();
    }

    // ------------------------------------------------------------ «díselo al asistente»

    pintarMensaje() {
        const cont = this.q('[data-habla-msg]');
        if (!cont) return;
        const pend = this.pendiente;
        if (this.errorHabla && !this.interpretando) { cont.innerHTML = `<span class="asi-hmsg error"><i class="fa-solid fa-triangle-exclamation"></i>${esc(this.errorHabla)}</span>`; return; }
        cont.innerHTML = this.interpretando
            ? '<span class="asi-hmsg"><i class="fa-solid fa-spinner fa-spin"></i>Entendiendo lo que dijiste…</span>'
            : pend ? `<span class="asi-hmsg"><i class="fa-solid fa-circle-question"></i>${esc(pend.pregunta)}</span>${pend.opciones.map((o, i) => `<button type="button" class="asi-chip" data-elegir-pend="${i}">${esc(o.etiqueta)}</button>`).join('')}`
                : this.mensaje ? `<span class="asi-hmsg ok"><i class="fa-solid fa-wand-magic-sparkles"></i>${esc(this.mensaje)}</span>`
                    : '<span class="asi-hmsg suave"><i class="fa-regular fa-lightbulb"></i>Puedes contarme la solicitud completa con la voz o escribiendo, y yo lleno los pasos.</span>';
    }

    /** Estado actual del formulario, para que el asistente entienda correcciones. */
    estadoActual() {
        const c = this.datos?.contactos.find((x) => x.id === this.contacto);

        return {
            cliente: this.cliente?.nombre ?? null,
            contacto: c ? c.label.split(' — ')[0] : null,
            elementos: this.items.filter((i) => this.itemLleno(i)).map((i) => ({ referencia: i.referencia, nombre: i.nombre })),
        };
    }

    async enviarHabla() {
        const area = this.q('[data-habla]');
        const texto = area.value.trim();
        if (!texto || this.interpretando) return;
        this.dictado?.stop();
        this.interpretando = true;
        this.pendiente = null;
        this.errorHabla = null;
        this.pintarMensaje();
        try {
            const r = await this.pedir(this.urls.interpretarUrl, 'POST', { texto, estado: this.estadoActual() });
            area.value = '';
            area.style.height = '';
            await this.aplicarInterpretacion(r);
            this.interpretando = false;
            if (this.abierto) this.pintar();
        } catch (e) {
            // El error se queda a la vista en la barra (un aviso que desaparece se pierde) y no se repinta el panel.
            this.interpretando = false;
            this.mensaje = '';
            this.errorHabla = e.message;
            this.pintarMensaje();
        }
    }

    /** Vuelca lo entendido en los pasos: cliente, contacto, elementos, asunto y próximo contacto; luego lleva a lo que falte o a la revisión. */
    async aplicarInterpretacion(r) {
        const notas = [];
        // Cliente
        if (r.cliente_texto) {
            const c = r.cliente;
            if (c?.elegido) {
                if (await this.elegirCliente(c.elegido.id, c.elegido.nombre, { confirmar: false })) notas.push(`cliente ${this.cliente.nombre}`);
            } else if (c?.candidatos.length) {
                this.pendiente = { pregunta: `¿Cuál de estos clientes es «${r.cliente_texto}»?`, opciones: c.candidatos.map((x) => ({ etiqueta: x.nombre, tipo: 'cliente', x })), siguiente: r };
            } else {
                notas.push(`no encontré el cliente «${r.cliente_texto}»`);
            }
        }
        // Contacto (necesita el cliente ya cargado)
        if (r.contacto_texto && this.datos && !this.pendiente) this.aplicarContacto(r.contacto_texto, notas);
        else if (r.contacto_texto && !this.datos && !this.pendiente) notas.push('dime primero el cliente para ubicar el contacto');
        // Elementos
        if (r.elementos.length) {
            const nuevos = r.elementos.map((e) => Object.assign(itemVacio(), { referencia: e.referencia, nombre: e.nombre, descripcion: e.descripcion, cantidad: e.cantidad }));
            this.items = r.modo_elementos === 'reemplazar' ? nuevos : this.items.filter((i) => this.itemLleno(i)).concat(nuevos);
            this.actual = Math.max(0, this.items.length - nuevos.length);
            notas.push(`${nuevos.length} pieza${nuevos.length === 1 ? '' : 's'}`);
            await Promise.all(nuevos.map((i) => this.vincularPorReferencia(i)));
        }
        if (r.asunto) this.asunto = r.asunto;
        if (r.dias_proximo_contacto != null) this.proximo = sumarDias(r.dias_proximo_contacto);

        this.mensaje = r.mensaje || (notas.length ? `Listo: ${notas.join(', ')}.` : 'No encontré nada que cambiar.');
        this.irAlPrimerFaltante();
        if (!this.pendiente) this.analizarPendientes();
    }

    aplicarContacto(texto, notas) {
        const { elegido, candidatos } = this.buscarContacto(texto);
        if (elegido) {
            this.contacto = elegido.id;
            this.relacionados.delete(elegido.id);
            notas.push(`contacto ${elegido.label.split(' — ')[0]}`);
        } else if (candidatos.length) {
            this.pendiente = { pregunta: `¿Cuál es el contacto «${texto}»?`, opciones: candidatos.map((c) => ({ etiqueta: c.label.split(' — ')[0], tipo: 'contacto', x: c })) };
        } else {
            notas.push(`no encontré al contacto «${texto}» entre los contactos del cliente`);
        }
    }

    async elegirPendiente(i) {
        const p = this.pendiente;
        const o = p?.opciones[i];
        if (!o) return;
        this.pendiente = null;
        if (o.tipo === 'cliente') {
            const r = p.siguiente;
            const notas = [];
            if (await this.elegirCliente(o.x.id, o.x.nombre, { confirmar: false })) {
                notas.push(`cliente ${this.cliente.nombre}`);
                if (r.contacto_texto) this.aplicarContacto(r.contacto_texto, notas);
            }
            this.mensaje = `Listo: ${notas.join(', ')}.`;
        } else {
            this.contacto = o.x.id;
            this.relacionados.delete(o.x.id);
            this.mensaje = `Listo: contacto ${o.etiqueta}.`;
        }
        this.irAlPrimerFaltante();
        this.pintar();
        if (!this.pendiente) this.analizarPendientes();
    }

    /** Con todo completo se va a la revisión; si falta algo, al paso donde falta. */
    irAlPrimerFaltante() {
        this.items = this.items.filter((i) => this.itemLleno(i)).length ? this.items.filter((i) => this.itemLleno(i)) : this.items;
        this.actual = Math.min(this.actual, this.items.length - 1);
        if (!this.datos) this.paso = 0;
        else if (!this.contacto) this.paso = 1;
        else if (!this.items.some((i) => this.itemLleno(i))) this.paso = 2;
        else { this.paso = PASO_REVISION; if (!this.asunto) this.asunto = this.asuntoSugerido(); }
    }

    /** Si la referencia dicha coincide exactamente con un producto del catálogo, se vincula (así el historial es exacto). */
    async vincularPorReferencia(it) {
        if (!it.referencia) return;
        try {
            const { items } = await this.pedir(`${this.urls.productosUrl}?q=${encodeURIComponent(it.referencia)}`);
            const exacto = items.filter((p) => normalizar(p.ref) === normalizar(it.referencia));
            if (exacto.length === 1) Object.assign(it, { fk_product: exacto[0].id, vinculo: `${exacto[0].ref} — ${exacto[0].label}` });
        } catch { /* sin vínculo: el análisis lo intenta por referencia exacta */ }
    }

    // ------------------------------------------------------------ paso 4: correo al cliente

    /** Referencia provisional que se ve en el correo hasta que se crea la solicitud; al crearla se cambia por la real. */
    refProvisional() {
        const h = new Date();

        return `RQ${String(h.getFullYear()).slice(2)}${String(h.getMonth() + 1).padStart(2, '0')}-XXXX`;
    }

    nombreContacto() {
        const c = this.datos?.contactos.find((x) => x.id === this.contacto);

        return c ? c.label.split(' — ')[0] : '';
    }

    /** Variables de las plantillas de Dolibarr; la firma no se reemplaza aquí porque se añade sola al final. */
    sustituir(txt) {
        return String(txt ?? '')
            .replaceAll('__REF__', this.refProvisional())
            .replaceAll('__THIRDPARTY_NAME__', this.cliente?.nombre ?? '')
            .replaceAll('__CONTACTFULLNAME__', this.nombreContacto()).replaceAll('__CONTACT_NAME__', this.nombreContacto())
            .replaceAll('__USER_FULLNAME__', this.correo.ctx?.asesor ?? '')
            .replaceAll('__USER_SIGNATURE__', '');
    }

    /** Contacto principal y relacionados, con su correo. */
    destinatarios() {
        return (this.datos?.contactos ?? []).filter((c) => c.id === this.contacto || this.relacionados.has(c.id)).map((c) => ({ nombre: c.label.split(' — ')[0], email: c.email }));
    }

    motivoCorreo() {
        if (!this.destinatarios().some((d) => d.email)) return 'ninguno de los contactos tiene correo electrónico.';
        if (!this.correo.asunto.trim()) return 'escribe el asunto.';
        if (!this.correo.cuerpo.trim()) return 'escribe o genera el cuerpo del correo.';

        return null;
    }

    setCuerpo(html) {
        const c = this.correo;
        c.cuerpo = html.trim() === '<br>' ? '' : html;
        if (!c.manual && c.cuerpo.trim()) c.enviar = true; // al tener texto se asume que se quiere enviar, salvo que el usuario lo apague
    }

    aplicarPlantilla(id) {
        const t = this.correo.ctx?.plantillas.find((x) => String(x.id) === String(id));
        if (!t) return;
        this.correo.plantilla = String(t.id);
        this.setCuerpo(comoHtml(this.sustituir(t.content)));
        if (t.topic) this.correo.asunto = this.sustituir(t.topic);
    }

    vistaCorreo() {
        const c = this.correo;
        const dest = this.destinatarios();
        const ctx = c.ctx;

        return `<div class="asi-p5">
            <div class="asi-p5-i">
                <h3>Correo al cliente</h3>
                <label class="asi-switch"><input type="checkbox" data-correo-enviar ${c.enviar ? 'checked' : ''}><span class="asi-sw"></span><b>Enviar este correo al crear la solicitud</b></label>
                <div class="asi-dest"><small>Para</small>${dest.map((d) => `<span class="asi-tag ${d.email ? '' : 'oro'}" title="${esc(d.email || 'Este contacto no tiene correo')}"><i class="fa-regular fa-${d.email ? 'envelope' : 'circle-xmark'}"></i>${esc(d.nombre)}${d.email ? ` · ${esc(d.email)}` : ' · sin correo'}</span>`).join('')}</div>
                <div class="asi-campos asi-campos2">
                    <label class="asi-campo">Plantilla<select data-correo-plantilla ${ctx ? '' : 'disabled'}><option value="">${ctx ? 'Sin plantilla' : 'Cargando plantillas…'}</option>${(ctx?.plantillas ?? []).map((t) => `<option value="${t.id}" ${String(t.id) === c.plantilla ? 'selected' : ''}>${esc(t.label)}</option>`).join('')}</select></label>
                    <label class="asi-campo">Asunto<input data-correo-asunto maxlength="250" value="${esc(c.asunto)}" placeholder="Ej. Solicitud ${this.refProvisional()} recibida"></label>
                </div>
                <div class="asi-editor">
                    <div class="asi-barra">
                        <button type="button" data-cmd="bold" title="Negrita"><i class="fa-solid fa-bold"></i></button><button type="button" data-cmd="italic" title="Cursiva"><i class="fa-solid fa-italic"></i></button>
                        <button type="button" data-cmd="underline" title="Subrayado"><i class="fa-solid fa-underline"></i></button><button type="button" data-cmd="insertUnorderedList" title="Lista"><i class="fa-solid fa-list-ul"></i></button>
                        <button type="button" data-cmd="createLink" title="Enlace"><i class="fa-solid fa-link"></i></button><button type="button" data-cmd="removeFormat" title="Quitar formato"><i class="fa-solid fa-eraser"></i></button>
                    </div>
                    <div class="asi-cuerpo-mail" data-editor contenteditable="true" role="textbox" aria-multiline="true" aria-label="Cuerpo del correo"></div>
                </div>
                <div class="asi-ia-correo">
                    <label class="asi-campo">Indicaciones para la IA <small>(opcional)</small><input data-correo-instr maxlength="1000" value="${esc(c.instrucciones)}" placeholder="Ej. más corto y formal; menciona que cotizamos esta semana"></label>
                    <div class="asi-acciones">
                        <button type="button" class="asi-btn primario" data-correo-redactar ${c.redactando ? 'disabled' : ''}><i class="fa-solid ${c.redactando ? 'fa-spinner fa-spin' : 'fa-wand-magic-sparkles'}"></i>${c.redactando ? 'Redactando…' : c.cuerpo.trim() ? 'Mejorar con IA' : 'Redactar con IA'}</button>
                        <button type="button" class="asi-prompt-btn" data-prompt-toggle title="Ver o editar lo que se le pide a la IA"><i class="fa-solid fa-sliders"></i>Instrucciones de la IA</button>
                        <small>Usa la plantilla o el texto actual como base y las piezas de la solicitud.</small>
                    </div>
                    <div class="asi-prompt" data-prompt hidden></div>
                </div>
                ${c.error ? `<p class="asi-alerta"><i class="fa-solid fa-triangle-exclamation"></i>${esc(c.error)}</p>` : ''}
            </div>
            <aside class="asi-p5-d">
                <h4>Vista previa <small>así le llega al cliente</small></h4>
                <div class="asi-mail">
                    <div class="asi-mail-cab">
                        <div><small>De</small><span>${esc(ctx?.asesor ?? '')}${ctx?.de ? ` &lt;${esc(ctx.de)}&gt;` : ''}</span></div>
                        <div><small>Para</small><span data-prev-para></span></div>
                        <div><small>Asunto</small><b data-prev-asunto></b></div>
                    </div>
                    <iframe class="asi-mail-frame" data-preview sandbox="allow-same-origin" title="Vista previa del correo"></iframe>
                </div>
                <p class="asi-ayuda">${ctx ? (ctx.firma ? `<i class="fa-solid fa-signature"></i>La firma de ${esc(ctx.asesor ?? 'tu usuario')} se agrega sola al final del correo.` : '<i class="fa-solid fa-triangle-exclamation"></i>Tu usuario no tiene firma configurada: el correo saldrá sin firma. Puedes agregarla en tu perfil.') : ''}</p>
            </aside>
        </div>`;
    }

    /** Carga plantillas y firma la primera vez y deja el editor y la vista previa al día. */
    async iniciarCorreo() {
        const ed = this.q('[data-editor]');
        if (ed) ed.innerHTML = limpiarHtml(this.correo.cuerpo);
        this.pintarPrompt();
        this.refrescarPrevia();
        const c = this.correo;
        if (c.ctx || c.cargando) return;
        c.cargando = true;
        try {
            c.ctx = await this.pedir(this.urls.correoContextoUrl);
            if (!c.cuerpo.trim() && c.ctx.plantilla_defecto) this.aplicarPlantilla(c.ctx.plantilla_defecto); // arranca con la plantilla por defecto, como al crear una solicitud a mano
        } catch (e) { c.error = e.message; }
        c.cargando = false;
        if (this.abierto && this.paso === PASO_CORREO) this.pintar();
    }

    /** Actualiza «Para», «Asunto» y el correo tal como llegará (cuerpo + firma), sin tocar el editor. */
    refrescarPrevia() {
        clearTimeout(this.tPrevia);
        this.tPrevia = setTimeout(() => {
            const frame = this.q('[data-preview]');
            if (!frame) return;
            const c = this.correo;
            const firma = c.ctx?.firma ?? '';
            const cuerpo = c.cuerpo.trim() ? c.cuerpo : '<p style="color:#9ca3af">El cuerpo del correo aparecerá aquí.</p>';
            this.q('[data-prev-para]').textContent = this.destinatarios().filter((d) => d.email).map((d) => `${d.nombre} <${d.email}>`).join(', ') || 'Sin correo';
            this.q('[data-prev-asunto]').textContent = c.asunto || `SOLICITUD CREADA ${this.refProvisional()}`;
            frame.onload = () => { try { frame.style.height = `${Math.max(220, frame.contentDocument.documentElement.scrollHeight + 8)}px`; } catch { /* sin acceso */ } };
            frame.srcdoc = `<!doctype html><html><head><meta charset="utf-8"><style>body{font:14px/1.55 Arial,Helvetica,sans-serif;color:#1f2937;margin:18px;word-wrap:break-word}p{margin:0 0 10px}img{max-width:100%;height:auto}a{color:#2563eb}</style></head><body>${cuerpo}${firma ? `<br><div>${firma}</div>` : ''}</body></html>`;
        }, 120);
    }

    async redactarCorreo() {
        const c = this.correo;
        if (c.redactando) return;
        c.redactando = true;
        c.error = null;
        this.pintar();
        try {
            const r = await this.pedir(this.urls.correoRedactarUrl, 'POST', {
                fk_soc: this.cliente.id, contacto_id: this.contacto, asunto: this.asunto || this.asuntoSugerido(),
                lineas: this.piezas().map((i) => ({ description: this.descripcionLinea(i), model: i.referencia ? i.referencia.slice(0, 40) : null, qty: Number(i.cantidad) || 1 })),
                base: this.q('[data-editor]')?.innerText.trim() || null, instrucciones: c.instrucciones || null,
            });
            this.setCuerpo(comoHtml(this.sustituir(r.cuerpo)));
            if (r.asunto) c.asunto = this.sustituir(r.asunto);
            toast.fire({ icon: 'success', title: 'Correo redactado: revisa la vista previa' });
        } catch (e) { c.error = e.message; }
        c.redactando = false;
        if (this.abierto && this.paso === PASO_CORREO) this.pintar();
    }

    /** Cuerpo y asunto definitivos: la referencia provisional se cambia por la real de la solicitud ya creada. */
    cuerpoFinal(ref) { return this.correo.cuerpo.replaceAll(this.refProvisional(), ref); }

    asuntoFinal(ref) { return (this.correo.asunto.trim() || 'SOLICITUD CREADA __REF__').replaceAll(this.refProvisional(), ref); }

    // ------------------------------------------------------------ paso 5: revisión y creación

    vistaRevision() {
        const c = this.datos.contactos.find((x) => x.id === this.contacto);

        return `<div class="asi-p4">
            <div class="asi-p4-i"><h3>Revisa y crea la solicitud</h3>
                <div class="asi-resumen">
                    <button type="button" data-ir="0" title="Cambiar el cliente (reinicia el contacto y el análisis)"><small>Cliente</small><b>${esc(this.cliente.nombre)}</b><i class="fa-solid fa-pen"></i></button>
                    <button type="button" data-ir="1" title="Cambiar el contacto"><small>Contacto</small><b>${esc(c?.label.split(' — ')[0] ?? '')}</b>${this.relacionados.size ? `<small>+ ${this.relacionados.size} más</small>` : ''}<i class="fa-solid fa-pen"></i></button>
                    <button type="button" data-ir="${PASO_CORREO}" title="Ver o cambiar el correo"><small>Correo al cliente</small><b>${this.correo.enviar && !this.motivoCorreo() ? 'Se envía al crear' : this.correo.enviar ? 'Incompleto' : 'No se envía'}</b>${this.correo.enviar ? `<small>${esc(this.correo.asunto || '')}</small>` : ''}<i class="fa-solid fa-pen"></i></button>
                    <div><small>Fecha</small><b>${fechaTxt(hoyIso())}</b></div>
                </div>
                <label class="asi-campo">Asunto de la solicitud<input data-asunto maxlength="150" value="${esc(this.asunto)}" autofocus></label>
                <label class="asi-campo corto">Próximo contacto <small>(opcional)</small><input type="date" data-proximo min="${hoyIso()}" value="${esc(this.proximo)}"></label>
                <p class="asi-ayuda"><i class="fa-regular fa-lightbulb"></i>El análisis de IA queda guardado en cada producto de la solicitud. Las fotos se usan para el análisis y no se guardan en la solicitud.</p>
            </div>
            <div class="asi-p4-d"><h4>${this.items.length === 1 ? 'Pieza' : 'Piezas'} (${this.items.length})</h4>
            <ul class="asi-items">${this.items.map((i, n) => `<li>
                ${i.foto ? `<img src="${i.foto}" alt="">` : '<span class="asi-ico"><i class="fa-solid fa-box"></i></span>'}
                <div><b>${esc(i.ia?.identificacion?.nombre || i.nombre || i.referencia || 'Pieza')}</b><small>${[i.referencia, `${nf2.format(Number(i.cantidad) || 1)} und`].filter(Boolean).map(esc).join(' · ')}</small>
                    ${i.descripcion ? `<p>${esc(i.descripcion.slice(0, 160))}</p>` : ''}</div>
                ${i.cargandoIa ? '<span class="asi-tag violeta"><i class="fa-solid fa-spinner fa-spin"></i>Analizando</span>' : `<span class="asi-tag ${i.ia ? 'violeta' : ''}">${i.ia ? 'Con análisis IA' : i.iaError ? 'Análisis con error' : 'Sin analizar'}</span>`}
                ${i.historial?.comprado ? '<span class="asi-tag verde">Comprado antes</span>' : ''}
                <button type="button" data-editar-item="${n}" title="Editar la pieza"><i class="fa-solid fa-pen"></i></button></li>`).join('')}</ul></div>
        </div>`;
    }

    descripcionLinea(i) {
        const base = i.nombre || i.ia?.identificacion?.nombre || i.referencia || 'Pieza';

        return `${base}${i.descripcion ? ` — ${i.descripcion}` : ''}`.slice(0, 2000);
    }

    analisisLinea(i) {
        if (!i.ia) return '';
        const id = i.ia.identificacion;
        const partes = [id.nombre, [id.marca, id.referencia].filter(Boolean).join(' '), id.parte].filter(Boolean).join(' · ');
        const resumen = `${partes}${id.descripcion ? `. ${id.descripcion}` : ''}${i.ia.recomendacion ? ` Recomendado: ${i.ia.recomendacion.proveedor}. ${i.ia.recomendacion.motivo}` : ''}`.slice(0, 1500);

        return JSON.stringify({
            resumen,
            donde_conseguir: i.ia.proveedores.map((p) => ({ proveedor: p.proveedor, pais: p.pais, precio: p.precio, url: p.url, nota: [p.tiempo_entrega, p.transporte, p.nota].filter(Boolean).join(' · ').slice(0, 200) })),
        });
    }

    async crear() {
        if (this.creando) return;
        if (this.items.some((i) => i.cargandoIa) && !(await Swal.fire({ icon: 'info', title: 'Hay análisis en curso', text: 'Si creas ahora, esas piezas quedarán sin análisis.', showCancelButton: true, confirmButtonText: 'Crear igual', cancelButtonText: 'Esperar', reverseButtons: true })).isConfirmed) return;
        this.creando = true;
        this.pintar();
        this.q('[data-sig]').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>Creando…';
        let creada = null;
        try {
            creada = await this.pedir(this.urls.storeUrl, 'POST', {
                fk_soc: this.cliente.id, contacto_id: this.contacto, relacionados: [...this.relacionados], fecha_solicitud: hoyIso(),
                fecha_proximo_contacto: this.proximo || null, asunto: this.asunto.trim(),
            });
            await this.pedir(creada.updateUrl, 'PUT', {
                seccion: 'productos',
                lineas: this.items.map((i) => ({
                    description: this.descripcionLinea(i), model: i.referencia ? i.referencia.slice(0, 40) : null, qty: Number(i.cantidad) > 0 ? Number(i.cantidad) : 1,
                    fk_product: i.fk_product, analisis: this.analisisLinea(i),
                })),
            });
        } catch (e) {
            this.creando = false;
            if (creada) {
                // La solicitud ya existe; solo faltaron los productos: se abre para completarlos a mano.
                this.cerrarYa();
                this.onCreada?.(creada.id);
                toast.fire({ icon: 'warning', title: `Se creó ${creada.ref}, pero no se pudieron guardar los productos: ${e.message}` });
                return;
            }
            toast.fire({ icon: 'error', title: e.message });
            this.pintar();
            return;
        }
        // El correo va después de crear: si falla, la solicitud ya existe y se abre para reenviarlo desde su paso «Detalle y correo».
        let errorCorreo = null;
        if (this.correo.enviar) {
            try {
                await this.pedir(creada.updateUrl, 'PUT', {
                    seccion: 'detalle', detalle: this.cuerpoFinal(creada.ref), enviar_correo: true, asunto_correo: this.asuntoFinal(creada.ref), incluir_firma: true,
                });
            } catch (e) { errorCorreo = e.message; }
        }
        this.cerrarYa();
        if (errorCorreo) toast.fire({ icon: 'warning', title: `Solicitud ${creada.ref} creada, pero el correo no se envió: ${errorCorreo}` });
        else toast.fire({ icon: 'success', title: this.correo.enviar ? `Solicitud ${creada.ref} creada y correo enviado` : `Solicitud ${creada.ref} creada` });
        this.onCreada?.(creada.id);
    }
    // ------------------------------------------------------------ eventos

    /** Tras cambiar el correo: el interruptor, la marca del paso y la vista previa. */
    refrescarCorreoUi() {
        const sw = this.q('[data-correo-enviar]');
        if (sw) sw.checked = this.correo.enviar;
        this.q('[data-pasos]').innerHTML = this.htmlPasos();
        this.refrescarPrevia();
    }

    alEscribir(e) {
        const t = e.target;
        if (t.matches('[data-editor]')) { this.setCuerpo(t.innerHTML); this.refrescarCorreoUi(); return; }
        if (t.matches('[data-correo-asunto]')) { this.correo.asunto = t.value; this.refrescarPrevia(); return; }
        if (t.matches('[data-correo-instr]')) { this.correo.instrucciones = t.value; return; }
        if (t.matches('[data-habla]')) { t.style.height = 'auto'; t.style.height = `${Math.min(t.scrollHeight, 110)}px`; return; }
        if (t.matches('[data-q-cliente]')) { clearTimeout(this.espera); this.espera = setTimeout(() => this.buscarClientes(t.value), 250); return; }
        if (t.matches('[data-f]')) {
            const it = this.items[this.actual];
            const k = t.dataset.f;
            it[k] = t.value;
            if ((k === 'referencia' || k === 'nombre') && it.fk_product && it.vinculo) { it.fk_product = null; it.vinculo = null; it.historial = null; this.q('.asi-vinculo')?.remove(); }
            if (k === 'referencia') { clearTimeout(this.esperaC); this.esperaC = setTimeout(() => this.cargarCoincidencias(), 700); }
            if (k === 'referencia' || k === 'nombre') { clearTimeout(this.esperaP); this.esperaP = setTimeout(() => this.buscarProductos(t.value), 250); }
            this.refrescarAnalizar();
            return;
        }
        if (t.matches('[data-asunto]')) { this.asunto = t.value; this.q('[data-sig]').disabled = !this.puedeAvanzar(); }
        if (t.matches('[data-proximo]')) this.proximo = t.value;
    }

    alCambiar(e) {
        const t = e.target;
        if (t.matches('[data-correo-enviar]')) { this.correo.enviar = t.checked; this.correo.manual = true; return; }
        if (t.matches('[data-correo-plantilla]')) {
            if (t.value) { this.aplicarPlantilla(t.value); this.pintar(); } else this.correo.plantilla = '';
            return;
        }
        if (t.matches('[data-efec-f]') && this.efec) { this.efec[t.dataset.efecF] = t.value; this.cargarEfectividad(); return; }
        if (t.matches('[data-foto]')) this.leerFoto(t.files?.[0]);
        else if (t.name === 'contacto') { this.contacto = Number(t.value); this.relacionados.delete(this.contacto); this.pintar(); }
        else if (t.matches('[data-rel]')) { const id = Number(t.dataset.rel); if (t.checked) this.relacionados.add(id); else this.relacionados.delete(id); }
    }

    alClic(e) {
        const t = e.target;
        const b = (sel) => t.closest(sel);
        if (b('[data-cerrar]')) return this.cerrar();
        const ep = b('[data-efec-p]');
        if (ep && this.efec) {
            this.efec.periodo = ep.dataset.efecP;
            if (this.efec.periodo === 'rango' && !this.efec.hasta) {
                const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
                const hoy = new Date();
                const ini = new Date();
                ini.setMonth(ini.getMonth() - 3);
                Object.assign(this.efec, { desde: iso(ini), hasta: iso(hoy) });
            }
            return this.cargarEfectividad();
        }
        if (b('[data-accion-lado]')) return this.cambiarLado();
        const ancho = b('[data-accion-ancho]');
        if (ancho) return this.cambiarAncho(ancho.dataset.accionAncho);
        if (b('[data-atras]')) return this.irA(this.paso - 1);
        if (b('[data-sig]')) return this.siguiente();
        const ir = b('[data-ir]');
        if (ir) return this.irA(Number(ir.dataset.ir));
        if (b('[data-enviar-habla]')) return this.enviarHabla();
        if (b('[data-mic-habla]')) {
            const area = this.q('[data-habla]');
            const antes = area.value ? `${area.value.trimEnd()} ` : '';
            return this.dictar({
                base: antes, boton: b('[data-mic-habla]'),
                alCambiar: (txt) => { area.value = txt; area.dispatchEvent(new Event('input', { bubbles: true })); },
                alTerminar: () => { if (area.value.trim() && !this.interpretando) this.enviarHabla(); }, // al dejar de dictar se envía solo
            });
        }
        const pend = b('[data-elegir-pend]');
        if (pend) return this.elegirPendiente(Number(pend.dataset.elegirPend));
        if (!b('.asi-sugs') && !b('[data-q-cliente]')) this.dlg.querySelectorAll('[data-sugs],[data-sugs-prod]').forEach((s) => { s.hidden = true; });

        const cli = b('[data-cliente]');
        if (cli) return this.elegirCliente(Number(cli.dataset.cliente), cli.dataset.nombre);
        const prod = b('[data-prod]');
        if (prod) {
            const it = this.items[this.actual];
            Object.assign(it, { fk_product: Number(prod.dataset.prod), referencia: prod.dataset.ref, nombre: prod.dataset.label, vinculo: `${prod.dataset.ref} — ${prod.dataset.label}`, historial: null, ia: null, iaError: null });
            this.pintar();
            return this.analizar();
        }
        if (b('[data-desvincular]')) { Object.assign(this.items[this.actual], { fk_product: null, vinculo: null, historial: null }); return this.pintar(); }
        const quitar = b('[data-quitar]');
        if (quitar) { this.items.splice(Number(quitar.dataset.quitar), 1); this.actual = Math.min(this.actual, this.items.length - 1); return this.pintar(); }
        const tab = b('[data-tab]');
        if (tab) { this.actual = Number(tab.dataset.tab); return this.pintar(); }
        if (b('[data-nuevo]')) { this.items.push(itemVacio()); this.actual = this.items.length - 1; return this.pintar(); }
        if (b('[data-quitar-foto]')) { this.items[this.actual].foto = null; return this.pintar(); }
        if (b('[data-mic]')) {
            const area = this.q('[data-f=descripcion]');
            const it = this.items[this.actual];
            return this.dictar({
                base: it.descripcion ? `${it.descripcion.trimEnd()} ` : '', boton: b('[data-mic]'),
                alCambiar: (txt) => { it.descripcion = txt.slice(0, 3000); if (area?.isConnected) area.value = it.descripcion; this.refrescarAnalizar(); },
            });
        }
        if (b('[data-analizar]')) return this.analizar();
        if (b('[data-mas-piezas]')) return this.verMasPiezas();
        if (b('[data-correo-redactar]')) return this.redactarCorreo();
        if (b('[data-prompt-toggle]')) { this.promptAbierto = !this.promptAbierto; return this.pintarPrompt(); }
        if (b('[data-prompt-guardar]')) return this.guardarPrompt(this.q('[data-prompt-texto]').value);
        if (b('[data-prompt-defecto]')) return this.guardarPrompt('');
        const ed = b('[data-editar-item]');
        if (ed) { this.actual = Number(ed.dataset.editarItem); this.paso = 2; return this.pintar(); }
        return null;
    }
}
