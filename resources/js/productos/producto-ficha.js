// Modal de ficha de producto/servicio (crear y editar). El comportamiento
// común de los pasos vive en FichaModal; aquí: cálculo de precio sin IVA y
// margen por nivel, vista previa de códigos de barras y la cabecera. El
// buscador de categorías es SelectorCategorias.
import { FichaModal } from '../panel/ficha-modal.js';
import { SelectorCategorias } from '../panel/selector-categorias.js';

const pesos = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
const decimal = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

export class ProductoFichaModal extends FichaModal {
    constructor(opts) {
        super({ etiqueta: 'el producto', ...opts });
    }

    inicializarExtras() {
        this.inicializarPrecios();
        this.inicializarCodigos();
        this.inicializarCategorias();
    }

    // ---------------------------------------------------------------- precios

    inicializarPrecios() {
        const form = this.root.querySelector('form[data-step="precios"]');
        if (!form) return;
        form.addEventListener('input', () => this.calcularPrecios());
        form.addEventListener('change', () => this.calcularPrecios());
        this.calcularPrecios();
    }

    calcularPrecios() {
        const form = this.root.querySelector('form[data-step="precios"]');
        if (!form) return;
        const iva = Number(form.querySelector('[data-iva]')?.value || 0);
        const costo = decimal(form.querySelector('[data-costo]')?.value);
        const filas = [...form.querySelectorAll('[data-nivel]')];
        const precioBase = decimal(filas[0]?.querySelector('[data-precio]').value);

        filas.forEach((fila, i) => {
            const input = fila.querySelector('[data-precio]');
            // Un nivel vacío usa el precio 1 (así lo resuelve el POS).
            const ttc = decimal(input.value) ?? (i > 0 ? precioBase : null);
            const sinIvaEl = fila.querySelector('[data-sin-iva]');
            const margenEl = fila.querySelector('[data-margen]');

            if (ttc === null || Number.isNaN(ttc)) {
                sinIvaEl.textContent = '—';
                margenEl.textContent = '—';
                margenEl.className = 'col-span-2 text-right text-sm font-medium tabular-nums text-muted-foreground';
                return;
            }
            const sinIva = ttc / (1 + iva / 100);
            sinIvaEl.textContent = pesos.format(sinIva);
            sinIvaEl.classList.toggle('opacity-60', input.value === '');

            if (costo === null || sinIva <= 0) {
                margenEl.textContent = '—';
                margenEl.className = 'col-span-2 text-right text-sm font-medium tabular-nums text-muted-foreground';
                return;
            }
            const margen = ((sinIva - costo) / sinIva) * 100;
            margenEl.textContent = `${margen.toFixed(1)} %`;
            const color = margen < 0 ? 'text-destructive' : margen < 10 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400';
            margenEl.className = `col-span-2 text-right text-sm font-medium tabular-nums ${color}`;
        });
    }

    // ---------------------------------------------------------------- códigos

    inicializarCodigos() {
        const area = this.root.querySelector('[data-codigos]');
        if (!area) return;
        area.addEventListener('input', () => this.pintarCodigos());
        this.pintarCodigos();
    }

    pintarCodigos() {
        const area = this.root.querySelector('[data-codigos]');
        const preview = this.root.querySelector('[data-codigos-preview]');
        if (!area || !preview) return;

        const codigos = area.value.split(/[\r\n,;]+/).map((c) => c.trim()).filter(Boolean);
        const vistos = new Set();
        preview.innerHTML = '';
        if (!codigos.length) {
            preview.innerHTML = '<span class="text-sm text-muted-foreground">Sin códigos de barras.</span>';
            return;
        }
        codigos.forEach((codigo) => {
            const repetido = vistos.has(codigo);
            const largo = codigo.length > 20;
            vistos.add(codigo);
            const chip = document.createElement('span');
            chip.className = `inline-flex items-center gap-1.5 rounded-md border px-2 py-1 font-mono text-xs ${largo
                ? 'border-destructive/50 bg-destructive/10 text-destructive'
                : repetido ? 'border-amber-500/40 bg-amber-500/10 text-amber-700 line-through dark:text-amber-300' : 'border-border bg-muted/50 text-foreground'}`;
            chip.title = largo ? 'Más de 20 caracteres' : repetido ? 'Repetido: se guardará una sola vez' : '';
            chip.innerHTML = '<i class="fa-solid fa-barcode text-muted-foreground"></i>';
            chip.append(codigo);
            preview.appendChild(chip);
        });
    }

    // ------------------------------------------------------------- categorías

    inicializarCategorias() {
        const contenedor = this.root.querySelector('form[data-step="categorias"] [data-selector-categorias]');
        this.selectorCategorias = contenedor ? new SelectorCategorias(contenedor) : null;
    }

    // ------------------------------------------------------------------ hooks

    alDescartar(form) {
        if (form.dataset.step === 'precios') this.calcularPrecios();
        if (form.dataset.step === 'codigos') this.pintarCodigos();
        if (form.dataset.step === 'categorias') this.selectorCategorias?.refrescar();
    }

    alCrear({ producto }) {
        this.root.querySelector('[data-ficha-meta]')?.classList.remove('hidden');
        const id = this.root.querySelector('[data-ficha-id]');
        if (id) id.textContent = producto.rowid;
    }

    actualizarCabecera({ producto }) {
        if (!producto) return;
        const titulo = this.root.querySelector('[data-ficha-titulo]');
        if (titulo) titulo.textContent = producto.label;
        const ref = this.root.querySelector('[data-ficha-ref]');
        if (ref) ref.textContent = producto.ref;
        this.pintarEstado(producto.tosell === '1', ['En venta', 'No a la venta']);
        this.root.querySelector('[data-ficha-compra]')?.classList.toggle('hidden', producto.tobuy !== '1');
        const precio = this.root.querySelector('[data-kpi-precio]');
        if (precio) precio.textContent = pesos.format(producto.price_ttc);
        const iva = this.root.querySelector('[data-kpi-iva]');
        if (iva) iva.textContent = `${producto.tva_tx} %`;

        const contar = (paso, selector, singular, plural) => {
            const n = paso === 'codigos'
                ? new Set(this.root.querySelector('[data-codigos]').value.split(/[\r\n,;]+/).map((c) => c.trim()).filter(Boolean)).size
                : this.root.querySelectorAll(selector).length;
            const sub = this.root.querySelector(`[data-step-target="${paso}"] [data-step-sub]`);
            if (sub) sub.dataset.default = `${n} ${n === 1 ? singular : plural}`;
        };
        contar('codigos', null, 'registrado', 'registrados');
        contar('categorias', 'input[name="categorias[]"]:checked', 'asignada', 'asignadas');
    }
}
