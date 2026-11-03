import { DataManager } from '@syncfusion/ej2-data';
import { ContextMenu } from '@syncfusion/ej2-navigations';
import { getFilterTemplate, templateDateRangePickerSF } from './filterTemplates.js';
import './locale-es.js';

/** Formatea un número como moneda/decimal es-CO, negativos en rojo. */
export function formatNumberCO(valor) {
    const num = Number(valor) || 0;
    const formatted = new Intl.NumberFormat('es-CO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Math.abs(num));
    const color = num < 0 ? '#e53935' : 'inherit';
    const texto = num < 0 ? `-${formatted}` : formatted;
    const weight = num < 0 ? '600' : 'normal';
    return `<span style="color:${color}; font-weight:${weight}">${texto}</span>`;
}

/** Activa tooltips Bootstrap si el plugin está cargado; no-op en caso contrario. */
export function activarTooltips() {
    if (window.$ && typeof window.$.fn?.tooltip === 'function') {
        window.$('[data-bs-toggle="tooltip"]').tooltip();
    }
}

/**
 * Crea/actualiza columnas dinámicas a partir de los extrafields que manda
 * el backend en la primera fila (obj.rowData[0].extrafields). Port de
 * globalsFilters.js#createExtraFieldsColumns.
 */
export function createExtraFieldsColumns(extrafieldsMetadata, idGrid, filterCtx) {
    if (!extrafieldsMetadata) return;
    const gridInstance = document.getElementById(idGrid).ej2_instances[0];

    Object.keys(extrafieldsMetadata).forEach((key) => {
        const meta = extrafieldsMetadata[key];
        const fieldName = `ef_${key}`;
        const columnIndex = gridInstance.columns.findIndex((col) => col.field === fieldName);

        if (meta.enabled === '0' || meta.enabled === 0) {
            if (columnIndex !== -1) {
                gridInstance.columns.splice(columnIndex, 1);
            }
            return;
        }

        const columnConf = {
            field: fieldName,
            headerText: meta.label,
            width: 150,
            allowFiltering: true,
            textAlign: 'Left',
            uid: fieldName,
            autoFit: true,
            minWidth: 50,
        };

        switch (meta.type) {
            case 'boolean':
                columnConf.type = 'boolean';
                columnConf.displayAsCheckBox = true;
                columnConf.textAlign = 'Center';
                columnConf.width = 100;
                break;
            case 'date':
            case 'datetime':
                columnConf.type = 'date';
                columnConf.format = { type: 'date', format: 'dd/MM/yyyy' };
                columnConf.textAlign = 'Right';
                columnConf.width = 130;
                break;
            case 'double':
            case 'int':
                columnConf.type = 'number';
                columnConf.format = meta.type === 'int' ? 'N0' : 'N2';
                columnConf.textAlign = 'Right';
                columnConf.width = 120;
                break;
            case 'price':
            case 'pricecy':
                columnConf.type = 'number';
                columnConf.format = { format: 'c2', locale: 'es' };
                columnConf.textAlign = 'Right';
                columnConf.width = 150;
                break;
            case 'select':
            case 'sellist':
            case 'text':
            case 'varchar':
            default:
                columnConf.type = 'string';
                columnConf.textAlign = 'Left';
                break;
        }

        if (filterCtx && columnConf.allowFiltering && !columnConf.filterBarTemplate) {
            const t = (columnConf.type || 'string').toLowerCase();
            if (t === 'string') {
                columnConf.filterBarTemplate = getFilterTemplate(fieldName, 'string', filterCtx);
            } else if (t === 'number') {
                columnConf.filterBarTemplate = getFilterTemplate(fieldName, 'number', filterCtx);
            } else if (t === 'date') {
                columnConf.filterBarTemplate = templateDateRangePickerSF({
                    id: `${fieldName}_auto_drp`,
                    fieldName,
                    idGrid,
                    format: 'dd/MM/yyyy',
                });
            }
        }

        if (columnIndex === -1) {
            gridInstance.columns.splice(gridInstance.columns.length - 1, 0, columnConf);
        } else {
            gridInstance.columns[columnIndex].headerText = columnConf.headerText;
            gridInstance.columns[columnIndex].width = columnConf.width;
            if (columnConf.template) {
                gridInstance.columns[columnIndex].template = columnConf.template;
            }
            if (columnConf.filterBarTemplate) {
                gridInstance.columns[columnIndex].filterBarTemplate = columnConf.filterBarTemplate;
            }
        }
    });

    gridInstance.refreshColumns();

    if (gridInstance.enablePersistence) {
        const persistData = gridInstance.getPersistData();
        window.localStorage.setItem(gridInstance.getModuleName() + gridInstance.element.id, persistData);
    }
}

/**
 * Mejoras de UX para cualquier grid Syncfusion: caché de queries repetidas,
 * menú contextual de columnas (fijar/ocultar/auto-ajustar), panel de
 * visibilidad de columnas y persistencia de layout en localStorage. Port
 * de globalsFilters.js#AdvancedGridUX — llamar `AdvancedGridUX.enable(grid)`
 * en el `dataBound` del grid.
 */
export const AdvancedGridUX = (function iife() {
    let menu = null;
    let ctx = {};
    const queryCache = {};
    const CACHE_TTL = 60000;

    (function installDMPatch() {
        if (DataManager.prototype.__advCachePatch) return;
        DataManager.prototype.__advCachePatch = true;
        const origEQ = DataManager.prototype.executeQuery;
        DataManager.prototype.executeQuery = function executeQuery(query) {
            const url = this.dataSource && this.dataSource.url;
            if (url && query) {
                const key = `${url}||${qfp(query)}`;
                const entry = queryCache[key];
                if (entry && Date.now() - entry.ts < CACHE_TTL) {
                    return Promise.resolve(entry.result);
                }
            }
            return Promise.resolve(origEQ.apply(this, arguments)).then((result) => {
                if (url && query) {
                    queryCache[`${url}||${qfp(query)}`] = { result, ts: Date.now() };
                }
                return result;
            });
        };
    }());

    function qfp(query) {
        try {
            return JSON.stringify(query, (k, v) => (typeof v === 'function' ? undefined : v));
        } catch (e) {
            return '';
        }
    }

    function invalidateCache(grid) {
        const url = grid.dataSource && grid.dataSource.dataSource && grid.dataSource.dataSource.url;
        if (!url) return;
        Object.keys(queryCache).forEach((k) => {
            if (k.indexOf(`${url}||`) === 0) delete queryCache[k];
        });
    }

    function patchGrid(grid) {
        if (grid.__advPatched) return;
        grid.__advPatched = true;

        const origGFC = grid.getFooterContent.bind(grid);
        grid.getFooterContent = function getFooterContent() {
            const el = origGFC();
            if (!el) {
                const d = document.createElement('div');
                d.appendChild(document.createElement('div'));
                return d;
            }
            return el;
        };

        const origGFCT = grid.getFooterContentTable.bind(grid);
        grid.getFooterContentTable = function getFooterContentTable() {
            try {
                return origGFCT();
            } catch (e) {
                return null;
            }
        };
    }

    function setVisible(grid, col, visible) {
        try {
            if (visible) {
                grid.showColumns(col.headerText, 'headerText');
            } else {
                grid.hideColumns(col.headerText, 'headerText');
            }
            col.visible = visible;
        } catch (e) {
            col.visible = visible;
            try { grid.refreshColumns(); } catch (_) { /* noop */ }
        }
    }

    function openColumnPanel(grid) {
        const existing = document.getElementById('advColumnPanel');
        if (existing) { existing.remove(); return; }
        const panel = document.createElement('div');
        panel.id = 'advColumnPanel';
        panel.style.cssText = [
            'position:fixed;top:70px;right:20px;z-index:99999;',
            'background:#fff;border:1px solid #ddd;border-radius:8px;',
            'box-shadow:0 4px 24px rgba(0,0,0,.18);width:280px;',
            'font-size:13px;display:flex;flex-direction:column;',
            'max-height:80vh;overflow:hidden;font-family:sans-serif;',
        ].join('');

        const hdr = document.createElement('div');
        hdr.style.cssText = [
            'padding:10px 14px;background:#f5f5f5;',
            'border-bottom:1px solid #e0e0e0;border-radius:8px 8px 0 0;',
            'display:flex;justify-content:space-between;align-items:center;',
            'font-weight:bold;color:#333;',
        ].join('');
        hdr.innerHTML = '<span><span class="e-icons e-column-chooser" style="margin-right:6px;font-size:14px;vertical-align:middle;"></span>Visibilidad de columnas</span>';
        const closeBtn = document.createElement('button');
        closeBtn.innerHTML = '&times;';
        closeBtn.style.cssText = 'background:none;border:none;font-size:20px;cursor:pointer;color:#888;padding:0;line-height:1;';
        closeBtn.onclick = () => panel.remove();
        hdr.appendChild(closeBtn);
        panel.appendChild(hdr);

        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Buscar columna...';
        searchInput.style.cssText = [
            'margin:8px 10px 4px;padding:5px 8px;box-sizing:border-box;',
            'width:calc(100% - 20px);border:1px solid #ddd;',
            'border-radius:4px;font-size:12px;outline:none;',
        ].join('');
        panel.appendChild(searchInput);

        const list = document.createElement('div');
        list.style.cssText = 'overflow-y:auto;flex:1;padding-bottom:4px;';

        function rebuild(filter) {
            list.innerHTML = '';
            let hits = 0;
            grid.columns
                .filter((c) => c.field && c.field !== 'rowid')
                .forEach((col) => {
                    const label = col.headerText || col.field;
                    if (filter && label.toLowerCase().indexOf(filter.toLowerCase()) === -1) return;
                    hits += 1;

                    const vis = col.visible !== false;
                    const row = document.createElement('div');
                    row.style.cssText = [
                        'display:flex;align-items:center;padding:7px 14px;cursor:pointer;',
                        'gap:9px;border-bottom:1px solid #f5f5f5;',
                        `color:${vis ? '#333' : '#aaa'};`,
                    ].join('');
                    row.onmouseenter = () => { row.style.background = '#f0f8ff'; };
                    row.onmouseleave = () => { row.style.background = ''; };

                    const eye = document.createElement('span');
                    eye.style.minWidth = '16px';
                    eye.innerHTML = vis
                        ? '<i class="fas fa-eye" style="color:#0891b2;font-size:13px;"></i>'
                        : '<i class="fas fa-eye-slash" style="color:#ccc;font-size:13px;"></i>';

                    const nm = document.createElement('span');
                    nm.textContent = label;
                    nm.style.flex = '1';

                    const bdg = document.createElement('span');
                    bdg.style.cssText = 'display:flex;gap:3px;align-items:center;';
                    if (col.freeze === 'Left') {
                        bdg.innerHTML = '<span title="Fijada izquierda" style="font-size:10px;background:#e3f2fd;color:#1565c0;border-radius:3px;padding:1px 5px;white-space:nowrap;">◀ Izq</span>';
                    } else if (col.freeze === 'Right') {
                        bdg.innerHTML = '<span title="Fijada derecha" style="font-size:10px;background:#fff3e0;color:#e65100;border-radius:3px;padding:1px 5px;white-space:nowrap;">Der ▶</span>';
                    }
                    const sb = document.createElement('span');
                    sb.style.cssText = `font-size:10px;border-radius:3px;padding:1px 6px;white-space:nowrap;${vis ? 'background:#e8f5e9;color:#2e7d32;' : 'background:#fafafa;color:#bbb;border:1px solid #eee;'}`;
                    sb.textContent = vis ? 'visible' : 'oculta';
                    bdg.appendChild(sb);
                    row.appendChild(eye);
                    row.appendChild(nm);
                    row.appendChild(bdg);

                    row.onclick = () => {
                        setVisible(grid, col, !vis);
                        setTimeout(() => rebuild(searchInput.value), 80);
                        saveLayout(grid);
                    };

                    list.appendChild(row);
                });

            if (!hits) {
                const empty = document.createElement('div');
                empty.style.cssText = 'text-align:center;color:#bbb;padding:16px;font-size:12px;';
                empty.textContent = 'Sin resultados';
                list.appendChild(empty);
            }
        }
        rebuild('');
        searchInput.addEventListener('input', () => rebuild(searchInput.value));
        panel.appendChild(list);

        const foot = document.createElement('div');
        foot.style.cssText = 'padding:8px 14px;border-top:1px solid #eee;display:flex;justify-content:flex-end;';
        const showAll = document.createElement('button');
        showAll.textContent = 'Mostrar todas';
        showAll.style.cssText = 'background:#0891b2;color:#fff;border:none;border-radius:4px;padding:4px 12px;font-size:12px;cursor:pointer;';
        showAll.onmouseenter = () => { showAll.style.background = '#0e7490'; };
        showAll.onmouseleave = () => { showAll.style.background = '#0891b2'; };
        showAll.onclick = () => {
            grid.columns
                .filter((c) => c.field && c.field !== 'rowid' && c.visible === false)
                .forEach((col) => setVisible(grid, col, true));
            setTimeout(() => rebuild(searchInput.value), 80);
            saveLayout(grid);
        };
        foot.appendChild(showAll);
        panel.appendChild(foot);
        document.body.appendChild(panel);

        setTimeout(() => {
            function onOutside(e) {
                if (!panel.contains(e.target)) {
                    panel.remove();
                    document.removeEventListener('click', onOutside);
                }
            }
            document.addEventListener('click', onOutside);
        }, 100);
    }

    function ensureMenu() {
        if (menu) return;
        const el = document.createElement('ul');
        el.id = 'advGridCtxMenu';
        document.body.appendChild(el);

        menu = new ContextMenu({
            items: [
                { text: 'Fijar a la izquierda', id: 'freezeLeft', iconCss: 'e-icons e-pin' },
                { text: 'Fijar a la derecha', id: 'freezeRight', iconCss: 'e-icons e-pin' },
                { text: 'Quitar fijación', id: 'freezeNone', iconCss: 'e-icons e-close' },
                { separator: true },
                { text: 'Auto ajustar ancho', id: 'autoSizeOn', iconCss: 'e-icons e-resize' },
                { text: 'Ancho fijo', id: 'autoSizeOff', iconCss: 'e-icons e-resize' },
                { text: 'Ocultar columna', id: 'hideColumn', iconCss: 'e-icons e-hide' },
                { separator: true },
                { text: 'Gestionar columnas', id: 'columnPanel', iconCss: 'e-icons e-column-chooser' },
                { text: 'Restablecer columnas', id: 'resetLayout', iconCss: 'e-icons e-reset' },
            ],
            beforeOpen: (args) => {
                if (!ctx.grid) { args.cancel = true; return; }
                const col = ctx.grid.getColumnByField(ctx.field);
                if (!col) { args.cancel = true; return; }

                menu.items[0].iconCss = 'e-icons e-pin'; menu.items[0].text = 'Fijar a la izquierda';
                menu.items[1].iconCss = 'e-icons e-pin'; menu.items[1].text = 'Fijar a la derecha';
                menu.items[2].iconCss = 'e-icons e-close'; menu.items[2].text = 'Quitar fijación';
                if (col.freeze === 'Left') { menu.items[0].iconCss = 'e-icons e-check'; menu.items[0].text += '  ✓'; } else if (col.freeze === 'Right') { menu.items[1].iconCss = 'e-icons e-check'; menu.items[1].text += '  ✓'; } else { menu.items[2].iconCss = 'e-icons e-check'; menu.items[2].text += '  ✓'; }

                menu.items[4].iconCss = 'e-icons e-resize'; menu.items[4].text = 'Auto ajustar ancho';
                menu.items[5].iconCss = 'e-icons e-resize'; menu.items[5].text = 'Ancho fijo';
                if (col.__autoFit) { menu.items[4].iconCss = 'e-icons e-check'; menu.items[4].text += '  ✓'; } else { menu.items[5].iconCss = 'e-icons e-check'; menu.items[5].text += '  ✓'; }

                menu.dataBind();
            },
            select: (args) => {
                const { grid } = ctx;
                const col = grid.getColumnByField(ctx.field);
                switch (args.item.id) {
                    case 'freezeLeft':
                        col.freeze = 'Left';
                        saveLayout(grid);
                        try { grid.refreshColumns(); } catch (e) { /* noop */ }
                        break;
                    case 'freezeRight':
                        col.freeze = 'Right';
                        saveLayout(grid);
                        try { grid.refreshColumns(); } catch (e) { /* noop */ }
                        break;
                    case 'freezeNone':
                        col.freeze = undefined;
                        saveLayout(grid);
                        try { grid.refreshColumns(); } catch (e) { /* noop */ }
                        break;
                    case 'autoSizeOn':
                        col.__autoFit = true;
                        saveAutoFit(grid);
                        applyAutoFit(grid);
                        break;
                    case 'autoSizeOff':
                        col.__autoFit = false;
                        saveAutoFit(grid);
                        break;
                    case 'hideColumn':
                        setVisible(grid, col, false);
                        saveLayout(grid);
                        break;
                    case 'columnPanel':
                        openColumnPanel(grid);
                        break;
                    case 'resetLayout':
                        if (window.confirm('¿Restablecer las columnas a la configuración por defecto?')) {
                            resetLayout(grid);
                        }
                        break;
                    default:
                        break;
                }
            },
            onClose: () => { ctx = {}; },
        });
        menu.appendTo(el);
    }

    function attachMenu(grid) {
        ensureMenu();
        if (grid.__advMenu) return;
        grid.__advMenu = true;
        grid.element.addEventListener('contextmenu', (e) => {
            const header = e.target.closest('.e-headercell');
            if (!header) return;
            const hDiv = header.querySelector('.e-headercelldiv');
            if (!hDiv) return;
            const uid = hDiv.dataset.mappinguid;
            if (!uid) return;
            const col = grid.getColumnByUid(uid);
            if (!col || !col.field) return;
            e.preventDefault();
            ctx = { grid, field: col.field };
            menu.open(e.pageY, e.pageX);
        });
    }

    function key(grid) { return `gridLayout_${grid.element.id}`; }

    function saveAutoFit(grid) {
        const state = {};
        grid.columns.forEach((c) => { if (c.field) state[c.field] = !!c.__autoFit; });
        try { localStorage.setItem(`${key(grid)}_autofit`, JSON.stringify(state)); } catch (e) { /* noop */ }
    }

    function loadAutoFit(grid) {
        try {
            const data = localStorage.getItem(`${key(grid)}_autofit`);
            if (!data) return;
            const state = JSON.parse(data);
            grid.columns.forEach((c) => { if (c.field && state[c.field] !== undefined) c.__autoFit = state[c.field]; });
        } catch (e) { /* noop */ }
    }

    function applyAutoFit(grid) {
        const fields = grid.columns.filter((c) => c.field && c.__autoFit).map((c) => c.field);
        if (!fields.length) return;
        try { grid.enablePersistence = false; } catch (e) { /* noop */ }
        grid.autoFitColumns(fields);
        setTimeout(() => { try { grid.enablePersistence = true; } catch (e) { /* noop */ } }, 300);
    }

    function saveLayout(grid) {
        try { localStorage.setItem(key(grid), grid.getPersistData()); } catch (e) { /* noop */ }
    }

    function loadLayout(grid) {
        try {
            const data = localStorage.getItem(key(grid));
            if (data) grid.setProperties(JSON.parse(data));
        } catch (e) { /* noop */ }
    }

    function resetLayout(grid) {
        localStorage.removeItem(key(grid));
        localStorage.removeItem(`${key(grid)}_autofit`);
        localStorage.removeItem(`grid${grid.element.id}`);
        try { grid.enablePersistence = false; } catch (e) { /* noop */ }
        window.location.reload();
    }

    function exportLayout(grid) {
        const blob = new Blob([grid.getPersistData()], { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `${grid.element.id}_layout.json`;
        a.click();
    }

    function importLayout(grid, file) {
        const r = new FileReader();
        r.onload = (e) => {
            try {
                grid.setProperties(JSON.parse(e.target.result));
                saveLayout(grid);
            } catch (_) { /* noop */ }
        };
        r.readAsText(file);
    }

    function enable(grid) {
        patchGrid(grid);
        attachMenu(grid);

        if (grid.__advLayout) return;
        grid.__advLayout = true;

        loadAutoFit(grid);
        applyAutoFit(grid);

        const origActionBegin = grid.actionBegin;
        grid.actionBegin = function actionBegin(args) {
            const dataOps = ['filtering', 'sorting', 'paging', 'searching', 'clearFilter'];
            if (args && dataOps.indexOf(args.requestType) !== -1) {
                invalidateCache(grid);
            }
            if (typeof origActionBegin === 'function') origActionBegin.call(this, args);
        };
        grid.actionComplete = (args) => {
            saveLayout(grid);
            const dataActions = ['filtering', 'sorting', 'paging', 'searching'];
            if (dataActions.indexOf(args && args.requestType) !== -1) {
                applyAutoFit(grid);
            }
        };
    }

    return {
        enable,
        patchGrid,
        openColumnPanel,
        saveLayout,
        loadLayout,
        resetLayout,
        exportLayout,
        importLayout,
        invalidateCache,
    };
}());
