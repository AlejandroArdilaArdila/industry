// Selector de categorías para las fichas (productos, terceros…). Puede haber
// más de mil categorías: se pintan aquí desde el JSON (una fila con checkbox
// por categoría) y solo se muestran las que coinciden con la búsqueda, con un
// tope para que la lista siga siendo usable.
//
// Contrato del HTML, todo dentro de un contenedor [data-selector-categorias]:
//   script[data-categorias-json]   {categorias: [{id,label,color,parent}], seleccionadas: [id], disabled, name}
//   [data-categorias-lista]        donde van las filas con checkbox (name = json.name, "categorias[]" por defecto)
//   [data-categorias-seleccionadas] chips de las marcadas
//   input[data-filtro-categorias]  buscador
//   [data-categorias-info]         "Mostrando x de y"
import { normalizar } from './ficha-modal.js';

const TOPE = 120;

export class SelectorCategorias {
    constructor(contenedor) {
        this.contenedor = contenedor;
        this.lista = contenedor.querySelector('[data-categorias-lista]');
        const fuente = contenedor.querySelector('[data-categorias-json]');
        if (!fuente || !this.lista) return;

        const {
            categorias, seleccionadas, disabled, name = 'categorias[]',
        } = JSON.parse(fuente.textContent);
        this.name = name;
        const porId = new Map(categorias.map((c) => [c.id, c]));
        const ruta = (c) => {
            const partes = [];
            for (let p = porId.get(c.parent), n = 0; p && n < 5; p = porId.get(p.parent), n++) partes.unshift(p.label);
            return partes.join(' › ');
        };
        const elegidas = new Set(seleccionadas);

        this.filas = categorias.map((c) => {
            const fila = document.createElement('label');
            fila.className = 'flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm transition hover:bg-muted has-[:checked]:bg-primary/10';
            const padre = ruta(c);
            fila.dataset.texto = normalizar(`${c.label} ${padre}`);
            fila.innerHTML = `
                <input type="checkbox" value="${c.id}" class="h-4 w-4 flex-shrink-0 accent-[var(--primary)]" ${elegidas.has(c.id) ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                <span class="h-2.5 w-2.5 flex-shrink-0 rounded-full" style="background:${c.color ?? '#9ca3af'}"></span>
                <span class="min-w-0"><span class="block truncate text-foreground"></span><span class="block truncate text-xs text-muted-foreground"></span></span>`;
            fila.querySelector('input').name = name;
            const [nombre, sub] = fila.querySelectorAll('.min-w-0 > span');
            nombre.textContent = c.label;
            sub.textContent = padre;
            if (!padre) sub.remove();
            fila.categoria = c;
            this.lista.appendChild(fila);
            return fila;
        });

        contenedor.querySelector('[data-filtro-categorias]')?.addEventListener('input', () => this.filtrar());
        this.lista.addEventListener('change', () => this.refrescar());
        this.refrescar();
    }

    /** Vuelve a pintar chips y lista (tras marcar, descartar cambios, etc.). */
    refrescar() {
        if (!this.filas) return;
        this.pintarSeleccionadas();
        this.filtrar();
    }

    marcadas() {
        return (this.filas ?? []).filter((f) => f.querySelector('input').checked);
    }

    filtrar() {
        const q = normalizar(this.contenedor.querySelector('[data-filtro-categorias]')?.value);
        let coinciden = 0;
        let visibles = 0;
        // Sin búsqueda, primero las asignadas para que se vean de entrada. Se ordena
        // con CSS (order) y no moviendo nodos: el orden del DOM es el del FormData
        // y cambiarlo marcaría el paso como modificado.
        const asignadasPrimero = (f) => (!q && f.querySelector('input').checked ? 0 : 1);
        [...this.filas].sort((a, b) => asignadasPrimero(a) - asignadasPrimero(b)).forEach((fila) => {
            const ok = !q || fila.dataset.texto.includes(q);
            if (ok) coinciden++;
            const mostrar = ok && visibles < TOPE;
            if (mostrar) visibles++;
            fila.classList.toggle('hidden', !mostrar);
            fila.style.order = asignadasPrimero(fila) === 0 ? '-1' : '';
        });
        const info = this.contenedor.querySelector('[data-categorias-info]');
        if (info) {
            info.textContent = coinciden > visibles
                ? `Mostrando ${visibles} de ${coinciden}. Escribe para afinar la búsqueda.`
                : `${coinciden} categoría${coinciden === 1 ? '' : 's'}.`;
        }
    }

    pintarSeleccionadas() {
        const caja = this.contenedor.querySelector('[data-categorias-seleccionadas]');
        if (!caja) return;
        caja.innerHTML = '';
        const marcadas = this.marcadas();
        if (!marcadas.length) {
            caja.innerHTML = '<span class="px-1 py-1.5 text-sm text-muted-foreground">Ninguna. Busca y marca las categorías abajo.</span>';
            return;
        }
        marcadas.forEach((fila) => {
            const { label, color } = fila.categoria;
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'inline-flex items-center gap-2 rounded-full border border-primary/40 bg-primary/10 px-3 py-1 text-sm text-foreground transition hover:border-destructive/50 hover:bg-destructive/10';
            chip.title = 'Quitar';
            chip.innerHTML = `<span class="h-2.5 w-2.5 rounded-full" style="background:${color ?? '#9ca3af'}"></span><span></span><i class="fa-solid fa-xmark text-xs text-muted-foreground"></i>`;
            chip.children[1].textContent = label;
            chip.addEventListener('click', () => {
                const input = fila.querySelector('input');
                if (input.disabled) return;
                input.checked = false;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            caja.appendChild(chip);
        });
    }
}
