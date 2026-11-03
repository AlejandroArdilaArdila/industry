// Modal de ficha del tercero (crear y editar). El comportamiento común de los
// pasos vive en FichaModal; aquí solo lo propio de terceros: dígito de
// verificación del NIT, ciudad → departamento/país, categorías y la cabecera.
import { FichaModal, normalizar } from '../panel/ficha-modal.js';
import { SelectorCategorias } from '../panel/selector-categorias.js';

// Pesos del dígito de verificación del NIT (DIAN), de derecha a izquierda.
const PESOS_DV = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

export function calcularDv(nit) {
    const digitos = String(nit ?? '').replace(/\D/g, '');
    if (!digitos || digitos.length > PESOS_DV.length) return null;
    let suma = 0;
    [...digitos].reverse().forEach((d, i) => { suma += Number(d) * PESOS_DV[i]; });
    const r = suma % 11;
    return r > 1 ? 11 - r : r;
}

export class TerceroFichaModal extends FichaModal {
    constructor(opts) {
        super({ etiqueta: 'el tercero', ...opts });
    }

    inicializarExtras() {
        this.selectoresCategorias = [...this.root.querySelectorAll('form[data-step="categorias"] [data-selector-categorias]')]
            .map((c) => new SelectorCategorias(c));

        const tipo = this.root.querySelector('[name="siret"]');
        const doc = this.root.querySelector('[name="siren"]');
        const badge = this.root.querySelector('[data-dv]');
        if (!tipo || !doc || !badge) return;

        const pintar = () => {
            const dv = tipo.value === 'NIT' ? calcularDv(doc.value) : null;
            badge.classList.toggle('hidden', dv === null);
            badge.classList.toggle('flex', dv !== null);
            badge.textContent = dv !== null ? `DV ${dv}` : '';
        };
        tipo.addEventListener('change', pintar);
        doc.addEventListener('input', pintar);
        pintar();
    }

    alCambiarCombo(nombre, ciudad) {
        if (nombre !== 'town') return;
        const zip = this.root.querySelector('[data-zip]');
        if (zip) zip.textContent = ciudad?.code ?? '—';
        if (!ciudad) return;

        // Sugiere el departamento por nombre (c_ciudades_fe y c_departements no comparten llave).
        const depto = normalizar(ciudad.depto).split(',')[0];
        const comboDepto = this.combo('fk_departement');
        const match = comboDepto?.datos.find((d) => {
            const n = normalizar(d.label);
            return n === depto || n.startsWith(depto) || depto.startsWith(n);
        });
        if (match && comboDepto.combo.value !== match.id) {
            comboDepto.combo.value = match.id;
            comboDepto.hidden.value = match.id;
        }
        const comboPais = this.combo('fk_pays');
        if (comboPais && !comboPais.hidden.value) {
            comboPais.combo.value = 70;
            comboPais.hidden.value = 70;
        }
    }

    alDescartar(form) {
        if (form.dataset.step === 'categorias') this.selectoresCategorias.forEach((s) => s.refrescar());
        const zip = form.querySelector('[data-zip]');
        if (zip) {
            const ciudad = this.combo('town')?.combo.getDataByValue(Number(form.querySelector('[name=town]')?.value));
            zip.textContent = ciudad?.code ?? zip.textContent;
        }
        form.querySelector('[name="siren"]')?.dispatchEvent(new Event('input'));
    }

    alCrear({ tercero }) {
        // Los códigos de cliente/proveedor los asigna el servidor al crear.
        if (tercero?.code_client) this.root.querySelector('[name="code_client"]').value = tercero.code_client;
        if (tercero?.code_fournisseur) this.root.querySelector('[name="code_fournisseur"]').value = tercero.code_fournisseur;
        this.root.querySelector('[data-ficha-meta]')?.classList.remove('hidden');
        const id = this.root.querySelector('[data-ficha-id]');
        if (id) id.textContent = tercero.rowid;
    }

    actualizarCabecera({ tercero }) {
        if (!tercero) return;
        const titulo = this.root.querySelector('[data-ficha-titulo]');
        if (titulo) titulo.textContent = tercero.nom;
        const tipo = this.root.querySelector('[name="siret"]')?.value ?? '';
        const doc = this.root.querySelector('[data-ficha-doc]');
        if (doc) doc.textContent = `${tipo} ${tercero.siren}`;
        this.pintarEstado(tercero.status === '1');

        const n = this.root.querySelectorAll('form[data-step="categorias"] input[type=checkbox]:checked').length;
        const sub = this.root.querySelector('[data-step-target="categorias"] [data-step-sub]');
        if (sub) sub.dataset.default = `${n} asignada${n === 1 ? '' : 's'}`;
    }
}
