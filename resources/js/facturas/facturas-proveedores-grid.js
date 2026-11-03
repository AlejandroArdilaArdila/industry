import '../../css/syncfusion-theme.css';
import {
    Grid, Toolbar, ColumnChooser, Filter, Page, Sort, Resize, Reorder,
} from '@syncfusion/ej2-grids';
import { DataManager, Query, UrlAdaptor } from '@syncfusion/ej2-data';
import Swal from 'sweetalert2';
import { quitarCongeladasEnMovil, toolbarTitulo } from '../grid/toolbarButtons.js';
import { autoFilterTemplates, templateDropDownListSF } from '../grid/filterTemplates.js';
import { AdvancedGridUX, activarTooltips, formatNumberCO } from '../grid/gridUx.js';
import {
    downloadFile, ensureCsvModal, showCsvModal, hideCsvModal, limpiarFiltroPersistente, cleanColumns,
} from '../grid/exportHelpers.js';

const IDGRID = 'GridFacturasProveedor';

function getToolbarTemplate() {
    return [
        { template: toolbarTitulo('fa-file-invoice', 'Facturas de proveedor'), align: 'Left' },
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
    const config = window.FACTURAS_PROVEEDOR_CONFIG;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

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
                processedResult = (obj.rowData || []).map((item) => ({
                    f_rowid: Number(item.f_rowid),
                    f_ref: item.f_ref,
                    f_ref_supplier: item.f_ref_supplier,
                    f_type: item.f_type,
                    f_datef: item.f_datef,
                    s_rowid: Number(item.s_rowid),
                    s_nom: item.s_nom,
                    f_total_ht: Number(item.f_total_ht),
                    f_total_tva: Number(item.f_total_tva),
                    f_localtax1: Number(item.f_localtax1),
                    f_total_ttc: Number(item.f_total_ttc),
                    f_multicurrency_code: item.f_multicurrency_code,
                    f_multicurrency_tx: Number(item.f_multicurrency_tx),
                    f_datec: item.f_datec,
                    f_tms: item.f_tms,
                    f_date_closing: item.f_date_closing,
                    f_note_public: item.f_note_public,
                    f_note_private: item.f_note_private,
                    f_fk_statut: item.f_fk_statut,
                    u_rowid: Number(item.u_rowid),
                    creadoPor: item.creadoPor,
                    ba_rowid: item.ba_rowid,
                    ba_label: item.ba_label,
                    f_fk_cond_reglement: item.f_fk_cond_reglement,
                }));
            } catch (e) {
                console.error('Error procesando rowData:', e);
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

    const templateTipoFilter = templateDropDownListSF({
        id: 'f_type',
        fieldName: 'f_type',
        options: [
            { value: '', text: 'Todos' },
            { value: '0', text: 'Factura' },
            { value: '2', text: 'Nota Crédito' },
        ],
        idGrid: IDGRID,
    });

    const templateCondicionPagoFilter = templateDropDownListSF({
        id: 'f_fk_cond_reglement',
        fieldName: 'f_fk_cond_reglement',
        options: [{ value: '', text: 'Todas' }, ...config.condicionesPago],
        idGrid: IDGRID,
    });

    const templateEstadoFilter = templateDropDownListSF({
        id: 'f_fk_statut',
        fieldName: 'f_fk_statut',
        options: [
            { value: '', text: 'Todos' },
            { value: '0', text: 'Borrador' },
            { value: '1', text: 'No pagado' },
            { value: '2', text: 'Pagado' },
            { value: '3', text: 'Abandonado' },
        ],
        idGrid: IDGRID,
    });

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
        toolbar: getToolbarTemplate(),
        columns: autoFilterTemplates([
            { field: 'f_rowid', headerText: 'ID', allowFiltering: true, type: 'string', width: 70, minWidth: 50, freeze: 'Left', visible: false, allowReordering: false },
            {
                field: 'f_ref',
                headerText: 'Ref',
                width: 130,
                minWidth: 50,
                freeze: 'Left',
                allowFiltering: true,
                template: (data) => `
                    <span class="fas fa-file-invoice" style="color:#65953d;margin-right:6px;"></span>
                    <strong>${data.f_ref ?? ''}</strong>`,
                allowReordering: false,
            },
            {
                field: 's_nom',
                headerText: 'Tercero',
                width: 180,
                minWidth: 50,
                freeze: 'Left',
                allowFiltering: true,
                template: (data) => `<span class="fas fa-building" style="color:#6c6aa8;margin-right:6px;"></span>${data.s_nom ?? ''}`,
            },
            { field: 'f_total_ht', headerText: 'Sub-total', width: 140, minWidth: 50, allowFiltering: true, type: 'number', textAlign: 'Right', freeze: 'Left', template: (data) => formatNumberCO(data.f_total_ht) },
            { field: 'f_total_tva', headerText: 'Iva', width: 130, minWidth: 50, allowFiltering: true, type: 'number', textAlign: 'Right', freeze: 'Left', template: (data) => formatNumberCO(data.f_total_tva) },
            { field: 'f_localtax1', headerText: 'Inc', width: 100, minWidth: 50, allowFiltering: true, type: 'number', textAlign: 'Right', template: (data) => formatNumberCO(data.f_localtax1) },
            { field: 'f_total_ttc', headerText: 'Total', width: 140, minWidth: 50, allowFiltering: true, type: 'number', textAlign: 'Right', freeze: 'Left', template: (data) => formatNumberCO(data.f_total_ttc) },
            { field: 'f_ref_supplier', headerText: 'Ref Proveedor', width: 130, minWidth: 50, allowFiltering: true },
            {
                field: 'f_type',
                headerText: 'Tipo',
                width: 110,
                minWidth: 50,
                allowFiltering: true,
                filterBarTemplate: templateTipoFilter,
            },
            {
                field: 'ba_label',
                headerText: 'Medio de pago',
                width: 180,
                minWidth: 50,
                allowFiltering: true,
                template: (data) => (data.ba_label ? `<span class="fas fa-university" style="color:#b0bb39;margin-right:6px;"></span><strong>${data.ba_label}</strong>` : ''),
            },
            {
                field: 'f_fk_cond_reglement',
                headerText: 'Condición pago',
                width: 160,
                minWidth: 50,
                allowFiltering: true,
                filterBarTemplate: templateCondicionPagoFilter,
            },
            {
                field: 'creadoPor',
                headerText: 'Creado por',
                width: 200,
                minWidth: 50,
                allowFiltering: true,
                template: (data) => (data.creadoPor ? `<span class="fas fa-user" style="color:#65953d;margin-right:6px;"></span>${data.creadoPor}` : ''),
            },
            { field: 'f_datef', headerText: 'F.Facturación', type: 'date', width: 130, minWidth: 50, allowFiltering: true, format: { type: 'date', format: 'dd/MM/yyyy' }, textAlign: 'Right' },
            { field: 'f_multicurrency_code', headerText: 'Divisa', width: 90, allowFiltering: true },
            { field: 'f_multicurrency_tx', headerText: 'Tasa conversión', width: 120, type: 'number', textAlign: 'Right' },
            { field: 'f_datec', headerText: 'F.Creación', type: 'date', width: 130, minWidth: 50, allowFiltering: true, format: { type: 'date', format: 'dd/MM/yyyy' }, textAlign: 'Right' },
            { field: 'f_tms', headerText: 'F.Modificación', type: 'date', width: 130, minWidth: 50, allowFiltering: true, format: { type: 'date', format: 'dd/MM/yyyy' }, textAlign: 'Right' },
            { field: 'f_date_closing', headerText: 'F.Cierre', type: 'date', width: 130, minWidth: 50, allowFiltering: true, format: { type: 'date', format: 'dd/MM/yyyy' }, textAlign: 'Right' },
            {
                field: 'f_note_public',
                headerText: 'Nota pública',
                width: 150,
                template: (data) => (data.f_note_public ? `<div title="${data.f_note_public}">${data.f_note_public}</div>` : ''),
            },
            {
                field: 'f_note_private',
                headerText: 'Nota privada',
                width: 150,
                template: (data) => (data.f_note_private ? `<div title="${data.f_note_private}">${data.f_note_private}</div>` : ''),
            },
            {
                field: 'f_fk_statut',
                headerText: 'Estado',
                width: 130,
                allowFiltering: true,
                freeze: 'Right',
                textAlign: 'Center',
                filterBarTemplate: templateEstadoFilter,
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

    document.getElementById('reloadGrid').addEventListener('click', () => {
        AdvancedGridUX.invalidateCache(grid);
        grid.refresh();
    });

    document.getElementById('resetGrid').addEventListener('click', () => {
        grid.allowFiltering = false;
        grid.clearFiltering();
        grid.filterSettings.columns = [];
        grid.clearSorting();
        grid.searchSettings.key = '';
        grid.pageSettings.currentPage = 1;
        grid.allowFiltering = true;
    });

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
            await downloadFile(config.exportXlsxUrl, `FacturasProveedor_${fechaActual}.xlsx`, {
                columnas: cleanColumns(gridInstance.getColumns()),
                where: adaptor?.lastWhere || [],
                sorted: adaptor?.lastSorted || [],
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
            await downloadFile(config.exportCsvUrl, `FacturasProveedor_${fechaActual}.csv`, {
                columnas: cleanColumns(gridInstance.getColumns()),
                separador: separadorValue === '2' ? ',' : ';',
                where: adaptor?.lastWhere || [],
                sorted: adaptor?.lastSorted || [],
            });
            Swal.fire({ title: 'Descarga exitosa', icon: 'success', timer: 2000 });
        } catch (e) {
            // downloadFile ya mostró el Swal de error
        }
    });
});
