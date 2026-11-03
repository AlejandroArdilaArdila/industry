// Ficha de solicitud (crear y editar) en panel lateral. El comportamiento común de los pasos vive en
// FichaModal; aquí: contactos relacionados según el cliente, líneas de producto con buscador del
// catálogo, editor del detalle/correo y cierre/reapertura desde el paso Estado.
import Swal from 'sweetalert2';
import { FichaModal, serializar, toast } from '../panel/ficha-modal.js';
import { abrirResumenMeses } from '../panel/cliente-resumen.js';

const TITULOS = {
    general: 'Solicitud', productos: 'Productos', detalle: 'Detalle y correo', estado: 'Estado',
};

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/** Deja solo HTML de formato: sin scripts, estilos en línea, manejadores de eventos ni enlaces javascript:. */
function limpiarHtml(html) {
    const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
    const raiz = doc.body.firstElementChild;
    raiz.querySelectorAll('script,style,iframe,object,embed,link,meta,form,input,button,img,svg').forEach((n) => n.remove());
    raiz.querySelectorAll('*').forEach((n) => {
        [...n.attributes].forEach((a) => {
            const nombre = a.name.toLowerCase();
            const permitido = nombre === 'href' && /^(https?:|mailto:|tel:)/i.test(a.value.trim());
            if (!permitido) n.removeAttribute(a.name);
        });
        if (n.tagName === 'A') { n.setAttribute('target', '_blank'); n.setAttribute('rel', 'noopener noreferrer'); }
    });
    return raiz.innerHTML;
}

export class SolicitudFichaModal extends FichaModal {
    constructor(opts) {
        super({ etiqueta: 'la solicitud', ...opts });
    }

    tituloPaso(paso) {
        return TITULOS[paso] ?? paso;
    }

    /** Elegir el motivo de cierre no es un cambio de datos que haya que guardar. */
    pasosConCambios() {
        return super.pasosConCambios().filter((p) => p !== 'estado');
    }

    /**
     * Tras guardar los productos, las filas nuevas reciben su id: si no, guardar otra vez las duplicaría.
     * El id no es un cambio del usuario, así que se vuelve a tomar la referencia de «sin cambios».
     */
    actualizarCabecera(json) {
        super.actualizarCabecera(json);
        if (!Array.isArray(json.lineas)) return;
        const form = this.root.querySelector('form[data-step="productos"]');
        const filas = [...form.querySelectorAll('[data-linea]')];
        filas.forEach((f, i) => {
            if (json.lineas[i]) {
                f.querySelector('input[name$="[id]"]').value = json.lineas[i];
                f.dataset.key = json.lineas[i];
            }
        });
        this.snapshots.set('productos', serializar(form));
    }

    inicializarExtras() {
        this.inicializarRelacionados();
        this.inicializarImportancia();
        this.inicializarLineas();
        this.inicializarDetalle();
        this.inicializarEstado();
    }

    // ------------------------------------------------------------ contactos

    alCambiarCombo(nombre, item) {
        if (nombre !== 'fk_soc') return;
        const id = item?.id;
        this.pintarImportancia(id);
        if (!id) {
            this.cambiarDatosCombo('contacto_id', []);
            this.pintarRelacionados([], []);
            return;
        }
        fetch(`${this.root.dataset.contactosUrl}?soc=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then(({ items }) => {
                if (this.combo('fk_soc').hidden.value !== String(id)) return; // eligió otro cliente mientras cargaba
                this.cambiarDatosCombo('contacto_id', items);
                this.pintarRelacionados(items, []);
            })
            .catch(() => toast.fire({ icon: 'error', title: 'No se pudieron cargar los contactos' }));
    }

    /** Tarjeta «Importancia del cliente» (la misma del seguimiento); clic = resumen por meses. */
    inicializarImportancia() {
        const caja = this.root.querySelector('[data-cliente-importancia]');
        if (!caja) return;
        caja.addEventListener('click', (e) => {
            const soc = e.currentTarget.dataset.soc;
            if (soc && e.target.closest('[data-abrir-meses]')) abrirResumenMeses(`${this.root.dataset.clienteMesesUrl}?soc=${encodeURIComponent(soc)}`);
        });
        this.pintarImportancia(this.root.querySelector('input[name="fk_soc"]')?.value);
    }

    async pintarImportancia(soc) {
        const caja = this.root.querySelector('[data-cliente-importancia]');
        if (!caja) return;
        caja.dataset.soc = soc || '';
        if (!soc) {
            caja.innerHTML = '<p class="text-xs text-muted-foreground">Elige el cliente para ver su importancia histórica.</p>';
            return;
        }
        caja.innerHTML = '<p class="text-xs text-muted-foreground"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Cargando…</p>';
        try {
            const r = await fetch(`${this.root.dataset.clienteResumenUrl}?soc=${encodeURIComponent(soc)}`, { headers: { Accept: 'application/json' } });
            if (!r.ok) throw new Error();
            const { resumen: c, ultimas = [] } = await r.json();
            if (caja.dataset.soc !== String(soc) || !c) return; // eligió otro cliente mientras cargaba
            const nf = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 });
            const barras = [['Solicitudes', c.solicitudes, '#3b82f6'], ['Cotizaciones', c.cotizaciones, '#f59e0b'], ['Órdenes', c.ordenes, '#10b981']];
            const max = Math.max(1, ...barras.map((b) => b[1]));
            caja.innerHTML = `<div data-abrir-meses role="button" tabindex="0" title="Ver el resumen por meses" class="cursor-pointer rounded-lg transition hover:bg-muted/60">
                <p class="mb-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">Importancia del cliente <span class="normal-case">(histórico)</span> <i class="fa-solid fa-chart-column float-right text-primary"></i></p>
                <div class="space-y-1">${barras.map(([n, v, color]) => `<div class="flex items-center gap-2 text-xs"><span class="w-[78px] shrink-0 text-muted-foreground">${n}</span><span class="h-2 flex-1 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full" style="width:${Math.max(v ? 6 : 0, Math.round((v / max) * 100))}%;background:${color}"></span></span><b class="w-8 shrink-0 text-right">${nf.format(v)}</b></div>`).join('')}</div>
                <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                    <div><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Cotizado</p><p class="font-semibold">$ ${nf.format(c.montoCotizado)}</p></div>
                    <div><p class="text-[10px] uppercase tracking-wide text-muted-foreground">En órdenes</p><p class="font-semibold text-emerald-600">$ ${nf.format(c.montoOrdenes)}</p></div>
                </div></div>
                ${ultimas.length ? `<div class="mt-4 border-t border-border pt-3">
                    <p class="mb-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">Últimas solicitudes del cliente</p>
                    <ul class="space-y-2">${ultimas.map((u) => `<li class="text-xs"><div class="flex items-center justify-between gap-2"><b class="text-foreground">${esc(u.ref)}</b><span class="text-muted-foreground">${esc(String(u.fecha ?? '').slice(0, 10))}</span></div><p class="truncate text-muted-foreground" title="${esc(u.asunto)}">${esc(u.asunto) || '—'}</p><span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">${esc(u.estado)}</span></li>`).join('')}</ul>
                </div>` : ''}`;
        } catch {
            if (caja.dataset.soc === String(soc)) caja.innerHTML = '<p class="text-xs text-muted-foreground">No se pudo cargar la importancia del cliente.</p>';
        }
    }

    inicializarRelacionados() {
        const cont = this.root.querySelector('[data-relacionados]');
        if (!cont) return;
        this.pintarRelacionados(this.catalogos.contacto_id ?? [], JSON.parse(cont.dataset.seleccion || '[]'));
    }

    pintarRelacionados(contactos, seleccion) {
        const cont = this.root.querySelector('[data-relacionados]');
        if (!cont) return;
        const inactivo = 'disabled' in cont.dataset;
        if (!contactos.length) {
            cont.innerHTML = '<p class="px-1 py-1 text-xs text-muted-foreground">Elige el cliente para ver sus contactos.</p>';
        } else {
            cont.innerHTML = contactos.map((c) => `
                <label class="flex cursor-pointer items-center gap-2 rounded-md px-1.5 py-1 hover:bg-muted/60">
                    <input type="checkbox" name="relacionados[]" value="${c.id}" class="h-4 w-4 accent-[var(--primary,#2563eb)]"
                        ${seleccion.includes(c.id) ? 'checked' : ''} ${inactivo ? 'disabled' : ''}>
                    <span class="truncate">${esc(c.label)}</span>
                </label>`).join('');
        }
        // Al cambiar de cliente cambia el conjunto de campos: se avisa para recalcular "cambios sin guardar".
        cont.closest('form')?.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // ------------------------------------------------------------ productos

    inicializarLineas() {
        const form = this.root.querySelector('form[data-step="productos"]');
        if (!form) return;
        const lista = form.querySelector('[data-lineas]');
        const vacio = form.querySelector('[data-sin-lineas]');
        const plantilla = form.querySelector('[data-plantilla-linea]');
        let siguiente = lista.querySelectorAll('[data-linea]').length;
        const refrescar = () => {
            vacio.classList.toggle('hidden', lista.querySelector('[data-linea]') !== null);
            form.dispatchEvent(new Event('change', { bubbles: true }));
        };

        /** Agrega una fila; si el producto del catálogo ya está, suma uno a su cantidad. */
        const agregar = ({ id = '', ref = '', label = '' }) => {
            if (id) {
                const existente = [...lista.querySelectorAll('[data-linea]')].find((f) => f.querySelector('[data-producto-id]').value === String(id));
                if (existente) {
                    const qty = existente.querySelector('input[name$="[qty]"]');
                    qty.value = String((parseFloat(qty.value) || 0) + 1);
                    toast.fire({ icon: 'info', title: 'Ya estaba en la lista: se sumó una unidad' });
                    return refrescar();
                }
            }
            const cont = document.createElement('template');
            cont.innerHTML = plantilla.content.querySelector('tbody').innerHTML.replaceAll('__I__', String(siguiente++));
            const fila = cont.content.querySelector('tr');
            fila.querySelector('[data-producto-id]').value = id;
            fila.querySelector('textarea').value = label;
            if (id) {
                const badge = fila.querySelector('[data-linea-ref]');
                // Si el nombre del producto es la misma referencia (sin guiones bajos ni tildes), repetirla en la etiqueta sobra.
                const norm = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]/gi, '').toLowerCase();
                badge.textContent = norm(ref) === norm(label) ? 'Catálogo' : ref;
                badge.className = 'rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-semibold text-primary';
            }
            lista.appendChild(fila);
            if (form.dataset.iaVisible) fila.querySelectorAll('[data-col-ia]').forEach((n) => n.classList.remove('hidden'));
            refrescar();
            if (id) this.analizarFilas?.([fila]); // producto del catálogo: historial al instante y la IA solo si nunca se ha comprado
        };

        lista.addEventListener('click', (e) => {
            if (!e.target.closest('[data-quitar-linea]')) return;
            e.target.closest('[data-linea]').remove();
            refrescar();
        });

        this.buscadorProductos(form, agregar);
        this.inicializarAnalisisIa(form, lista);
    }

    /** Buscador del catálogo: elegir una opción agrega la fila; Enter agrega lo escrito como producto sin catálogo. */
    buscadorProductos(form, agregar) {
        const input = form.querySelector('[data-producto-buscar]');
        if (!input) return;
        let espera;
        let secuencia = 0;
        const cerrar = () => document.querySelectorAll('[data-producto-menu]').forEach((m) => m.remove());

        input.addEventListener('input', () => {
            clearTimeout(espera);
            const q = input.value.trim();
            cerrar();
            if (q.length < 2) return;
            const mia = ++secuencia;
            espera = setTimeout(async () => {
                try {
                    const r = await fetch(`${this.root.dataset.productosUrl}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
                    const { items } = await r.json();
                    if (mia !== secuencia) return;
                    cerrar();
                    const menu = document.createElement('div');
                    menu.dataset.productoMenu = '';
                    menu.dueno = input;
                    menu.className = 'mt-1 max-h-60 overflow-y-auto rounded-lg border border-border p-1 shadow-xl';
                    // Estilos en línea: este menú se crea por JS y no depende de que Tailwind genere sus clases.
                    const caja = input.getBoundingClientRect();
                    Object.assign(menu.style, { position: 'fixed', top: `${caja.bottom + 4}px`, left: `${caja.left}px`, width: `${caja.width}px`, margin: '0', zIndex: '2147483000', background: '#fff', color: '#171717' });
                    menu.innerHTML = (items.length
                        ? items.map((p) => `<button type="button" data-id="${p.id}" data-ref="${esc(p.ref)}" data-label="${esc(p.label)}" class="block w-full truncate rounded-md px-2 py-1.5 text-left text-sm hover:bg-neutral-100"><strong>${esc(p.ref)}</strong> — ${esc(p.label)}</button>`).join('')
                        : '<p class="px-2 py-1.5 text-xs text-neutral-500">Sin resultados en el catálogo.</p>')
                        + `<button type="button" data-libre class="mt-1 block w-full truncate rounded-md border-t border-neutral-200 px-2 py-1.5 text-left text-xs text-neutral-600 hover:bg-neutral-100"><i class="fa-solid fa-plus mr-1"></i>Agregar «${esc(q)}» sin catálogo</button>`;
                    document.body.appendChild(menu);
                } catch { /* sin conexión: se puede agregar como texto libre con Enter */ }
            }, 250);
        });

        /** Opciones del menú abierto (catálogo y "sin catálogo") y la que está resaltada con el teclado. */
        const opciones = () => [...document.querySelectorAll('[data-producto-menu] button')];
        const resaltar = (lista, i) => {
            lista.forEach((o, n) => { o.style.background = n === i ? '#e5e7eb' : ''; o.dataset.activa = n === i ? '1' : ''; });
            lista[i]?.scrollIntoView({ block: 'nearest' });
        };

        input.addEventListener('keydown', (e) => {
            const lista = opciones();
            const actual = lista.findIndex((o) => o.dataset.activa === '1');
            if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && lista.length) {
                e.preventDefault();
                const paso = e.key === 'ArrowDown' ? 1 : -1;
                resaltar(lista, actual < 0 ? (paso > 0 ? 0 : lista.length - 1) : (actual + paso + lista.length) % lista.length);
                return;
            }
            if (e.key === 'Escape' && lista.length) {
                e.stopPropagation(); // cierra solo el menú, no la ficha
                return cerrar();
            }
            if (e.key !== 'Enter') return;
            e.preventDefault();
            if (actual >= 0) return elegir(lista[actual]);
            const q = input.value.trim();
            if (!q) return;
            agregar({ label: q });
            input.value = '';
            cerrar();
        });

        const elegir = (op) => {
            if ('libre' in op.dataset) agregar({ label: input.value.trim() });
            else agregar({ id: op.dataset.id, ref: op.dataset.ref, label: op.dataset.label });
            input.value = '';
            cerrar();
            input.focus();
        };

        window.addEventListener('scroll', cerrar, true);
        window.addEventListener('resize', cerrar);
        document.addEventListener('mousedown', (e) => {
            const op = e.target.closest('[data-producto-menu] button');
            if (!op || op.closest('[data-producto-menu]').dueno !== input) return; // el menú es de otra ficha abierta antes
            e.preventDefault();
            elegir(op);
        });
        input.addEventListener('blur', () => setTimeout(cerrar, 150));
    }

    /** "Analizar con IA": una columna a la derecha de la tabla con el análisis de cada fila. */
    inicializarAnalisisIa(form, lista) {
        const boton = form.querySelector('[data-analizar-ia]');
        if (!boton) return;
        const fecha = (f) => (f ? esc(String(f).slice(0, 10)) : '—');
        const nf = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 });
        const usd = (v) => (v === null || v === undefined ? '—' : `USD ${Number(v).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);
        const cop = (v) => (v === null || v === undefined ? '—' : `COP ${nf.format(v)}`);
        const fuerte = (t) => `<strong class="text-foreground">${esc(t)}</strong>`;
        const grupo = (titulo, filas) => (filas.length
            ? `<div class="mt-2"><p class="mb-0.5 font-semibold text-foreground">${titulo}</p><ul class="space-y-0.5 text-muted-foreground">${filas.map((f) => `<li>${f}</li>`).join('')}</ul></div>`
            : '');

        /** Columna «Precio promedio»: promedio en USD y COP; clic abre el listado de todos los precios. */
        const celdaPrecio = (r) => {
            const h = r.historial;
            if (!h || !h.total_precios) return '<p class="text-muted-foreground">Sin precios previos.</p>';
            const { usd: u, cop: c } = h.resumen;
            return `<button type="button" data-ver-precios title="Ver todos los precios" class="block w-full rounded-lg p-1 text-left transition hover:bg-muted">
                <span class="block text-[10px] uppercase tracking-wide text-muted-foreground">Promedio</span>
                <span class="block text-sm font-semibold text-foreground">${usd(u?.promedio)}</span>
                <span class="block text-sm font-semibold text-emerald-600">${cop(c?.promedio)}</span>
                ${u ? `<span class="mt-1 block text-muted-foreground">${usd(u.min)} – ${usd(u.max).replace('USD ', '')}</span>` : ''}
                <span class="mt-1 block font-medium text-primary">Ver ${h.total_precios} precios <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i></span>
            </button>`;
        };

        const celda = (r) => {
            const h = r.historial;
            const recientes = (h?.precios ?? []).slice(0, 3).map((p) => `${fuerte(p.tercero ?? '—')} · ${p.tipo} ${esc(p.ref)} · ${fecha(p.fecha)} · ${p.moneda ? `${p.moneda} ${nf.format(p.valor)}` : '—'}`);
            const compra = (r.donde_conseguir ?? []).map((o) => {
                const nombre = o.url ? `<a href="${esc(o.url)}" target="_blank" rel="noopener noreferrer" class="font-semibold text-primary hover:underline">${esc(o.proveedor)}</a>` : fuerte(o.proveedor);
                return `${nombre}${o.pais ? ` · ${esc(o.pais)}` : ''}${o.precio ? ` · <span class="font-medium text-foreground">${esc(o.precio)}</span>` : ''}${o.nota ? `<br><span class="opacity-80">${esc(o.nota)}</span>` : ''}`;
            });
            const estadoIa = {
                cargando: '<p class="mt-2 text-muted-foreground"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Buscando dónde comprarlo…</p>',
                omitida: '<p class="mt-2 text-muted-foreground">Ya tiene historial. Usa «Analizar con IA» si quieres opciones de compra.</p>',
                error: `<p class="mt-2 text-amber-700">${esc(r.iaError ?? 'La IA no respondió.')}</p>`,
            }[r.iaEstado] ?? '';
            return `${r.resumen ? `<p class="text-foreground">${esc(r.resumen)}</p>` : ''}
                ${h?.total_precios ? `<p class="mt-2 text-muted-foreground">${h.cotizaciones} cotización(es) · ${h.ordenes} orden(es) de compra</p>` : `<p class="mt-2 text-muted-foreground">${h ? 'Sin cotizaciones ni órdenes previas de este producto.' : 'Fuera del catálogo: no hay historial interno.'}</p>`}
                ${grupo('Lo más reciente', recientes)}
                ${grupo('Dónde comprarlo (IA · precios por verificar)', compra)}
                ${estadoIa}
                ${r.guardado ? `<p class="mt-2 text-[10px] text-muted-foreground"><i class="fa-regular fa-floppy-disk mr-1"></i>Análisis guardado el ${esc(r.guardado)}</p>` : ''}`;
        };

        /** Enlace que abre el documento en otra pestaña (en el sistema anterior, donde está su pantalla). */
        const enlace = (url, texto) => (url
            ? `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer" title="Abrir en otra pestaña" style="color:var(--primary,#2563eb);font-weight:600">${esc(texto)} <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:9px"></i></a>`
            : esc(texto));

        /** Listado completo de precios del producto, en su moneda y convertido a USD y COP. */
        const verPrecios = (fila) => {
            const h = fila.datosIa?.historial;
            if (!h) return;
            const dlg = document.createElement('dialog');
            dlg.className = 'cm-dlg';
            const nombre = fila.querySelector('textarea').value.trim().slice(0, 80);
            const { usd: u, cop: c } = h.resumen;
            const resumen = (t, x, f) => `<div style="flex:1;min-width:9rem"><small style="display:block;opacity:.7">${t}</small><b>${x ? f(x.promedio) : '—'}</b>${x ? `<small style="display:block;opacity:.7">${f(x.min)} – ${f(x.max).replace(/^(USD|COP) /, '')} · ${x.n} precios</small>` : ''}</div>`;
            dlg.innerHTML = `<div class="cm-cab"><h3>Precios del producto <small>${esc(nombre)}</small></h3><button type="button" data-cerrar aria-label="Cerrar">&times;</button></div>
                <div class="cm-cuerpo">
                    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:1rem">${resumen('Promedio en dólares', u, usd)}${resumen('Promedio en pesos', c, cop)}</div>
                    <div style="overflow:auto;max-height:60vh"><table style="width:100%;font-size:12px;border-collapse:collapse">
                        <thead><tr style="text-align:left">${['Fecha', 'Tipo', 'Documento', 'Tercero', 'Importación', 'Cant.', 'Precio original', 'USD', 'COP'].map((t) => `<th style="padding:8px;position:sticky;top:0;background:#fff;border-bottom:2px solid var(--border,#e5e7eb);color:#374151;font-weight:600">${t}</th>`).join('')}</tr></thead>
                        <tbody>${h.precios.map((p, n) => `<tr style="background:${n % 2 ? 'color-mix(in srgb, var(--primary, #2563eb) 6%, #fff)' : '#fff'}">
                            <td style="padding:7px 8px">${fecha(p.fecha)}</td><td style="padding:7px 8px">${p.tipo}</td>
                            <td style="padding:7px 8px">${enlace(p.url, p.ref)}</td>
                            <td style="padding:7px 8px">${esc(p.tercero ?? '—')}</td><td style="padding:7px 8px">${p.importacion ? enlace(p.url_importacion, p.importacion) : ''}</td><td style="padding:7px 8px;text-align:right">${nf.format(p.cantidad)}</td>
                            <td style="padding:7px 8px;text-align:right">${p.moneda ? `${p.moneda} ${Number(p.valor).toLocaleString('es-CO', { maximumFractionDigits: 2 })}` : '—'}</td>
                            <td style="padding:7px 8px;text-align:right">${p.usd === null ? '—' : Number(p.usd).toLocaleString('es-CO', { minimumFractionDigits: 2 })}</td>
                            <td style="padding:7px 8px;text-align:right">${p.cop === null ? '—' : nf.format(p.cop)}</td></tr>`).join('')}</tbody>
                    </table></div>
                    <p style="margin-top:.75rem;font-size:11px;opacity:.7">Cotizaciones y órdenes de compra del cliente; cada documento se abre en otra pestaña. Las conversiones usan la TRM de cada cotización${h.total_precios > h.precios.length ? `; se muestran los ${h.precios.length} más recientes de ${h.total_precios}` : ''}.</p>
                </div>`;
            dlg.addEventListener('click', (e) => { if (e.target === dlg || e.target.closest('[data-cerrar]')) dlg.close(); });
            dlg.addEventListener('close', () => dlg.remove());
            document.body.appendChild(dlg);
            dlg.showModal();
        };
        lista.addEventListener('click', (e) => {
            if (e.target.closest('[data-ver-precios]')) verPrecios(e.target.closest('[data-linea]'));
        });

        const mostrar = (fila) => {
            fila.querySelector('[data-analisis]').innerHTML = celda(fila.datosIa);
            fila.querySelector('[data-precio]').innerHTML = celdaPrecio(fila.datosIa);
        };
        /** Lo que dijo la IA viaja con el formulario; el historial de precios lo vuelve a consultar el servidor al guardar. */
        const guardarEnFormulario = (fila) => {
            const d = fila.datosIa;
            fila.querySelector('[data-analisis-json]').value = JSON.stringify({ resumen: d.resumen, donde_conseguir: d.donde_conseguir ?? [] });
            form.dispatchEvent(new Event('change', { bubbles: true }));
        };
        const datosLinea = (f) => ({
            key: f.dataset.key,
            fk_product: f.querySelector('[data-producto-id]').value || null,
            description: f.querySelector('textarea').value.trim() || 'Producto sin descripción',
            model: f.querySelector('input[name$="[model]"]').value || null,
            qty: f.querySelector('input[name$="[qty]"]').value || null,
        });
        const pedir = async (lineas, ia) => {
            const res = await fetch(this.root.dataset.inteligenciaUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.opts.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ lineas, ia }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(json.message || `HTTP ${res.status}`);
            return json;
        };

        /**
         * Dos tiempos por fila: primero el historial interno (inmediato, sin IA) y después la IA para completar
         * dónde comprarlo. Sin `forzarIa`, la IA solo se consulta si el producto nunca se ha comprado.
         */
        this.analizarFilas = async (filas, forzarIa = false) => {
            form.dataset.iaVisible = '1';
            form.querySelectorAll('[data-col-ia]').forEach((n) => n.classList.remove('hidden'));
            form.querySelector('[data-col-producto]').style.width = '30%'; // con el análisis a la vista, el producto cede espacio
            filas.forEach((f) => {
                f.datosIa = { historial: null, resumen: null, donde_conseguir: [], iaEstado: null };
                f.querySelector('[data-analisis]').innerHTML = '<p class="text-muted-foreground"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Consultando historial…</p>';
                f.querySelector('[data-precio]').innerHTML = '';
            });
            const lineas = filas.map(datosLinea);
            try {
                const { items } = await pedir(lineas, false);
                const porClave = Object.fromEntries(items.map((r) => [r.key, r]));
                filas.forEach((f) => { f.datosIa.historial = porClave[f.dataset.key]?.historial ?? null; });
            } catch (e) {
                filas.forEach((f) => { f.datosIa.iaEstado = 'error'; f.datosIa.iaError = e.message; mostrar(f); });
                return;
            }

            const conIa = filas.filter((f) => {
                const conHistorial = (f.datosIa.historial?.total_precios ?? 0) > 0;
                const pide = forzarIa || !conHistorial;
                f.datosIa.iaEstado = pide ? 'cargando' : 'omitida';
                return pide;
            });
            filas.forEach((f) => { mostrar(f); guardarEnFormulario(f); });
            if (!conIa.length) return;

            try {
                const json = await pedir(conIa.map(datosLinea), true);
                const porClave = Object.fromEntries(json.items.map((r) => [r.key, r]));
                conIa.forEach((f) => {
                    const r = porClave[f.dataset.key];
                    Object.assign(f.datosIa, { resumen: r?.resumen ?? null, donde_conseguir: r?.donde_conseguir ?? [], iaEstado: json.ia_error ? 'error' : null, iaError: json.ia_error });
                    mostrar(f);
                    guardarEnFormulario(f);
                });
            } catch (e) {
                conIa.forEach((f) => { Object.assign(f.datosIa, { iaEstado: 'error', iaError: e.message }); mostrar(f); });
            }
        };

        // Análisis ya guardado de las líneas existentes: se muestra tal cual, sin volver a consultar nada.
        const guardadas = [...lista.querySelectorAll('[data-analisis-guardado]')];
        if (guardadas.length) {
            form.dataset.iaVisible = '1';
            form.querySelectorAll('[data-col-ia]').forEach((n) => n.classList.remove('hidden'));
            form.querySelector('[data-col-producto]').style.width = '30%';
            guardadas.forEach((f) => {
                try {
                    const d = JSON.parse(f.dataset.analisisGuardado);
                    f.datosIa = { historial: d.historial ?? null, resumen: d.resumen ?? null, donde_conseguir: d.donde_conseguir ?? [], iaEstado: null, guardado: d.guardado_en };
                    mostrar(f);
                } catch { /* análisis ilegible: se ignora */ }
            });
        }

        boton.addEventListener('click', () => {
            const filas = [...lista.querySelectorAll('[data-linea]')];
            if (!filas.length) return toast.fire({ icon: 'info', title: 'Agrega al menos un producto' });
            if (filas.length > 8) return toast.fire({ icon: 'info', title: 'Analiza máximo 8 productos a la vez' });
            this.analizarFilas(filas, true);
        });
    }

    // ------------------------------------------------------------ detalle y correo

    inicializarDetalle() {
        const form = this.root.querySelector('form[data-step="detalle"]');
        if (!form) return;
        const editor = form.querySelector('[data-editor]');
        const hidden = form.querySelector('input[name="detalle"]');
        const plantillas = this.catalogos.plantillas ?? [];
        const sync = () => { hidden.value = editor.innerHTML.trim() === '<br>' ? '' : editor.innerHTML; };

        // El contenido guardado se limpia antes de mostrarse; la plantilla por defecto solo se precarga al crear.
        editor.innerHTML = limpiarHtml(hidden.value);
        const sel = form.querySelector('[data-plantilla]');
        if (this.esNuevo && sel?.value) editor.innerHTML = limpiarHtml(plantillas.find((t) => String(t.id) === sel.value)?.content ?? '');
        sync();
        editor.addEventListener('input', () => { sync(); form.dispatchEvent(new Event('change', { bubbles: true })); });

        form.querySelectorAll('[data-editor-cmd]').forEach((b) => b.addEventListener('mousedown', (e) => {
            e.preventDefault();
            const cmd = b.dataset.editorCmd;
            let valor = null;
            if (cmd === 'createLink') {
                valor = window.prompt('Dirección del enlace (https://…)');
                if (!valor || !/^(https?:|mailto:|tel:)/i.test(valor.trim())) return;
            }
            editor.focus();
            document.execCommand(cmd, false, valor);
            editor.dispatchEvent(new Event('input'));
        }));
        // Pegar siempre como texto plano: evita arrastrar estilos y HTML ajeno al mensaje.
        editor.addEventListener('paste', (e) => {
            e.preventDefault();
            document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
        });

        sel?.addEventListener('change', () => {
            const t = plantillas.find((x) => String(x.id) === sel.value);
            if (!t) return;
            editor.innerHTML = limpiarHtml(t.content);
            if (t.topic) form.querySelector('[name="asunto_correo"]').value = t.topic;
            editor.dispatchEvent(new Event('input'));
        });

        // «Redactar con IA»: pide el correo según lo que pidió el cliente, tomando como base la plantilla o el texto que ya haya.
        const botonIa = form.querySelector('[data-redactar-ia]');
        botonIa?.addEventListener('click', async () => {
            const url = this.root.dataset.updateUrl ? `${this.root.dataset.updateUrl}/correo-ia` : null;
            if (!url) return;
            const lineas = [...this.root.querySelectorAll('form[data-step="productos"] [data-linea]')].map((f) => ({
                description: f.querySelector('textarea').value.trim(),
                model: f.querySelector('input[name$="[model]"]').value || null,
                qty: f.querySelector('input[name$="[qty]"]').value || null,
            })).filter((l) => l.description);
            const original = botonIa.innerHTML;
            botonIa.disabled = true;
            botonIa.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-xs"></i> Redactando…';
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.opts.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ lineas, base: editor.innerText.trim(), instrucciones: form.querySelector('[data-ia-instrucciones]').value }),
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(json.errors ? Object.values(json.errors)[0][0] : (json.message || `HTTP ${res.status}`));
                editor.innerHTML = limpiarHtml(json.cuerpo);
                if (json.asunto) form.querySelector('[name="asunto_correo"]').value = json.asunto;
                editor.dispatchEvent(new Event('input'));
                toast.fire({ icon: 'success', title: 'Correo redactado: revísalo antes de enviarlo' });
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'No se pudo redactar el correo', text: e.message });
            } finally {
                botonIa.disabled = false;
                botonIa.innerHTML = original;
            }
        });

        const interruptor = form.querySelector('[data-enviar-correo]');
        interruptor?.addEventListener('change', () => form.querySelector('[data-correo]').classList.toggle('hidden', !interruptor.checked));
    }

    // ------------------------------------------------------------ estado

    inicializarEstado() {
        this.root.querySelector('[data-cerrar]')?.addEventListener('click', async () => {
            const motivo = this.combo('motivo')?.hidden.value;
            if (!motivo) {
                Swal.fire({ icon: 'info', title: 'Elige el motivo de cierre' });
                return;
            }
            const { isConfirmed } = await Swal.fire({
                title: '¿Cerrar la solicitud?', text: 'Podrás reabrirla después.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Cerrar solicitud', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626', reverseButtons: true,
            });
            if (isConfirmed) this.accionEstado(this.root.dataset.cerrarUrl, { motivo });
        });
        this.root.querySelector('[data-reabrir]')?.addEventListener('click', async () => {
            const { isConfirmed } = await Swal.fire({
                title: '¿Reabrir la solicitud?', icon: 'question', showCancelButton: true, confirmButtonText: 'Reabrir', cancelButtonText: 'Cancelar', reverseButtons: true,
            });
            if (isConfirmed) this.accionEstado(this.root.dataset.reabrirUrl, {});
        });
    }

    async accionEstado(url, datos) {
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.opts.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(datos),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(json.message || `HTTP ${res.status}`);
            toast.fire({ icon: 'success', title: json.message });
            this.opts.onSaved?.(json);
            // Se recarga la ficha para reflejar el nuevo estado, los permisos de edición y el historial.
            await this.cargar(this.ultimaUrl);
            this.irA('estado');
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'No se pudo completar', text: e.message });
        }
    }

    abrir(id) {
        this.ultimaUrl = this.opts.fichaUrl.replace('__ID__', id);
        return super.abrir(id);
    }
}
