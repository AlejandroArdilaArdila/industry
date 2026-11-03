import '../../css/syncfusion-theme.css';
import {
    Grid, Toolbar, ColumnChooser, Filter, Page, Sort, Resize, Reorder,
} from '@syncfusion/ej2-grids';
import { DataManager, Query, UrlAdaptor } from '@syncfusion/ej2-data';
import Swal from 'sweetalert2';
import { quitarCongeladasEnMovil, toolbarBtnNuevo, toolbarTitulo } from '../grid/toolbarButtons.js';
import { autoFilterTemplates } from '../grid/filterTemplates.js';
import { AdvancedGridUX, activarTooltips } from '../grid/gridUx.js';
import { ColorMultiSelectDropdown } from '../grid/colorMultiSelect.js';
import { CategoriaFichaModal } from './categoria-ficha.js';
import {
    downloadFile, ensureCsvModal, showCsvModal, hideCsvModal, limpiarFiltroPersistente, cleanColumns,
} from '../grid/exportHelpers.js';

const IDGRID = 'GridCategorias';

const escapar = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

function getToolbarTemplate(config) {
    return [
        { template: toolbarTitulo('fa-tags', 'Etiquetas'), align: 'Left' },
        {
            template: `
                <div style="width: 350px;">
                    <div style="display:flex;align-items:center;">
                        <i class="fas fa-layer-group" style="font-size:16px; margin-right: 8px; color:#6c6aa8;"></i>
                        <div id="tiposDropdown" style="flex:1;"></div>
                    </div>
                </div>
            `,
            align: 'Left',
        },
        ...(config.puedeCrear ? [{ template: toolbarBtnNuevo('btnNuevaCategoria', 'Nueva'), align: 'Right' }] : []),
        { template: toolbarBtn('btnDescargarExcel', 'fa-file-excel', '#198754', 'Exportar a Excel'), align: 'Right' },
        { template: toolbarBtn('btnModalCsv', 'fa-file-csv', '#0891b2', 'Exportar a CSV'), align: 'Right' },
        { template: toolbarBtn('reloadGrid', 'fa-sync-alt', '#b45309', 'Recargar'), align: 'Right' },
        { template: toolbarBtn('resetGrid', 'fa-recycle', '#dc2626', 'Restablecer filtros'), align: 'Right' },
    ];
}

function toolbarBtn(id, icon, color, title) {
    const style = `display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;`
        + `border:1px solid ${color};border-radius:6px;background:#fff;color:${color};cursor:pointer;`;
    return `<button type="button" id="${id}" title="${title}" style="${style}"><i class="fa-solid ${icon}"></i></button>`;
}

document.addEventListener('DOMContentLoaded', () => {
    const config = window.CATEGORIAS_CONFIG;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const tiposPorId = new Map(config.tipos.map((t) => [t.rowid, t]));

    let currentTipos = [];
    let updateGridTimer = null;
    let lastQuerySignature = null;

    Grid.Inject(Toolbar, ColumnChooser, Filter, Page, Sort, Resize, Reorder);

    class CustomPostAdaptor extends UrlAdaptor {
        processQuery(ds, query, hierarchyFilters) {
            const result = super.processQuery(ds, query, hierarchyFilters);
            result.type = 'POST';
            result.contentType = 'application/json; charset=utf-8';
            let payload = typeof result.data === 'string' ? JSON.parse(result.data) : result.data;
            if (!payload) payload = {};

            if (query && query.params && query.params.length > 0) {
                query.params.forEach((param) => { payload[param.key] = param.value; });
            }
            if (payload.where && Array.isArray(payload.where) && payload.where.length === 0) {
                delete payload.where;
            }
            if (payload.sorted && Array.isArray(payload.sorted) && payload.sorted.length === 0) {
                delete payload.sorted;
            }
            this.lastWhere = payload.where || [];
            this.lastSorted = payload.sorted || [];
            this.lastTipos = payload.currentTipos;
            result.data = JSON.stringify(payload);
            return result;
        }

        processResponse(data) {
            const obj = typeof data === 'string' ? JSON.parse(data) : data;
            this.lastResponse = obj;
            return { result: obj.rowData || [], count: obj.count || 0 };
        }
    }

    const dataManager = new DataManager({
        url: config.dataUrl,
        adaptor: new CustomPostAdaptor(),
        headers: [{ 'X-CSRF-TOKEN': csrfToken }],
        crossDomain: false,
    });

    const customFilterFields = {};
    let applyingCustomFilter = false;
    // eslint-disable-next-line no-use-before-define
    const filterCtx = {
        get grid() { return grid; },
        customFilterFields,
        getApplying: () => applyingCustomFilter,
        setApplying: (v) => { applyingCustomFilter = v; },
    };

    const columnas = [
        { field: 'c_rowid', headerText: 'ID', allowFiltering: true, type: 'string', width: 70, minWidth: 50, freeze: 'Left', visible: false, allowReordering: false },
        {
            field: 'c_label',
            headerText: 'Nombre',
            width: 230,
            minWidth: 80,
            freeze: 'Left',
            allowFiltering: true,
            template: (data) => `
                <button type="button" class="categoria-ficha-link" data-categoria-id="${data.c_rowid}" title="Ver / editar"
                        style="display:inline-flex;align-items:center;max-width:100%;border:0;background:transparent;padding:0;cursor:pointer;color:inherit;">
                    <span style="flex-shrink:0;width:10px;height:10px;border-radius:9999px;margin-right:8px;background:${escapar(data.c_color || '#cbd5e1')};"></span>
                    <strong style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:underline dotted;text-underline-offset:3px;">${escapar(data.c_label)}</strong>
                </button>`,
            allowReordering: false,
        },
        {
            field: 'c_tipo_label',
            headerText: 'Tipo',
            width: 190,
            minWidth: 80,
            allowFiltering: false,
            template: (data) => {
                const t = tiposPorId.get(data.c_tipo);
                const color = `#${t?.color ?? '64748b'}`;
                return `<span style="display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:9999px;font-size:12px;font-weight:600;background:${color}1f;color:${color};">
                    <i class="fa-solid ${t?.icono ?? 'fa-tag'}"></i>${escapar(data.c_tipo_label)}</span>`;
            },
        },
        {
            field: 'c_padre',
            headerText: 'Dentro de',
            width: 260,
            minWidth: 80,
            allowFiltering: true,
            template: (data) => (data.c_padre
                ? `<span style="color:#6b7280;">${escapar(data.c_padre)}</span>`
                : '<span style="color:#9ca3af;font-style:italic;">Principal</span>'),
        },
        { field: 'c_description', headerText: 'Descripción', width: 240, minWidth: 80, allowFiltering: true },
        { field: 'c_subcategorias', headerText: 'Subcategorías', type: 'number', width: 130, minWidth: 60, textAlign: 'Right', allowFiltering: false },
        { field: 'c_elementos', headerText: 'Elementos', type: 'number', width: 110, minWidth: 60, textAlign: 'Right', allowFiltering: false, allowSorting: false },
        { field: 'c_creador', headerText: 'Creada por', width: 160, minWidth: 60, allowFiltering: false, allowSorting: false, visible: false },
        { field: 'c_datec', headerText: 'Fecha creación', type: 'date', width: 150, minWidth: 50, allowFiltering: true, format: { type: 'date', format: 'dd/MM/yyyy' }, textAlign: 'Right' },
        { field: 'c_tms', headerText: 'Última modificación', type: 'date', width: 170, minWidth: 50, allowFiltering: true, format: { type: 'date', format: 'dd/MM/yyyy HH:mm' }, textAlign: 'Right', visible: false },
    ];

    const grid = new Grid({
        dataSource: dataManager,
        query: new Query(),
        locale: 'es',
        allowPaging: true,
        allowResizing: true,
        gridLines: 'Both',
        height: '100%',
        allowSorting: true,
        allowFiltering: true,
        allowSelection: true,
        enableClipboard: true,
        showColumnChooser: true,
        allowReordering: true,
        enablePersistence: false,
        selectionSettings: { type: 'Multiple', mode: 'Cell', cellSelectionMode: 'Flow' },
        filterSettings: {
            type: 'FilterBar',
            showFilterBarStatus: false,
            mode: 'Immediate',
            operators: {
                stringOperator: [
                    { value: 'contains', text: 'Contiene' },
                    { value: 'startswith', text: 'Empieza con' },
                    { value: 'endswith', text: 'Termina con' },
                    { value: 'equal', text: 'Igual' },
                ],
                numberOperator: [
                    { value: 'equal', text: 'Igual' },
                    { value: 'greaterthan', text: 'Mayor que' },
                    { value: 'lessthan', text: 'Menor que' },
                ],
            },
        },
        pageSettings: { pageSize: 50, pageSizes: [50, 100, 200, 500] },
        showColumnMenu: false,
        toolbar: getToolbarTemplate(config),
        columns: autoFilterTemplates(columnas, filterCtx, IDGRID),

        dataBound() {
            AdvancedGridUX.enable(this);
            activarTooltips();
        },
        actionBegin(args) {
            if (args.requestType === 'filtering' && args.currentFilteringColumn && customFilterFields[args.currentFilteringColumn] && !filterCtx.getApplying()) {
                args.cancel = true;
            }
        },
    });

    limpiarFiltroPersistente(IDGRID);
    quitarCongeladasEnMovil(grid);
    grid.appendTo(`#${IDGRID}`);

    const refrescar = () => {
        AdvancedGridUX.invalidateCache(grid);
        grid.refresh();
    };

    const fichaModal = new CategoriaFichaModal({
        fichaUrl: config.fichaUrl,
        nuevoUrl: config.nuevoUrl,
        csrfToken,
        onSaved: refrescar,
    });
    document.getElementById(IDGRID).addEventListener('click', (e) => {
        const link = e.target.closest('.categoria-ficha-link');
        if (link) fichaModal.abrir(link.dataset.categoriaId);
    });
    // "Nueva" arranca con el tipo filtrado si hay uno solo elegido.
    document.getElementById('btnNuevaCategoria')?.addEventListener('click', () => {
        fichaModal.abrirNuevo(currentTipos.length === 1 ? `?tipo=${currentTipos[0]}` : '');
    });

    document.getElementById('reloadGrid').addEventListener('click', refrescar);

    const selectorTipos = new ColorMultiSelectDropdown('#tiposDropdown', config.tipos.map((t) => ({
        rowid: t.rowid,
        label: `${t.label} (${t.total})`,
        color: t.color,
    })), {
        multiple: true,
        visibleCount: 2,
        initialSelected: [],
        onChange: (idsSeleccionados) => {
            currentTipos = idsSeleccionados.map(Number);
            updateGridQuery();
        },
    });

    document.getElementById('resetGrid').addEventListener('click', () => {
        currentTipos = [];
        selectorTipos.clear();

        updateGridQuery();

        grid.allowFiltering = false;
        grid.clearFiltering();
        grid.filterSettings.columns = [];
        grid.clearSorting();
        grid.searchSettings.key = '';
        grid.pageSettings.currentPage = 1;
        grid.allowFiltering = true;
    });

    function updateGridQuery() {
        if (updateGridTimer) clearTimeout(updateGridTimer);

        updateGridTimer = setTimeout(() => {
            const signature = JSON.stringify({ tipos: currentTipos.slice().sort() });
            if (signature === lastQuerySignature) return;
            lastQuerySignature = signature;

            let query = new Query();
            if (currentTipos.length > 0) {
                query = query.addParams('currentTipos', JSON.stringify(currentTipos));
            }

            grid.query = query;
            grid.pageSettings.currentPage = 1;
            grid.refresh();
        }, 300);
    }

    setTimeout(() => {
        const multiPopup = document.querySelector('.dropdown-options-integros');
        if (multiPopup) document.body.appendChild(multiPopup);
    }, 100);

    const payloadExport = (gridInstance) => {
        const adaptor = gridInstance.dataSource?.adaptor;
        return {
            columnas: cleanColumns(gridInstance.getColumns()),
            where: adaptor?.lastWhere || [],
            sorted: adaptor?.lastSorted || [],
            currentTipos: adaptor?.lastTipos,
        };
    };

    document.getElementById('btnDescargarExcel').addEventListener('click', async () => {
        const gridInstance = document.getElementById(IDGRID).ej2_instances[0];
        const totalFilas = gridInstance.dataSource?.adaptor?.lastResponse?.count || 0;
        if (totalFilas === 0) {
            Swal.fire({ title: 'La consulta no trajo filas para exportar', icon: 'info' });
            return;
        }

        const fechaActual = new Date().toISOString().split('T')[0].replaceAll('-', '_');
        try {
            await downloadFile(config.exportXlsxUrl, `Categorias_${fechaActual}.xlsx`, payloadExport(gridInstance));
            Swal.fire({ title: 'Descarga exitosa', icon: 'success', timer: 2000 });
        } catch (e) {
            // downloadFile ya mostró el Swal de error
        }
    });

    document.getElementById('btnModalCsv').addEventListener('click', () => {
        ensureCsvModal();
        showCsvModal();
    });

    document.addEventListener('click', async (e) => {
        if (e.target.id !== 'btnDescargarCsv') return;

        const gridInstance = document.getElementById(IDGRID).ej2_instances[0];
        const totalFilas = gridInstance.dataSource?.adaptor?.lastResponse?.count || 0;
        if (totalFilas === 0) {
            Swal.fire({ title: 'La consulta no trajo filas para exportar', icon: 'info' });
            return;
        }

        hideCsvModal();

        const separadorValue = document.getElementById('separadorCsv').value;
        const fechaActual = new Date().toISOString().split('T')[0].replaceAll('-', '_');

        try {
            await downloadFile(config.exportCsvUrl, `Categorias_${fechaActual}.csv`, {
                ...payloadExport(gridInstance),
                separador: separadorValue === '2' ? ',' : ';',
            });
            Swal.fire({ title: 'Descarga exitosa', icon: 'success', timer: 2000 });
        } catch (e) {
            // downloadFile ya mostró el Swal de error
        }
    });
});
