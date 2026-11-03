import '../../css/color-multiselect.css';

/**
 * Dropdown multiselect con chips de color (usado para filtros de categoría).
 * Port de libsIntegros/multiSelectIntegros.js.
 */
export class ColorMultiSelectDropdown {
    /**
     * @param {HTMLElement|string} container Contenedor o selector CSS
     * @param {Array} items [{ color, fk_parent, label, rowid }]
     * @param {Object} options
     *   - visibleCount: cuántos chips mostrar en el resumen (por defecto 3)
     *   - multiple: bool (true por defecto)
     *   - initialSelected: array de rowid iniciales
     *   - onChange: function(selectedIds: array)
     */
    constructor(container, items, options = {}) {
        this.container = typeof container === 'string' ? document.querySelector(container) : container;
        if (!this.container) {
            throw new Error('ColorMultiSelectDropdown: contenedor no encontrado');
        }

        this.items = items || [];
        this.multiple = options.multiple !== false;
        this.visibleCount = options.visibleCount || 3;
        this.selectedIds = new Set(options.initialSelected || []);
        this.onChange = typeof options.onChange === 'function' ? options.onChange : null;

        this.isOpen = false;
        this.filterText = '';

        this._render();
        this._attachGlobalClick();
        this._attachReposition();
    }

    _render() {
        this.container.classList.add('cmd-container');
        this.container.innerHTML = '';

        this.summary = document.createElement('div');
        this.summary.className = 'cmd-summary';
        this.summary.addEventListener('click', () => this.toggle());

        this.summaryCount = document.createElement('span');
        this.summaryCount.className = 'cmd-summary-count';

        this.summaryChips = document.createElement('div');
        this.summaryChips.className = 'cmd-summary-chips';

        const caret = document.createElement('span');
        caret.className = 'cmd-caret';
        caret.innerHTML = '&#9662;';

        this.summary.appendChild(this.summaryCount);
        this.summary.appendChild(this.summaryChips);
        this.summary.appendChild(caret);

        this.panel = document.createElement('div');
        this.panel.className = 'cmd-panel';
        this.panel.style.display = 'none';

        const searchBar = document.createElement('div');
        searchBar.className = 'cmd-search';

        this.searchInput = document.createElement('input');
        this.searchInput.type = 'text';
        this.searchInput.placeholder = 'Buscar...';
        this.searchInput.className = 'cmd-search-input';
        this.searchInput.addEventListener('input', () => this._onSearchChange());

        const searchIcon = document.createElement('span');
        searchIcon.className = 'cmd-search-icon';
        searchIcon.innerHTML = '&#128269;';

        searchBar.appendChild(searchIcon);
        searchBar.appendChild(this.searchInput);

        this.listEl = document.createElement('div');
        this.listEl.className = 'cmd-list';

        this.items.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'cmd-item-row';
            row.dataset.id = item.rowid;
            row.dataset.label = (item.label || '').toLowerCase();

            const colorBox = document.createElement('span');
            colorBox.className = 'cmd-color-box';
            let { color } = item;
            color = color || 'cccccc';
            if (!color.startsWith('#')) color = `#${color}`;
            colorBox.style.backgroundColor = color;

            const labelSpan = document.createElement('span');
            labelSpan.className = 'cmd-item-label';
            labelSpan.textContent = item.label;

            const check = document.createElement('span');
            check.className = 'cmd-check';

            row.appendChild(colorBox);
            row.appendChild(labelSpan);
            row.appendChild(check);
            row.addEventListener('click', () => this._onItemClick(item.rowid));

            this.listEl.appendChild(row);
        });

        const footer = document.createElement('div');
        footer.className = 'cmd-footer';

        const btnCerrar = document.createElement('button');
        btnCerrar.type = 'button';
        btnCerrar.className = 'cmd-btn-close';
        btnCerrar.textContent = 'Cerrar';
        btnCerrar.addEventListener('click', () => this.close());
        footer.appendChild(btnCerrar);

        this.panel.appendChild(searchBar);
        this.panel.appendChild(this.listEl);
        this.panel.appendChild(footer);

        this.container.appendChild(this.summary);
        // El panel vive en <body> con position:fixed: dentro de la toolbar de
        // Syncfusion (overflow:hidden, 48px de alto) quedaría recortado.
        document.body.appendChild(this.panel);

        this._refreshAll();
    }

    _attachGlobalClick() {
        document.addEventListener('click', (e) => {
            if (!this.isOpen) return;
            if (!this.container.contains(e.target) && !this.panel.contains(e.target)) {
                this.close();
            }
        });
    }

    _attachReposition() {
        const reposition = () => { if (this.isOpen) this._positionPanel(); };
        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, true);
    }

    _positionPanel() {
        const rect = this.summary.getBoundingClientRect();
        const width = Math.max(rect.width, 260);
        const left = Math.min(rect.left, window.innerWidth - width - 8);
        const espacioAbajo = window.innerHeight - rect.bottom - 8;
        const abrirArriba = espacioAbajo < 200 && rect.top > espacioAbajo;

        this.panel.style.left = `${Math.max(8, left)}px`;
        this.panel.style.width = `${width}px`;
        this.panel.style.maxHeight = `${Math.min(300, abrirArriba ? rect.top - 12 : espacioAbajo - 4)}px`;
        if (abrirArriba) {
            this.panel.style.top = '';
            this.panel.style.bottom = `${window.innerHeight - rect.top + 4}px`;
        } else {
            this.panel.style.bottom = '';
            this.panel.style.top = `${rect.bottom + 4}px`;
        }
    }

    _onSearchChange() {
        this.filterText = this.searchInput.value.trim().toLowerCase();
        this._applyFilter();
    }

    _applyFilter() {
        const rows = this.listEl.querySelectorAll('.cmd-item-row');
        rows.forEach((row) => {
            const label = row.dataset.label || '';
            row.style.display = !this.filterText || label.includes(this.filterText) ? 'flex' : 'none';
        });
    }

    _onItemClick(id) {
        const isSelected = this.selectedIds.has(id);

        if (this.multiple) {
            if (isSelected) {
                this.selectedIds.delete(id);
            } else {
                this.selectedIds.add(id);
            }
        } else if (isSelected) {
            this.selectedIds.clear();
        } else {
            this.selectedIds.clear();
            this.selectedIds.add(id);
        }

        this._refreshAll();
        if (this.onChange) this.onChange(this.getSelectedIds());
    }

    _refreshAll() {
        this._refreshSummary();
        this._refreshPanelSelection();
        this._applyFilter();
    }

    _refreshSummary() {
        const selectedItems = this.items.filter((i) => this.selectedIds.has(i.rowid));
        const count = selectedItems.length;

        if (count === 0) {
            this.summaryCount.textContent = '0 seleccionados';
        } else if (count === 1) {
            this.summaryCount.textContent = '1 seleccionado';
        } else {
            this.summaryCount.textContent = `${count} seleccionados`;
        }

        this.summaryChips.innerHTML = '';
        const toShow = selectedItems.slice(0, this.visibleCount);

        toShow.forEach((item) => {
            const chip = document.createElement('span');
            chip.className = 'cmd-chip';
            let { color } = item;
            color = color || 'cccccc';
            if (!color.startsWith('#')) color = `#${color}`;
            chip.style.setProperty('--cmd-chip-bg', color);
            chip.textContent = item.label;
            this.summaryChips.appendChild(chip);
        });

        const remaining = count - toShow.length;
        if (remaining > 0) {
            const more = document.createElement('span');
            more.className = 'cmd-chip cmd-chip-more';
            more.textContent = `+${remaining} más`;
            this.summaryChips.appendChild(more);
        }
    }

    _refreshPanelSelection() {
        const rows = this.panel.querySelectorAll('.cmd-item-row');
        rows.forEach((row) => {
            const id = Number(row.dataset.id);
            row.classList.toggle('cmd-item-selected', this.selectedIds.has(id));
        });
    }

    open() {
        this.panel.style.display = 'flex';
        this._positionPanel();
        this.isOpen = true;
        if (this.searchInput) {
            setTimeout(() => {
                this.searchInput.focus();
                this.searchInput.select();
            }, 0);
        }
    }

    close() {
        this.panel.style.display = 'none';
        this.isOpen = false;
    }

    toggle() {
        if (this.isOpen) { this.close(); } else { this.open(); }
    }

    getSelectedIds() {
        return Array.from(this.selectedIds);
    }

    setSelectedIds(idsArray) {
        this.selectedIds = new Set(idsArray || []);
        this._refreshAll();
        if (this.onChange) this.onChange(this.getSelectedIds());
    }

    clear() {
        this.setSelectedIds([]);
    }
}
