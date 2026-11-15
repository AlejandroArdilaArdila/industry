// Panel lateral de ficha (canvas deslizante, derecha o izquierda): asistente por pasos donde cada paso (<form data-step>)
// se guarda por separado. El HTML lo renderiza Blade y llega como JSON {html};
// aquí vive solo el comportamiento. Lo usan terceros, usuarios y productos, que
// extienden esta clase con sus particularidades (hooks al final del archivo).
//
// Contrato del HTML (ver resources/views/panel/partials/ficha/*):
//   [data-ficha]                raíz; data-update-url, data-puede-editar,
//                               y en modo creación data-nuevo="1" + data-store-url
//   [data-ficha-catalogos]      JSON con los datos de los combos con búsqueda
//   form[data-step]             un paso; data-url opcional si guarda en otra ruta,
//                               data-limpiar para vaciarlo tras guardar
//   [data-step-target]          botón de la barra lateral que lleva a un paso
//   [data-combo=nombre]         combo Syncfusion; el valor viaja en input[type=hidden][name=nombre]
//   [data-required]/[data-recommended]  campos que marcan el estado del paso
//   [data-error-for=campo]      donde se pinta el error de validación de ese campo
import '../../css/ficha-modal.css';
import { DropDownList } from '@syncfusion/ej2-dropdowns';
import Swal from 'sweetalert2';

export const toast = Swal.mixin({
    toast: true,
    position: 'bottom-end',
    showConfirmButton: false,
    timer: 2200,
    timerProgressBar: true,
});

export const normalizar = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

const CLAVE_LADO = 'ficha-panel-lado';

function ladoGuardado() {
    try {
        return localStorage.getItem(CLAVE_LADO) === 'izquierda' ? 'izquierda' : 'derecha';
    } catch {
        return 'derecha';
    }
}

function guardarLado(lado) {
    try { localStorage.setItem(CLAVE_LADO, lado); } catch { /* sin almacenamiento */ }
}

export function serializar(form) {
    return new URLSearchParams(new FormData(form)).toString();
}

export class FichaModal {
    /**
     * @param {{
     *   fichaUrl: string,          con el marcador __ID__
     *   nuevoUrl?: string,         ficha vacía para crear
     *   csrfToken: string,
     *   etiqueta?: string,         "el tercero", "el usuario"… para los mensajes
     *   onSaved?: (json: object) => void,
     * }} opts
     */
    constructor(opts) {
        this.opts = { etiqueta: 'el registro', ...opts };
        this.combos = [];
        this.snapshots = new Map();
        this.estados = new Map();
        this.pasoActual = null;
        this.crearContenedor();
    }

    crearContenedor() {
        this.overlay = document.createElement('div');
        this.overlay.className = 'ficha-overlay';
        this.overlay.dataset.lado = ladoGuardado();
        this.overlay.hidden = true;
        this.overlay.innerHTML = `
            <div role="dialog" aria-modal="true"
                 class="ficha-dialog flex flex-col overflow-hidden border border-border bg-card text-card-foreground">
            </div>`;
        document.body.appendChild(this.overlay);
        this.dialog = this.overlay.firstElementChild;
        this.dialog.setAttribute('aria-label', `Ficha de ${this.opts.etiqueta}`);

        this.overlay.addEventListener('mousedown', (e) => {
            if (e.target === this.overlay) this.cerrar();
        });

        document.addEventListener('keydown', (e) => {
            if (this.overlay.hidden) return;
            if (e.key === 'Escape' && !document.querySelector('.swal2-container')) {
                e.preventDefault();
                this.cerrar();
            }
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                this.formActual()?.requestSubmit();
            }
        });
    }

    /** Botones de la cabecera: cambiar de lado y ampliar/reducir el panel. */
    crearControles() {
        const cabecera = this.root.querySelector('header');
        if (!cabecera) return;
        const boton = (accion, icono, titulo) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.dataset.panelAccion = accion;
            b.title = titulo;
            b.setAttribute('aria-label', titulo);
            b.innerHTML = `<i class="fa-solid ${icono}"></i>`;
            return b;
        };
        const barra = document.createElement('div');
        barra.className = 'ficha-controles';
        const lado = boton('lado', 'fa-arrow-right-arrow-left', 'Mover el panel al otro lado');
        // Ancho de partida: '' (todo menos la franja libre) o 'mitad' (media pantalla). El botón alterna con el otro extremo.
        this.anchoInicial ??= this.dialog.dataset.ancho || '';
        const mitad = this.anchoInicial === 'mitad';
        const ancho = boton('ancho', mitad ? 'fa-expand' : 'fa-compress', mitad ? 'Ampliar el panel' : 'Reducir el panel');
        lado.addEventListener('click', () => this.cambiarLado());
        ancho.addEventListener('click', () => {
            const alterno = mitad ? '' : 'compacto';
            const enAlterno = this.dialog.dataset.ancho === alterno;
            this.dialog.dataset.ancho = enAlterno ? this.anchoInicial : alterno;
            const grande = mitad ? enAlterno === false : enAlterno;
            ancho.firstElementChild.className = `fa-solid ${grande ? 'fa-compress' : 'fa-expand'}`;
            ancho.title = grande ? 'Reducir el panel' : 'Ampliar el panel';
        });
        barra.append(lado, ancho);
        cabecera.appendChild(barra);

        if (this.forms.length > 1) {
            this.progreso = document.createElement('div');
            this.progreso.className = 'ficha-progreso';
            this.progreso.innerHTML = '<span></span>';
            this.dialog.prepend(this.progreso);
        }
    }

    cambiarLado() {
        const nuevo = this.overlay.dataset.lado === 'derecha' ? 'izquierda' : 'derecha';
        this.dialog.classList.remove('is-open');
        setTimeout(() => {
            this.overlay.dataset.lado = nuevo;
            guardarLado(nuevo);
            void this.dialog.offsetWidth;
            this.dialog.classList.add('is-open');
        }, 320);
    }

    abrir(id) {
        return this.cargar(this.opts.fichaUrl.replace('__ID__', id));
    }

    abrirNuevo(query = '') {
        return this.cargar(this.opts.nuevoUrl + query);
    }

    async cargar(url) {
        this.destruirCombos();
        this.snapshots.clear();
        this.estados.clear();
        this.forms = [];
        this.dialog.innerHTML = this.skeleton();
        this.overlay.hidden = false;
        document.documentElement.style.overflow = 'hidden';
        void this.overlay.offsetWidth;
        this.overlay.classList.add('is-open');
        this.dialog.classList.add('is-open');

        try {
            const res = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const { html } = await res.json();
            this.dialog.innerHTML = html;
            this.inicializar();
        } catch (e) {
            console.error(e);
            this.dialog.innerHTML = `
                <div class="flex h-full flex-col items-center justify-center gap-3 p-8 text-center">
                    <i class="fa-solid fa-circle-exclamation text-3xl text-destructive"></i>
                    <p class="font-medium">No se pudo cargar ${this.opts.etiqueta}</p>
                    <button type="button" data-ficha-close class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted">Cerrar</button>
                </div>`;
            this.dialog.querySelector('[data-ficha-close]').addEventListener('click', () => this.cerrar(true));
        }
    }

    async cerrar(forzar = false) {
        if (!forzar && this.pasosConCambios().length > 0) {
            const { isConfirmed } = await Swal.fire({
                title: '¿Salir sin guardar?',
                text: `Tienes cambios sin guardar en: ${this.pasosConCambios().map((p) => this.tituloPaso(p)).join(', ')}.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Salir sin guardar',
                cancelButtonText: 'Seguir editando',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
            });
            if (!isConfirmed) return;
        }

        this.overlay.classList.remove('is-open');
        this.dialog.classList.remove('is-open');
        setTimeout(() => {
            if (this.overlay.classList.contains('is-open')) return;
            this.overlay.hidden = true;
            this.destruirCombos();
            this.dialog.innerHTML = '';
            this.dialog.dataset.ancho = this.anchoInicial ?? '';
            this.forms = [];
            document.documentElement.style.overflow = '';
        }, 420);
    }

    skeleton() {
        const barra = (w) => `<div class="h-3 ${w} animate-pulse rounded bg-muted"></div>`;
        return `
            <div class="flex items-center gap-4 border-b border-border p-6">
                <div class="h-14 w-14 animate-pulse rounded-2xl bg-muted"></div>
                <div class="flex-1 space-y-2">${barra('w-1/3')}${barra('w-1/4')}</div>
            </div>
            <div class="flex flex-1">
                <div class="hidden w-64 space-y-3 border-r border-border p-4 md:block">${Array(5).fill(barra('w-full h-9')).join('')}</div>
                <div class="flex-1 space-y-5 p-7">${Array(6).fill(barra('w-full h-10')).join('')}</div>
            </div>`;
    }

    // ------------------------------------------------------------------ init

    inicializar() {
        this.root = this.dialog.querySelector('[data-ficha]');
        this.puedeEditar = this.root.dataset.puedeEditar === '1';
        this.esNuevo = this.root.dataset.nuevo === '1';
        const catalogos = this.root.querySelector('[data-ficha-catalogos]');
        this.catalogos = catalogos ? JSON.parse(catalogos.textContent) : {};
        this.forms = [...this.root.querySelectorAll('form[data-step]')];

        this.root.querySelectorAll('[data-ficha-close]').forEach((b) => b.addEventListener('click', () => this.cerrar()));
        this.root.querySelectorAll('[data-step-target]').forEach((b) => {
            b.addEventListener('click', () => this.irA(b.dataset.stepTarget));
        });
        this.root.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', () => this.copiar(b.dataset.copy)));

        this.inicializarCombos();
        this.inicializarExtras();
        this.crearControles();

        this.forms.forEach((form) => {
            this.snapshots.set(form.dataset.step, serializar(form));
            form.addEventListener('input', () => this.actualizarEstado(form));
            form.addEventListener('change', () => this.actualizarEstado(form));
            form.addEventListener('submit', (e) => { e.preventDefault(); this.guardar(form); });
            form.querySelector('[data-step-prev]')?.addEventListener('click', () => this.irRelativo(form, -1));
            form.querySelector('[data-step-next]')?.addEventListener('click', () => this.irRelativo(form, 1));
            form.querySelector('[data-step-discard]')?.addEventListener('click', () => this.descartar(form));
            this.actualizarEstado(form);
        });

        this.bloquearPasos(this.esNuevo);

        // Abre en el primer paso al que le falte algo obligatorio; si no, en el primero.
        const pendiente = this.esNuevo ? null : this.forms.find((f) => this.faltantes(f, '[data-required]').length > 0);
        // Vistas sin formulario por pasos (p. ej. el detalle de una tarea) no tienen paso al que ir.
        const inicial = pendiente ?? this.forms[0];
        if (inicial) this.irA(inicial.dataset.step);
    }

    /** En modo creación solo el primer paso está disponible hasta que se crea el registro. */
    bloquearPasos(bloquear) {
        this.forms.slice(1).forEach((f) => {
            const btn = this.root.querySelector(`[data-step-target="${f.dataset.step}"]`);
            if (!btn) return;
            btn.disabled = bloquear;
            btn.classList.toggle('opacity-50', bloquear);
            btn.classList.toggle('cursor-not-allowed', bloquear);
            btn.title = bloquear ? `Primero crea ${this.opts.etiqueta}` : '';
        });
    }

    pasoBloqueado(paso) {
        return this.esNuevo && this.forms[0]?.dataset.step !== paso;
    }

    inicializarCombos() {
        this.root.querySelectorAll('[data-combo]').forEach((el) => {
            const nombre = el.dataset.combo;
            const hidden = el.parentElement.querySelector(`input[type=hidden][name="${nombre}"]`);
            const datos = this.catalogos[nombre] ?? [];
            // Las opciones se leen de "entrada" para que cambiarDatosCombo() pueda reemplazarlas.
            const entrada = { nombre, hidden, datos };

            const combo = new DropDownList({
                dataSource: datos,
                fields: { value: 'id', text: 'label' },
                value: hidden.value !== '' ? this.tipar(datos, hidden.value) : null,
                placeholder: el.dataset.placeholder ?? '',
                allowFiltering: true,
                filterBarPlaceholder: 'Buscar…',
                showClearButton: true,
                popupHeight: '300px',
                zIndex: 2000,
                cssClass: 'ficha-combo',
                enabled: !('disabled' in el.dataset),
                noRecordsTemplate: '<span class="text-sm text-muted-foreground">Sin resultados</span>',
                filtering: (e) => {
                    const q = normalizar(e.text);
                    e.updateData(q ? entrada.datos.filter((d) => normalizar(d.label).includes(q)) : entrada.datos);
                },
                change: (e) => {
                    hidden.value = e.value ?? '';
                    this.alCambiarCombo(nombre, e.itemData);
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                },
            });
            combo.appendTo(el);
            // El componente crea su propio <select>/<input> con name; el valor real viaja en el hidden.
            combo.element.closest('.e-input-group')?.querySelectorAll('[name]').forEach((n) => n.removeAttribute('name'));
            entrada.combo = combo;
            this.combos.push(entrada);
        });
    }

    combo(nombre) {
        return this.combos.find((c) => c.nombre === nombre);
    }

    /** Reemplaza las opciones de un combo; si el valor elegido ya no está, lo limpia. */
    cambiarDatosCombo(nombre, datos) {
        const c = this.combo(nombre);
        if (!c) return;
        c.datos = datos;
        c.combo.dataSource = datos;
        if (c.hidden.value !== '' && this.tipar(datos, c.hidden.value) === null) {
            c.combo.value = null;
            c.hidden.value = '';
            c.hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // Los ids del catálogo pueden ser número o string; se busca el valor con el mismo tipo.
    tipar(datos, valor) {
        const item = datos.find((d) => String(d.id) === String(valor));
        return item ? item.id : null;
    }

    destruirCombos() {
        this.combos.forEach(({ combo }) => combo.destroy());
        this.combos = [];
    }

    // ------------------------------------------------------------ navegación

    formActual() {
        return this.forms?.find((f) => f.dataset.step === this.pasoActual);
    }

    irA(paso) {
        if (this.pasoBloqueado(paso)) return;
        this.pasoActual = paso;
        this.forms.forEach((f) => {
            f.classList.toggle('hidden', f.dataset.step !== paso);
            f.classList.toggle('flex', f.dataset.step === paso);
        });
        this.root.querySelectorAll('[data-step-target]').forEach((b) => {
            b.toggleAttribute('data-active', b.dataset.stepTarget === paso);
        });
        this.root.querySelector(`[data-step-target="${paso}"]`)?.scrollIntoView({ block: 'nearest', inline: 'center' });
        this.root.querySelector('[data-ficha-scroll]').scrollTop = 0;
        if (this.progreso) {
            this.progreso.firstElementChild.style.width = `${((this.forms.findIndex((f) => f.dataset.step === paso) + 1) / this.forms.length) * 100}%`;
        }
        const primerCampo = this.formActual()?.querySelector('input:not([type=hidden]):not(.sr-only):not([disabled]), select:not([disabled]), textarea:not([disabled])');
        if (primerCampo && window.matchMedia('(min-width: 768px)').matches) primerCampo.focus({ preventScroll: true });
    }

    irRelativo(form, delta) {
        const i = this.forms.indexOf(form) + delta;
        if (this.forms[i]) this.irA(this.forms[i].dataset.step);
    }

    tituloPaso(paso) {
        return this.root.querySelector(`[data-step-target="${paso}"] [data-step-title]`)?.textContent.trim() ?? paso;
    }

    // ------------------------------------------------------- estado de pasos

    faltantes(form, selector) {
        return [...form.querySelectorAll(selector)].filter((el) => !String(el.value ?? '').trim());
    }

    pasosConCambios() {
        return (this.forms ?? []).filter((f) => serializar(f) !== this.snapshots.get(f.dataset.step)).map((f) => f.dataset.step);
    }

    /**
     * Estado visual del paso en la barra lateral. Prioridad:
     * error > cambios sin guardar > faltan obligatorios > recién guardado > recomendado incompleto.
     */
    actualizarEstado(form) {
        const paso = form.dataset.step;
        const sucio = serializar(form) !== this.snapshots.get(paso);
        const faltanReq = this.faltantes(form, '[data-required]').length;
        const faltanRec = this.faltantes(form, '[data-recommended]').length;
        const estado = this.estados.get(paso);

        let color = null;
        let texto = null;
        if (estado === 'error' && sucio) { color = 'bg-destructive'; texto = 'Revisa los errores'; }
        else if (sucio) { color = 'bg-sky-500'; texto = 'Cambios sin guardar'; }
        else if (faltanReq) { color = 'bg-destructive'; texto = `Faltan ${faltanReq} obligatorio${faltanReq > 1 ? 's' : ''}`; }
        else if (estado === 'guardado') { color = 'bg-emerald-500'; texto = 'Guardado'; }
        else if (faltanRec) { color = 'bg-amber-400'; texto = 'Información incompleta'; }

        const btn = this.root.querySelector(`[data-step-target="${paso}"]`);
        const dot = btn?.querySelector('[data-step-dot]');
        const sub = btn?.querySelector('[data-step-sub]');
        if (dot) {
            dot.className = `absolute -top-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-card ${color ?? 'hidden'}`;
        }
        if (sub) sub.textContent = texto ?? sub.dataset.default;

        const discard = form.querySelector('[data-step-discard]');
        if (discard) {
            discard.classList.toggle('hidden', !sucio);
            discard.classList.toggle('inline-flex', sucio);
        }
        const status = form.querySelector('[data-step-status]');
        if (status) {
            status.dataset.default ??= status.textContent;
            status.textContent = sucio ? 'Cambios sin guardar · Ctrl+S' : status.dataset.default;
            status.classList.toggle('text-sky-600', sucio);
        }
    }

    descartar(form) {
        const original = new URLSearchParams(this.snapshots.get(form.dataset.step));
        [...form.elements].forEach((el) => {
            if (!el.name || el.disabled) return;
            if (el.type === 'checkbox' || el.type === 'radio') {
                el.checked = original.getAll(el.name).includes(el.value);
            } else if (el.type !== 'hidden' || !form.querySelector(`input[type=checkbox][name="${CSS.escape(el.name)}"]`)) {
                el.value = original.get(el.name) ?? '';
            }
        });
        this.combos.filter((c) => form.contains(c.hidden)).forEach((c) => {
            c.combo.value = c.hidden.value !== '' ? this.tipar(c.datos, c.hidden.value) : null;
        });
        this.alDescartar(form);
        this.limpiarErrores(form);
        this.estados.delete(form.dataset.step);
        this.actualizarEstado(form);
    }

    // --------------------------------------------------------------- guardar

    async guardar(form) {
        if (!this.puedeEditar) return;
        const paso = form.dataset.step;
        const boton = form.querySelector('[data-step-save]');
        const esUltimo = boton?.dataset.ultimo === '1';
        const creando = this.esNuevo;
        const sucio = serializar(form) !== this.snapshots.get(paso);

        // Sin cambios: solo avanza (no hace falta pegarle al servidor). Al crear siempre se envía.
        if (!sucio && !creando) {
            if (esUltimo) this.cerrar();
            else this.irRelativo(form, 1);
            return;
        }

        this.limpiarErrores(form);
        this.cargando(boton, true);

        const body = new FormData(form);
        if (!creando) body.append('_method', 'PUT');
        body.append('seccion', paso);
        const url = creando ? this.root.dataset.storeUrl : (form.dataset.url || this.root.dataset.updateUrl);

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.opts.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            const json = await res.json().catch(() => ({}));

            if (res.status === 422) {
                this.estados.set(paso, 'error');
                this.mostrarErrores(form, json.errors ?? {});
                this.actualizarEstado(form);
                toast.fire({ icon: 'error', title: 'Revisa los campos marcados' });
                return;
            }
            if (!res.ok) throw new Error(json.message || `HTTP ${res.status}`);
            // Un redirect (p. ej. sesión vencida) termina en una página HTML 200: no es un guardado.
            if (res.redirected || json.ok !== true) throw new Error('La sesión expiró o el servidor no confirmó el guardado. Recarga la página.');

            if (creando) this.marcarCreado(json);
            // Pasos como "contraseña" no deben conservar lo escrito tras guardar.
            if (form.hasAttribute('data-limpiar')) form.reset();

            this.snapshots.set(paso, serializar(form));
            this.estados.set(paso, 'guardado');
            this.actualizarEstado(form);
            this.actualizarCabecera(json);
            this.opts.onSaved?.(json);
            toast.fire({ icon: 'success', title: json.message || `${this.tituloPaso(paso)} guardado` });

            if (esUltimo) this.cerrar(true);
            else this.irRelativo(form, 1);
        } catch (e) {
            console.error(e);
            Swal.fire({ icon: 'error', title: 'No se pudo guardar', text: e.message });
        } finally {
            this.cargando(boton, false);
        }
    }

    /** Tras crear: el resto de pasos guardan sobre el registro nuevo. */
    marcarCreado(json) {
        if (!json.updateUrl) throw new Error('El servidor no devolvió la ruta del registro creado.');
        this.root.dataset.updateUrl = json.updateUrl;
        delete this.root.dataset.nuevo;
        this.esNuevo = false;
        this.bloquearPasos(false);

        const boton = this.forms[0].querySelector('[data-step-save]');
        if (boton) {
            delete boton.dataset.crear;
            const label = boton.querySelector('[data-label]');
            if (label) label.textContent = boton.dataset.ultimo === '1' ? 'Guardar y cerrar' : 'Guardar y continuar';
        }
        this.alCrear(json);
    }

    cargando(boton, activo) {
        if (!boton) return;
        boton.disabled = activo;
        boton.querySelector('[data-spinner]')?.classList.toggle('hidden', !activo);
    }

    /** Laravel devuelve "ef.campo" y "lista.0"; se buscan como name="ef[campo]" / name="lista[]". */
    mostrarErrores(form, errores) {
        let primero = null;
        Object.entries(errores).forEach(([campo, mensajes]) => {
            const base = campo.replace(/\.\d+$/, '');
            const p = form.querySelector(`[data-error-for="${CSS.escape(campo)}"]`)
                ?? form.querySelector(`[data-error-for="${CSS.escape(base)}"]`);
            if (p) {
                p.textContent = mensajes[0];
                p.classList.remove('hidden');
            }
            const partes = base.split('.');
            const nombreInput = partes.length > 1 ? `${partes[0]}[${partes.slice(1).join('][')}]` : base;
            const input = form.querySelector(`[name="${CSS.escape(nombreInput)}"]:not([type=hidden])`)
                ?? form.querySelector(`[name="${CSS.escape(`${base}[]`)}"]:not([type=hidden])`);
            input?.classList.add('!border-destructive');
            primero ??= input ?? p;
        });
        if (!primero && Object.keys(errores).length) {
            toast.fire({ icon: 'error', title: Object.values(errores)[0][0] });
        }
        primero?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    limpiarErrores(form) {
        form.querySelectorAll('[data-error-for]').forEach((p) => { p.textContent = ''; p.classList.add('hidden'); });
        form.querySelectorAll('.\\!border-destructive').forEach((el) => el.classList.remove('!border-destructive'));
    }

    async copiar(texto) {
        try {
            await navigator.clipboard.writeText(texto);
            toast.fire({ icon: 'success', title: 'Copiado' });
        } catch {
            toast.fire({ icon: 'info', title: texto });
        }
    }

    /** Pinta el estado Activo/Inactivo de la cabecera ([data-ficha-estado]). */
    pintarEstado(activo, textos = ['Activo', 'Inactivo']) {
        const estado = this.root.querySelector('[data-ficha-estado]');
        if (!estado) return;
        estado.textContent = activo ? textos[0] : textos[1];
        estado.className = `rounded-full px-2 py-0.5 text-xs font-medium ${activo
            ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
            : 'bg-amber-500/15 text-amber-700 dark:text-amber-300'}`;
    }

    // ------------------------------------------------------------------ hooks

    /** Comportamiento propio de cada ficha (se llama tras crear los combos). */
    inicializarExtras() {}

    alCambiarCombo(_nombre, _item) {}

    alDescartar(_form) {}

    alCrear(_json) {}

    /** Refresca la cabecera con lo que devolvió el servidor al guardar. */
    actualizarCabecera(json) {
        const titulo = this.root.querySelector('[data-ficha-titulo]');
        if (titulo && json.titulo) titulo.textContent = json.titulo;
    }
}
