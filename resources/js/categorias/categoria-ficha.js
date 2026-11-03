// Modal de ficha de categoría/etiqueta (crear, editar y eliminar), un solo
// formulario para todos los tipos. El comportamiento común vive en FichaModal;
// aquí: padres según el tipo elegido, selector de color y eliminar.
import Swal from 'sweetalert2';
import { FichaModal, toast } from '../panel/ficha-modal.js';

export class CategoriaFichaModal extends FichaModal {
    constructor(opts) {
        super({ etiqueta: 'la categoría', ...opts });
    }

    tituloPaso() {
        return 'los datos de la categoría';
    }

    inicializarExtras() {
        const padres = this.root.querySelector('[data-padres-json]');
        this.padres = padres ? JSON.parse(padres.textContent) : [];
        this.form = this.root.querySelector('form[data-step="general"]');

        this.form.querySelectorAll('input[name="tipo"]').forEach((r) => {
            r.addEventListener('change', () => this.alCambiarTipo());
        });

        this.colorValor = this.root.querySelector('[data-color-valor]');
        this.root.querySelectorAll('[data-color]').forEach((b) => {
            b.addEventListener('click', () => this.elegirColor(b.dataset.color));
        });
        this.root.querySelector('[data-color-picker]')?.addEventListener('input', (e) => this.elegirColor(e.target.value));
        this.form.querySelector('[name="label"]')?.addEventListener('input', () => this.pintarMuestra());
        this.pintarMuestra();

        this.root.querySelector('[data-eliminar]')?.addEventListener('click', (e) => this.eliminar(e.currentTarget));
    }

    tipoElegido() {
        const radio = this.form.querySelector('input[name="tipo"]:checked');
        return Number(radio ? radio.value : this.root.dataset.tipo);
    }

    alCambiarTipo() {
        const tipo = this.tipoElegido();
        this.root.dataset.tipo = tipo;
        this.cambiarDatosCombo('fk_parent', this.padres.filter((p) => p.tipo === tipo));
    }

    // ------------------------------------------------------------------ color

    elegirColor(hex) {
        this.colorValor.value = hex ? hex.toLowerCase() : '';
        this.colorValor.dispatchEvent(new Event('change', { bubbles: true }));
        this.pintarMuestra();
    }

    pintarMuestra() {
        const color = this.colorValor?.value ?? '';
        this.root.querySelectorAll('[data-color]').forEach((b) => {
            b.toggleAttribute('data-active', b.dataset.color.toLowerCase() === color);
        });
        const muestra = this.root.querySelector('[data-color-muestra]');
        if (muestra) muestra.style.background = color || '#9ca3af';
        const picker = this.root.querySelector('[data-color-picker]');
        if (picker && color) picker.value = color;
        const avatar = this.root.querySelector('[data-ficha-avatar]');
        if (avatar) avatar.style.background = color || 'var(--primary)';
        const label = this.root.querySelector('[data-label-muestra]');
        if (label) label.textContent = this.form.querySelector('[name="label"]')?.value.trim() || 'Vista previa';
    }

    // --------------------------------------------------------------- eliminar

    async eliminar(boton) {
        const { elementos, subcategorias, promociones } = boton.dataset;
        if (Number(promociones) > 0) {
            Swal.fire({
                icon: 'info',
                title: 'No se puede eliminar',
                text: `Está en ${promociones} promoción(es). Quítala de ahí antes de eliminarla.`,
            });
            return;
        }

        const detalle = [
            Number(elementos) > 0 ? `Se quitará de ${elementos} elemento(s) (los elementos no se borran).` : null,
            Number(subcategorias) > 0 ? `Sus ${subcategorias} subcategoría(s) subirán un nivel.` : null,
        ].filter(Boolean).join(' ');

        const { isConfirmed } = await Swal.fire({
            title: '¿Eliminar la categoría?',
            text: detalle || 'Esta acción no se puede deshacer.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Eliminar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#dc2626',
            reverseButtons: true,
        });
        if (!isConfirmed) return;

        boton.disabled = true;
        try {
            const res = await fetch(boton.dataset.eliminar, {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.opts.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json().catch(() => ({}));
            if (res.status === 422) throw new Error(Object.values(json.errors ?? {})[0]?.[0] ?? json.message);
            if (!res.ok || json.ok !== true) throw new Error(json.message || `HTTP ${res.status}`);

            toast.fire({ icon: 'success', title: json.message });
            this.opts.onSaved?.(json);
            this.cerrar(true);
        } catch (e) {
            console.error(e);
            Swal.fire({ icon: 'error', title: 'No se pudo eliminar', text: e.message });
            boton.disabled = false;
        }
    }

    // ------------------------------------------------------------------ hooks

    alDescartar() {
        this.alCambiarTipo();
        this.pintarMuestra();
    }

    actualizarCabecera({ categoria }) {
        if (!categoria) return;
        const titulo = this.root.querySelector('[data-ficha-titulo]');
        if (titulo) titulo.textContent = categoria.label;
    }
}
