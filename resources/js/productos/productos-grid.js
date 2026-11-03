import '../../css/syncfusion-theme.css';
import {
    Grid, Toolbar, ColumnChooser, Filter, Page, Sort, Resize, Reorder,
} from '@syncfusion/ej2-grids';
import { DataManager, Query, UrlAdaptor } from '@syncfusion/ej2-data';
import Swal from 'sweetalert2';
import { autoFilterTemplates, templateDropDownListSF } from '../grid/filterTemplates.js';
import { AdvancedGridUX, activarTooltips, createExtraFieldsColumns, formatNumberCO } from '../grid/gridUx.js';
import { ColorMultiSelectDropdown } from '../grid/colorMultiSelect.js';
import { quitarCongeladasEnMovil, toolbarBtnNuevo, toolbarTitulo } from '../grid/toolbarButtons.js';
import { ProductoFichaModal } from './producto-ficha.js';
import { iniciarProductosStats } from './productos-stats.js';
import {
    downloadFile, ensureCsvModal, showCsvModal, hideCsvModal, limpiarFiltroPersistente, cleanColumns,
} from '../grid/exportHelpers.js';

function getToolbarTemplate(config) {
    return [
        { template: toolbarTitulo(config.icono, config.titulo), align: 'Left' },
        {
            template: `
                <div style="width: 350px;">
                    <div style="display:flex;align-items:center;">
                        <i class="fas fa-tags" style="font-size:16px; margin-right: 8px; color:#6c6aa8;"></i>
                        <div id="categoriasProductoDropdown" style="flex:1;"></div>
                    </div>
                </div>
            `,
            align: 'Left',
        },
        ...(config.puedeCrear ? [{ template: toolbarBtnNuevo('btnNuevoProducto', 'Nuevo'), align: 'Right' }] : []),
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
    const config = window.PRODUCTOS_CONFIG;
    const IDGRID = config.gridId;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    let currentIdsCatProd = [];
    let currentActividad = '';
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
            result.data = JSON.stringify(payload);
            return result;
        }

        processResponse(data, ds, query, xhr, request, changes) {
            const obj = typeof data === 'string' ? JSON.parse(data) : data;
            this.lastResponse = obj;
            let processedResult = [];
            try {
                processedResult = (obj.rowData || []).map((item) => {
                    const row = {
                        p_rowid: Number(item.p_rowid),
                        p_ref: item.p_ref,
                        p_label: item.p_label,
                        p_price: Number(item.p_price),
                        p_price_ttc: Number(item.p_price_ttc),
                        p_tva_tx: Number(item.p_tva_tx),
                        p_price_min: Number(item.p_price_min),
                        p_datec: item.p_datec,
                        p_tms: item.p_tms,
                        p_tosell: item.p_tosell,
                        p_tobuy: item.p_tobuy,
                        pc_ref: item.pc_ref,
                        uautor_name: item.uautor_name,
                        ueditor_name: item.ueditor_name,
                        p_fk_user_author: item.p_fk_user_author,
                        p_fk_user_modif: item.p_fk_user_modif,
                    };
                    if (item.extrafields && typeof item.extrafields === 'object') {
                        Object.keys(item.extrafields).forEach((key) => {
                            const field = item.extrafields[key];
                            if (!field) return;
                            const val = field.value;

                            if (field.type === 'boolean') {
                                row[`ef_${key}`] = val === '1' || val === 1 || val === true;
                            } else if (['int', 'double', 'price', 'pricecy'].includes(field.type)) {
                                row[`ef_${key}`] = val !== null && val !== '' ? Number(val) : null;
                            } else if (['date', 'datetime'].includes(field.type)) {
                                row[`ef_${key}`] = val ? new Date(val) : null;
                            } else {
                                row[`ef_${key}`] = val;
                            }
                        });
                    }
                    return row;
                });
            } catch (e) {
                console.error('Error procesando rowData:', e);
            }

            if (obj.rowData && obj.rowData.length > 0 && !this.extraColumnsCreated) {
                createExtraFieldsColumns(obj.rowData[0].extrafields, IDGRID, filterCtx);
                this.extraColumnsCreated = true;
            }

            return { result: processedResult, count: obj.count || 0 };
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

    const templateCompraVentaFilter = templateDropDownListSF({
        id: `${IDGRID}_compra_venta`,
        fieldName: '',
        options: [
            { value: '', text: 'Todos' },
            { value: 'sell', text: 'En venta' },
            { value: 'buy', text: 'En compra' },
        ],
        idGrid: IDGRID,
        onFilter: (val) => {
            filterCtx.setApplying(true);
            const otherFilters = (grid.filterSettings.columns || []).filter(
                (f) => f.field !== 'p_tosell' && f.field !== 'p_tobuy',
            );
            let newCols = otherFilters;
            if (val === 'sell') {
                newCols = [...otherFilters, { field: 'p_tosell', operator: 'equal', value: 1, predicate: 'and' }];
            } else if (val === 'buy') {
                newCols = [...otherFilters, { field: 'p_tobuy', operator: 'equal', value: 1, predicate: 'and' }];
            }
            grid.filterSettings = { ...grid.filterSettings, columns: newCols };
            setTimeout(() => filterCtx.setApplying(false), 50);
        },
    });

    const grid = new Grid({
        dataSource: dataManager,
        query: new Query().addParams('type', config.type),
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
        columns: autoFilterTemplates([
            { field: 'p_rowid', headerText: 'ID', allowFiltering: true, type: 'string', width: 70, minWidth: 50, freeze: 'Left', visible: false, allowReordering: false },
            {
                field: 'p_ref',
                headerText: 'Ref',
                width: 150,
                minWidth: 50,
                freeze: 'Left',
                allowFiltering: true,
                template: (data) => `
                    <button type="button" class="producto-ficha-link" data-producto-id="${data.p_rowid}" title="Ver / editar"
                            style="display:inline-flex;align-items:center;border:0;background:transparent;padding:0;cursor:pointer;color:inherit;">
                        <span class="fas ${config.icono}" style="color:#a69944;margin-right:6px;"></span>
                        <strong style="text-decoration:underline dotted;text-underline-offset:3px;">${data.p_ref ?? ''}</strong>
                    </button>`,
                allowReordering: false,
            },
            { field: 'p_label', headerText: 'Etiqueta', width: 250, minWidth: 50, allowFiltering: true, freeze: 'Left' },
            { field: 'p_price', headerText: 'Precio de venta (Sin IVA)', width: 190, minWidth: 50, allowFiltering: true, type: 'number', textAlign: 'Right', template: (data) => formatNumberCO(data.p_price) },
            { field: 'p_price_ttc', headerText: 'Precio de venta (+ IVA)', width: 190, minWidth: 50, allowFiltering: true, type: 'number', textAlign: 'Right', template: (data) => formatNumberCO(data.p_price_ttc) },
            { field: 'p_price_min', headerText: 'Mejor precio compra', width: 170, minWidth: 50, allowFiltering: true, type: 'number', textAlign: 'Right', template: (data) => formatNumberCO(data.p_price_min) },
            {
                field: 'p_tva_tx', headerText: 'IVA %', width: 80, minWidth: 50, allowFiltering: true, type: 'number', textAlign: 'Right',
                template: (data) => `${Number(data.p_tva_tx)} %`,
            },
            { field: 'p_datec', headerText: 'Fecha creación', type: 'date', width: 150, minWidth: 50, allowFiltering: true, format: { type: 'date', format: 'dd/MM/yyyy' }, textAlign: 'Right' },
            { field: 'p_tms', headerText: 'Última modificación', type: 'date', width: 150, minWidth: 50, allowFiltering: true, format: { type: 'date', format: 'dd/MM/yyyy' }, textAlign: 'Right' },
            {
                field: 'uautor_name',
                headerText: 'Creador',
                width: 200,
                minWidth: 50,
                allowFiltering: true,
                template: (data) => (data.uautor_name ? `<span class="fas fa-user" style="color:#79633F;margin-right:6px;"></span>${data.uautor_name}` : ''),
            },
            {
                field: 'ueditor_name',
                headerText: 'Editor',
                width: 200,
                minWidth: 50,
                allowFiltering: true,
                template: (data) => (data.ueditor_name ? `<span class="fas fa-user" style="color:#79633F;margin-right:6px;"></span>${data.ueditor_name}` : ''),
            },
            {
                field: 'pc_ref',
                headerText: 'Plantilla contable',
                width: 200,
                minWidth: 50,
                allowFiltering: true,
                template: (data) => (data.pc_ref ? `<span class="fa-solid fa-file-invoice" style="color:#6c6aa8;margin-right:6px;"></span>${data.pc_ref}` : ''),
            },
            { field: 'p_tosell', headerText: 'Estado venta', allowFiltering: false, type: 'string', width: 100, visible: false, allowReordering: false },
            { field: 'p_tobuy', headerText: 'Estado compra', allowFiltering: false, type: 'string', width: 100, visible: false, allowReordering: false },
            {
                field: 'compra_venta',
                headerText: 'En compra / En venta',
                width: 180,
                textAlign: 'Center',
                freeze: 'Right',
                template: (data) => {
                    const enVenta = String(data.p_tosell) === '1';
                    const enCompra = String(data.p_tobuy) === '1';
                    const badge = (activo) => `<span style="display:inline-block;padding:2px 10px;border-radius:9999px;font-size:11px;font-weight:600;`
                        + `${activo ? 'background:#dcfce7;color:#15803d;' : 'background:#fee2e2;color:#b91c1c;'}">${activo ? 'Sí' : 'No'}</span>`;
                    return `${badge(enVenta)} / ${badge(enCompra)}`;
                },
                filterBarTemplate: templateCompraVentaFilter,
                allowFiltering: true,
                allowReordering: false,
            },
        ], filterCtx, IDGRID),

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

    const fichaModal = new ProductoFichaModal({
        fichaUrl: config.fichaUrl,
        nuevoUrl: config.nuevoUrl,
        etiqueta: config.type === 1 ? 'el servicio' : 'el producto',
        csrfToken,
        onSaved: () => { grid.refresh(); stats?.cargar(true); },
    });
    // La franja solo existe en el listado de productos (no en el de servicios).
    const stats = document.getElementById('productos-stats')
        ? iniciarProductosStats({
            url: config.estadisticasUrl,
            onFiltro: ({ actividad }) => {
                currentActividad = actividad;
                updateGridQuery();
            },
        })
        : null;
    document.getElementById(IDGRID).addEventListener('click', (e) => {
        const link = e.target.closest('.producto-ficha-link');
        if (link) fichaModal.abrir(link.dataset.productoId);
    });
    document.getElementById('btnNuevoProducto')?.addEventListener('click', () => fichaModal.abrirNuevo(`?tipo=${config.type}`));

    document.getElementById('reloadGrid').addEventListener('click', () => {
        AdvancedGridUX.invalidateCache(grid);
        grid.refresh();
        stats?.cargar(true);
    });

    const selectorCategoriasProducto = new ColorMultiSelectDropdown('#categoriasProductoDropdown', config.categorias, {
        multiple: true,
        visibleCount: 2,
        initialSelected: [],
        onChange: (idsSeleccionados) => {
            currentIdsCatProd = idsSeleccionados;
            updateGridQuery();
        },
    });

    document.getElementById('resetGrid').addEventListener('click', () => {
        currentIdsCatProd = [];
        currentActividad = '';
        stats?.limpiar();
        selectorCategoriasProducto.clear();

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
            const signature = JSON.stringify({
                catProd: currentIdsCatProd.slice().sort(),
                actividad: currentActividad,
            });
            if (signature === lastQuerySignature) return;
            lastQuerySignature = signature;

            let query = new Query().addParams('type', config.type);
            if (currentIdsCatProd.length > 0) {
                query = query.addParams('currentIdsCatProd', JSON.stringify(currentIdsCatProd));
            }

            if (currentActividad) query = query.addParams('actividad', currentActividad);

            grid.query = query;
            grid.refresh();
        }, 300);
    }

    setTimeout(() => {
        const multiPopup = document.querySelector('.dropdown-options-integros');
        if (multiPopup) document.body.appendChild(multiPopup);
    }, 100);

    document.getElementById('btnDescargarExcel').addEventListener('click', async () => {
        const gridInstance = document.getElementById(IDGRID).ej2_instances[0];
        const adaptor = gridInstance.dataSource?.adaptor;
        const totalFilas = adaptor?.lastResponse?.count || 0;
        if (totalFilas === 0) {
            Swal.fire({ title: 'La consulta no trajo filas para exportar', icon: 'info' });
            return;
        }

        const fechaActual = new Date().toISOString().split('T')[0].replaceAll('-', '_');
        try {
            await downloadFile(config.exportXlsxUrl, `${config.titulo}_${fechaActual}.xlsx`, {
                columnas: cleanColumns(gridInstance.getColumns()),
                where: adaptor?.lastWhere || [],
                sorted: adaptor?.lastSorted || [],
                actividad: currentActividad,
                type: config.type,
            });
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
        const adaptor = gridInstance.dataSource?.adaptor;
        const totalFilas = adaptor?.lastResponse?.count || 0;
        if (totalFilas === 0) {
            Swal.fire({ title: 'La consulta no trajo filas para exportar', icon: 'info' });
            return;
        }

        hideCsvModal();

        const separadorValue = document.getElementById('separadorCsv').value;
        const fechaActual = new Date().toISOString().split('T')[0].replaceAll('-', '_');

        try {
            await downloadFile(config.exportCsvUrl, `${config.titulo}_${fechaActual}.csv`, {
                columnas: cleanColumns(gridInstance.getColumns()),
                separador: separadorValue === '2' ? ',' : ';',
                where: adaptor?.lastWhere || [],
                sorted: adaptor?.lastSorted || [],
                actividad: currentActividad,
                type: config.type,
            });
            Swal.fire({ title: 'Descarga exitosa', icon: 'success', timer: 2000 });
        } catch (e) {
            // downloadFile ya mostró el Swal de error
        }
    });
});
