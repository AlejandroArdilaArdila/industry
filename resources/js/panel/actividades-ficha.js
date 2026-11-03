// Panel lateral (media pantalla) del programador de actividades: buscar un documento, registrar el
// seguimiento con su recordatorio y ver/cerrar los pendientes. Reutiliza el comportamiento de FichaModal.
import Swal from 'sweetalert2';
import { FichaModal, toast } from './ficha-modal.js';
import { abrirResumenMeses } from './cliente-resumen.js';
import { esc, dineroCorto, isoLocal, COLORES_CARD } from './dashboard-util.js';

export class ActividadFichaModal extends FichaModal {
    constructor(opts) {
        super({ etiqueta: 'la actividad', ...opts });
        this.dialog.dataset.ancho = 'mitad';
        this.anchoInicial = 'mitad';
        this.fijo = false;
    }

    /** Abre el panel sobre un documento (solicitud, cotización u orden). */
    abrirDocumento(modulo, id, fijo = false) {
        this.fijo = fijo;
        this.lista?.vistos.add(`${modulo}:${id}`);
        return this.cargar(`${this.opts.panelUrl}?${new URLSearchParams({ modulo, id, fijo: fijo ? 1 : 0 })}`);
    }

    /** Abre el panel para programar una actividad: primero se elige el documento. */
    abrirNueva(conLista = false) {
        if (!conLista) this.lista = null;
        this.fijo = false;
        return this.cargar(this.opts.panelUrl);
    }

    tituloPaso() {
        return 'el seguimiento';
    }

    inicializarExtras() {
        if (this.root.querySelector('[data-buscar-q]')) this.iniciarBusqueda();
        else this.iniciarSeguimiento();
    }

    // ------------------------------------------------------------ búsqueda de documento

    iniciarBusqueda() {
        const colores = JSON.parse(this.root.dataset.colores || '{}');
        const q = this.root.querySelector('[data-buscar-q]');
        const cont = this.root.querySelector('[data-buscar-resultados]');
        const info = this.root.querySelector('[data-buscar-info]');
        let tipo = '';
        let secuencia = 0;
        let espera;

        // Abierto desde una etapa de la línea de proceso: se listan los documentos de esa etapa que faltan por revisar.
        const ICONOS = { solicitud: ['fa-inbox', 1], cotizacion: ['fa-file-invoice-dollar', 2], orden: ['fa-shopping-cart', 3] };
        // En esa lista los filtros no son por tipo de documento sino por tiempo de mora (días).
        const RANGOS = [['', 'Todos', 0, Infinity], ['1', '1 a 15 días', 0, 15], ['2', '16 a 30 días', 16, 30], ['3', '31 a 60 días', 31, 60], ['4', '61 a 90 días', 61, 90], ['5', '91 a 180 días', 91, 180], ['6', 'Más de 180 días', 181, Infinity]];
        if (this.lista) {
            this.root.querySelector('[data-buscar-tipos]').innerHTML = RANGOS.map(([k, txt]) => `<button type="button" data-tipo="${k}" class="act-pill ${k === '' ? 'on' : ''}">${txt}</button>`).join('');
        }
        const desdeLista = () => {
            const l = this.lista;
            const t = q.value.trim().toLowerCase();
            const pendientes = l.items.filter((x) => !l.vistos.has(`${x.modulo}:${x.doc}`));
            const enRango = (x, [, , min, max]) => (x.dias ?? 0) >= min && (x.dias ?? 0) <= max;
            this.root.querySelectorAll('[data-buscar-tipos] [data-tipo]').forEach((b) => {
                const r = RANGOS.find((z) => z[0] === b.dataset.tipo);
                b.textContent = `${r[1]} (${pendientes.filter((x) => enRango(x, r)).length})`;
            });
            const rango = RANGOS.find((z) => z[0] === tipo) ?? RANGOS[0];
            const items = pendientes.filter((x) => enRango(x, rango)
                && (!t || `${x.ref} ${x.empresa} ${x.titulo} ${x.estado}`.toLowerCase().includes(t)));
            info.textContent = `${l.etapa}: ${items.length} documento${items.length === 1 ? '' : 's'} por revisar${l.vistos.size ? ` (${l.vistos.size} ya revisado${l.vistos.size === 1 ? '' : 's'})` : ''}.`;
            cont.innerHTML = items.length ? items.slice(0, 200).map((x, i) => {
                const [icono, card] = ICONOS[x.modulo] ?? ['fa-file', 1];
                const c = (colores[card] ?? COLORES_CARD[card]).fondo;
                const seg = x.seguimiento ? `Últ. seguimiento ${esc(x.seguimiento.fecha.slice(0, 10))}` : 'Sin seguimientos';
                return `<button type="button" data-doc="${x.modulo}:${x.doc}" class="act-doc" style="--c:${c};--k:${Math.min(i, 10)}">
                    <span class="act-doc-ico"><i class="fa-solid ${icono}"></i></span>
                    <span class="min-w-0 flex-1 text-left"><b class="block truncate text-sm">${esc(x.ref)}</b><span class="block truncate text-xs text-muted-foreground">${esc(x.empresa)}</span></span>
                    <span class="text-right text-[11px] text-muted-foreground"><span class="block font-semibold text-foreground">${esc(x.estado)}${x.dias != null ? ` · ${x.dias} d` : ''}</span>${seg}</span>
                </button>`;
            }).join('') : '<p class="col-span-full rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">No quedan documentos por revisar en esta etapa.</p>';
        };

        const buscar = async () => {
            if (this.lista) return desdeLista();
            const n = ++secuencia;
            cont.innerHTML = Array(4).fill('<div class="h-[72px] animate-pulse rounded-xl bg-muted"></div>').join('');
            try {
                const res = await fetch(`${this.root.dataset.docsUrl}?${new URLSearchParams({ q: q.value, tipo })}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                const { items } = await res.json();
                if (n !== secuencia) return;
                info.textContent = q.value.trim() ? `${items.length} resultado${items.length === 1 ? '' : 's'}` : 'Tus documentos activos más recientes.';
                cont.innerHTML = items.length ? items.map((d, i) => {
                    const c = (colores[d.card] ?? COLORES_CARD[d.card]).fondo;
                    return `<button type="button" data-doc="${d.modulo}:${d.id}" class="act-doc" style="--c:${c};--k:${Math.min(i, 10)}">
                        <span class="act-doc-ico"><i class="fa-solid ${d.icono}"></i></span>
                        <span class="min-w-0 flex-1 text-left"><b class="block truncate text-sm">${esc(d.ref)}</b><span class="block truncate text-xs text-muted-foreground">${esc(d.empresa)}</span></span>
                        <span class="text-right text-[11px] text-muted-foreground"><span class="block font-semibold text-foreground">${esc(d.estado)}</span>${d.valor ? dineroCorto(d.valor) : esc(d.moduloNombre)}</span>
                    </button>`;
                }).join('') : '<p class="col-span-full rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">No encontré documentos activos con ese criterio.</p>';
            } catch (e) {
                if (n === secuencia) cont.innerHTML = `<p class="col-span-full text-sm text-destructive">No se pudo buscar: ${esc(e.message)}</p>`;
            }
        };

        q.addEventListener('input', () => { clearTimeout(espera); espera = setTimeout(buscar, 280); });
        this.root.querySelectorAll('[data-buscar-tipos] [data-tipo]').forEach((b) => b.addEventListener('click', () => {
            tipo = b.dataset.tipo;
            this.root.querySelectorAll('[data-buscar-tipos] [data-tipo]').forEach((x) => x.classList.toggle('on', x === b));
            buscar();
        }));
        cont.addEventListener('click', (e) => {
            const d = e.target.closest('[data-doc]');
            if (d) { const [m, id] = d.dataset.doc.split(':'); this.abrirDocumento(m, id); }
        });
        buscar();
        setTimeout(() => q.focus({ preventScroll: true }), 450);
    }

    // ------------------------------------------------------------ seguimiento de un documento

    /** Clic en «Importancia del cliente»: resumen por meses en tres columnas (solicitudes, cotizaciones y órdenes). */
    iniciarResumenCliente(form) {
        const caja = form.querySelector('[data-cliente-meses]');
        if (!caja) return;
        const abrir = () => abrirResumenMeses(`${this.root.dataset.mesesUrl}?${new URLSearchParams({ modulo: form.dataset.modulo, id: form.dataset.id })}`);
        caja.addEventListener('click', abrir);
        caja.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); abrir(); } });
    }

    /** Pestañas «datos del documento» / Seguimiento; recuerda la última usada al pasar de documento. */
    iniciarPestanas(form) {
        const tabs = form.querySelectorAll('[data-tab]');
        if (!tabs.length) return;
        const poner = (k) => {
            this.tabActiva = k;
            tabs.forEach((b) => b.classList.toggle('on', b.dataset.tab === k));
            form.querySelectorAll('[data-tab-panel]').forEach((p) => { p.hidden = p.dataset.tabPanel !== k; });
        };
        tabs.forEach((b) => b.addEventListener('click', () => poner(b.dataset.tab)));
        // Al guardar se vuelve a Seguimiento para que se vean los campos requeridos.
        form.addEventListener('submit', () => poner('seguimiento'), true);
        poner(this.tabActiva ?? 'documento');
    }

    /** Anterior / Siguiente entre los documentos de la etapa desde la que se abrió el panel. */
    iniciarNavegacion(form) {
        const l = this.lista;
        const h3 = form.querySelector('[data-nav-slot]') ?? form.querySelector('h3');
        if (!l || !h3 || l.items.length < 2) return;
        const i = l.items.findIndex((x) => `${x.modulo}:${x.doc}` === `${form.dataset.modulo}:${form.dataset.id}`);
        if (i < 0) return;
        const fmt = (iso) => (iso ? new Date(`${iso.slice(0, 10)}T00:00:00`).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
        // Tarjetita al pasar el mouse: el documento al que se va a pasar.
        const tip = (x, lado) => (x ? `<span class="pointer-events-none absolute top-full ${lado}-0 z-50 mt-2 hidden w-64 max-w-[80vw] rounded-xl border border-border bg-card p-3 text-left text-xs font-normal shadow-xl group-hover:block group-focus-visible:block">
            <b class="block text-sm text-foreground">${esc(x.ref)}</b>
            <span class="block truncate text-muted-foreground">${esc(x.empresa)}</span>
            <span class="mt-1.5 flex items-center justify-between gap-2 text-muted-foreground"><span>${esc(fmt(x.fecha))}</span><span>${esc(x.estado)}</span></span>
            ${x.dias != null ? `<span class="mt-1 block font-semibold text-destructive">${x.dias.toLocaleString('es-CO')} días de mora</span>` : ''}
        </span>` : '');
        const btn = (dir, txt, icono, x) => `<button type="button" data-nav="${dir}" ${x ? '' : 'disabled'} class="group relative inline-flex items-center gap-1 rounded-md border border-border px-2.5 py-1 text-xs font-semibold hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40">${dir < 0 ? `<i class="fa-solid ${icono}"></i> ${txt}` : `${txt} <i class="fa-solid ${icono}"></i>`}${tip(x, 'right')}</button>`;
        h3.classList.add('justify-between', 'relative', 'z-30');
        h3.insertAdjacentHTML('beforeend', `<span class="ml-auto flex items-center gap-1.5 font-normal"><span class="mr-1 text-[11px] text-muted-foreground">${l.etapa} · ${i + 1} de ${l.items.length}</span>${btn(-1, 'Anterior', 'fa-chevron-left', l.items[i - 1])}${btn(1, 'Siguiente', 'fa-chevron-right', l.items[i + 1])}</span>`);
        h3.querySelectorAll('[data-nav]').forEach((b) => b.addEventListener('click', async () => {
            const x = l.items[i + +b.dataset.nav];
            if (!x) return;
            if (this.pasosConCambios().length > 0) {
                const { isConfirmed } = await Swal.fire({ title: '¿Pasar de documento sin guardar?', text: 'Tienes cambios sin guardar en este seguimiento.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Pasar sin guardar', cancelButtonText: 'Seguir aquí', confirmButtonColor: '#dc2626', reverseButtons: true });
                if (!isConfirmed) return;
            }
            this.abrirDocumento(x.modulo, x.doc);
        }));
    }

    iniciarSeguimiento() {
        const form = this.root.querySelector('form[data-step="seguimiento"]');
        this.iniciarResumenCliente(form);
        this.iniciarPestanas(form);
        this.iniciarNavegacion(form);
        const programar = form.querySelector('[data-programar]');
        const caja = form.querySelector('[data-recordatorio]');
        const fecha = form.querySelector('#act_fecha');
        const atajos = form.querySelectorAll('[data-dias]');

        const marcarAtajo = () => {
            atajos.forEach((b) => {
                const d = new Date(); d.setDate(d.getDate() + +b.dataset.dias);
                b.classList.toggle('on', fecha.value === isoLocal(d));
            });
        };
        const alternar = () => {
            caja.classList.toggle('hidden', !programar.checked);
            caja.querySelectorAll('input').forEach((i) => { i.disabled = !programar.checked; });
        };
        atajos.forEach((b) => b.addEventListener('click', () => {
            const d = new Date(); d.setDate(d.getDate() + +b.dataset.dias);
            fecha.value = isoLocal(d);
            fecha.dispatchEvent(new Event('change', { bubbles: true }));
            marcarAtajo();
        }));
        fecha.addEventListener('change', marcarAtajo);
        programar.addEventListener('change', alternar);
        atajos[0]?.click();
        alternar();

        this.root.querySelector('[data-cambiar-doc]')?.addEventListener('click', () => {
            this.abrirNueva(true);
        });

        // Marcar un recordatorio pendiente como realizado, sin recargar el panel (no pierde lo escrito).
        form.addEventListener('click', async (e) => {
            const caja2 = e.target.closest('[data-hecho-caja]');
            if (!caja2) return;
            if (e.target.closest('[data-hecho-abrir]')) {
                caja2.querySelector('[data-hecho-abrir]').classList.add('hidden');
                caja2.querySelector('[data-hecho-form]').classList.remove('hidden');
                caja2.querySelector('[data-hecho-texto]').focus();
            } else if (e.target.closest('[data-hecho-cancelar]')) {
                caja2.querySelector('[data-hecho-abrir]').classList.remove('hidden');
                caja2.querySelector('[data-hecho-form]').classList.add('hidden');
            } else if (e.target.closest('[data-hecho]')) {
                await this.marcarRealizado(caja2, e.target.closest('[data-hecho]').dataset.hecho);
            }
        });
    }

    async marcarRealizado(caja, eventoId) {
        const texto = caja.querySelector('[data-hecho-texto]').value.trim();
        if (!texto) { toast.fire({ icon: 'warning', title: 'Describe la actividad realizada' }); return; }
        const boton = caja.querySelector('[data-hecho]');
        boton.disabled = true;
        try {
            const res = await fetch(this.root.dataset.estadoUrl.replace('__ID__', eventoId), {
                method: 'PUT',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.opts.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ estado: 'REALIZADO', actividad: texto }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(json.errors ? Object.values(json.errors)[0][0] : json.message || `HTTP ${res.status}`);

            const li = caja.closest('li');
            li.classList.replace('pend', 'done');
            li.querySelector('.act-dot i').className = 'fa-solid fa-check';
            const chip = li.querySelector('.bg-amber-500\\/15');
            if (chip) { chip.className = 'rounded-full px-2 py-0.5 font-semibold bg-emerald-500/15 text-emerald-600'; chip.textContent = 'Realizado'; }
            caja.outerHTML = `<p class="mt-1.5 rounded-lg bg-emerald-500/10 px-3 py-2 text-sm"><span class="text-xs font-semibold text-emerald-600">Actividad realizada:</span> ${esc(texto)}</p>`;
            toast.fire({ icon: 'success', title: json.message });
            this.opts.onSaved?.(json);
        } catch (e) {
            boton.disabled = false;
            Swal.fire({ icon: 'error', title: 'No se pudo marcar', text: e.message });
        }
    }

    // ------------------------------------------------------------ guardar

    async guardar(form) {
        if (form.dataset.step !== 'seguimiento') return;
        const boton = form.querySelector('[data-step-save]');
        const programar = form.querySelector('[data-programar]').checked;

        this.limpiarErrores(form);
        this.cargando(boton, true);

        const body = new FormData(form);
        body.set('modulo', form.dataset.modulo);
        body.set('id', form.dataset.id);
        body.set('programar', programar ? '1' : '0');
        if (!programar) ['fecha', 'hora', 'titulo'].forEach((k) => body.delete(k));

        try {
            const res = await fetch(this.root.dataset.storeUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.opts.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            const json = await res.json().catch(() => ({}));
            if (res.status === 422) {
                this.mostrarErrores(form, json.errors ?? {});
                toast.fire({ icon: 'error', title: 'Revisa los campos marcados' });
                return;
            }
            if (!res.ok || json.ok !== true) throw new Error(json.message || `HTTP ${res.status}`);

            toast.fire({ icon: 'success', title: json.message });
            this.opts.onSaved?.(json);
            // Se recarga el panel sobre el mismo documento: queda limpio y el historial muestra lo nuevo.
            await this.abrirDocumento(form.dataset.modulo, form.dataset.id, this.fijo);
        } catch (e) {
            console.error(e);
            Swal.fire({ icon: 'error', title: 'No se pudo guardar', text: e.message });
        } finally {
            this.cargando(boton, false);
        }
    }
}
