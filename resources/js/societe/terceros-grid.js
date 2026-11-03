import '../../css/syncfusion-theme.css';
import {
    Grid, Toolbar, ColumnChooser, Filter, Page, Sort, Resize, Reorder,
} from '@syncfusion/ej2-grids';
import { DataManager, Query, UrlAdaptor } from '@syncfusion/ej2-data';
import Swal from 'sweetalert2';
import { autoFilterTemplates, templateDropDownListSF } from '../grid/filterTemplates.js';
import { AdvancedGridUX, activarTooltips, createExtraFieldsColumns } from '../grid/gridUx.js';
import { ColorMultiSelectDropdown } from '../grid/colorMultiSelect.js';
import {
    downloadFile, ensureCsvModal, showCsvModal, hideCsvModal, limpiarFiltroPersistente, cleanColumns,
} from '../grid/exportHelpers.js';
import { TerceroFichaModal } from './tercero-ficha.js';
import { iniciarTercerosStats } from './terceros-stats.js';
import { TerceroIaModal } from './terceros-ia.js';
import { quitarCongeladasEnMovil, toolbarBtnNuevo, toolbarTitulo } from '../grid/toolbarButtons.js';

const IDGRID = 'GridTerceros';

function getToolbarTemplate(puedeCrear, puedeIa) {
    return [
        { template: toolbarTitulo('fa-building', 'Terceros'), align: 'Left' },
        {
            template: `
                <div style="width: 350px;">
                    <div style="display:flex;align-items:center;">
                        <i class="fas fa-tags" style="font-size:16px; margin-right: 8px; color:#0891b2;"></i>
                        <div id="categoriasClienteDropdown" style="flex:1;"></div>
                    </div>
                </div>
            `,
            align: 'Left',
        },
        {
            template: `
                <div style="width: 350px;">
                    <div style="display:flex;align-items:center;">
                        <i class="fas fa-tags" style="font-size:16px; margin-right: 8px; color:#e65100;"></i>
                        <div id="categoriasProveedorDropdown" style="flex:1;"></div>
                    </div>
                </div>
            `,
            align: 'Left',
        },
        ...(puedeIa ? [{ template: '<button type="button" id="btnNuevoTerceroIa" title="Crear un tercero conversando con la IA" style="display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border:1px solid #7c3aed;border-radius:6px;background:#fff;color:#7c3aed;font-weight:600;cursor:pointer;"><i class="fa-solid fa-wand-magic-sparkles"></i> Creación con IA</button>', align: 'Right' }] : []),
        ...(puedeCrear ? [{ template: toolbarBtnNuevo('btnNuevoTercero', 'Nuevo'), align: 'Right' }] : []),
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
    const config = window.TERCEROS_CONFIG;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    let currentIdsCatClient = [];
    let currentIdsCatProv = [];
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
                        s_rowid: Number(item.s_rowid),
                        s_nom: item.s_nom,
                        s_name_alias: item.s_name_alias,
                        s_code_client: item.s_code_client,
                        s_code_fournisseur: item.s_code_fournisseur,
                        s_code_compta: item.s_code_compta,
                        s_code_compta_fournisseur: item.s_code_compta_fournisseur,
                        s_address: item.s_address,
                        s_zip: item.s_zip,
                        s_town: item.s_town,
                        state_nom: item.state_nom,
                        region_nom: item.region_nom,
                        country_code_iso: item.country_code_iso,
                        s_email: item.s_email,
                        s_url: item.s_url,
                        s_phone: item.s_phone,
                        s_fax: item.s_fax,
                        typent_libelli: item.typent_libelli,
                        staff_id: item.staff_id,
                        s_siren: item.s_siren,
                        s_siret: item.s_siret,
                        s_ape: item.s_ape,
                        s_idprof4: item.s_idprof4,
                        s_idprof5: item.s_idprof5,
                        s_idprof6: item.s_idprof6,
                        s_tva_intra: item.s_tva_intra,
                        s_client: item.s_client,
                        s_fournisseur: item.s_fournisseur,
                        s_fk_stcomm: item.s_fk_stcomm,
                        s2_nom: item.s2_nom,
                        s_datec: item.s_datec,
                        s_tms: item.s_tms,
                        s_status: item.s_status,
                        s_import_key: item.s_import_key,
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

    const templateNaturalezaFilter = templateDropDownListSF({
        id: 'naturaleza',
        fieldName: 'naturaleza',
        options: [
            { value: '', text: 'Todos' },
            { value: 'C', text: 'Cliente' },
            { value: 'P', text: 'Proveedor' },
        ],
        idGrid: IDGRID,
    });

    const templateEstadoFilter = templateDropDownListSF({
        id: 's_status',
        fieldName: 's_status',
        options: [
            { value: '', text: 'Todos' },
            { value: '1', text: 'Activo' },
            { value: '0', text: 'Inactivo' },
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
        toolbar: getToolbarTemplate(config.puedeCrear, config.puedeCrear && !!config.iaInicioUrl),
        columns: autoFilterTemplates([
            { field: 's_rowid', headerText: 'ID', allowFiltering: true, type: 'string', width: 70, minWidth: 50, freeze: 'Left', visible: false, allowReordering: false },
            {
                field: 's_siren',
                headerText: 'NIT',
                width: 150,
                minWidth: 50,
                freeze: 'Left',
                allowFiltering: true,
                template: (data) => `
                    <button type="button" class="tercero-ficha-link" data-tercero-id="${data.s_rowid}" title="Ver / editar tercero"
                        style="display:inline-flex;align-items:center;gap:6px;background:none;border:0;padding:0;cursor:pointer;color:inherit;">
                        <span class="fas fa-building" style="color:#a69944;"></span>
                        <strong style="text-decoration:underline;text-decoration-style:dotted;text-underline-offset:3px;">${data.s_siren ?? ''}</strong>
                    </button>`,
                allowReordering: false,
            },
            { field: 's_nom', headerText: 'Razón Social', width: 250, minWidth: 50, allowFiltering: true, freeze: 'Left' },
            { field: 's_name_alias', headerText: 'Alias', width: 120, minWidth: 50, allowFiltering: true },
            { field: 's_email', headerText: 'Correo', width: 120, minWidth: 50, allowFiltering: true },
            { field: 's_address', headerText: 'Dirección', width: 100, minWidth: 50, allowFiltering: true },
            { field: 's_phone', headerText: 'Telefono', width: 120, minWidth: 50, allowFiltering: true },
            { field: 's_datec', headerText: 'Fecha creación', type: 'date', width: 150, minWidth: 50, allowFiltering: true, format: { type: 'date', format: 'dd/MM/yyyy' }, textAlign: 'Right' },
            { field: 's_code_client', headerText: 'Código Cliente', width: 150, minWidth: 50, allowFiltering: true },
            { field: 's_code_fournisseur', headerText: 'Código Proveedor', width: 150, minWidth: 50, allowFiltering: true },
            { field: 'region_nom', headerText: 'Región', width: 80, allowFiltering: true },
            { field: 'country_code_iso', headerText: 'País', width: 50, allowFiltering: true },
            { field: 'typent_libelli', headerText: 'Tipo de terceros', width: 150, minWidth: 50, allowFiltering: true },
            { field: 's_url', headerText: 'Url', allowFiltering: true, width: 150, minWidth: 50 },
            {
                field: 'naturaleza',
                headerText: 'Naturaleza',
                width: 100,
                minWidth: 50,
                freeze: 'Right',
                textAlign: 'Center',
                template: (data) => {
                    const badge = 'display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;'
                        + 'border-radius:9999px;color:#fff;font-size:11px;font-weight:600;';
                    let content = '';
                    if (String(data.s_client) === '1') content += `<span style="${badge}background:#0891b2;">C</span>`;
                    if (String(data.s_fournisseur) === '1') content += ` <span style="${badge}background:#e65100;">P</span>`;
                    return content;
                },
                filterBarTemplate: templateNaturalezaFilter,
                allowFiltering: true,
                allowReordering: false,
            },
            {
                field: 's_status',
                headerText: 'Estado',
                width: 100,
                freeze: 'Right',
                textAlign: 'Center',
                template: (data) => {
                    const isActive = String(data.s_status) === '1';
                    const style = 'display:inline-block;padding:2px 10px;border-radius:9999px;font-size:11px;font-weight:600;'
                        + (isActive ? 'background:#dcfce7;color:#15803d;' : 'background:#fef3c7;color:#b45309;');
                    return `<span style="${style}">${isActive ? 'Activo' : 'Inactivo'}</span>`;
                },
                filterBarTemplate: templateEstadoFilter,
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

    const fichaModal = new TerceroFichaModal({
        fichaUrl: config.fichaUrl,
        nuevoUrl: config.nuevoUrl,
        csrfToken,
        onSaved: () => { grid.refresh(); stats.cargar(true); },
    });
    const stats = iniciarTercerosStats({
        url: config.estadisticasUrl,
        onFiltro: ({ actividad }) => {
            currentActividad = actividad;
            updateGridQuery();
        },
    });
    document.getElementById('btnNuevoTercero')?.addEventListener('click', () => fichaModal.abrirNuevo());
    const iaModal = new TerceroIaModal({
        inicioUrl: config.iaInicioUrl,
        mensajeUrl: config.iaMensajeUrl,
        rutUrl: config.iaRutUrl,
        crearUrl: config.iaCrearUrl,
        csrfToken,
        onCreado: () => { grid.refresh(); stats.cargar(true); },
    });
    document.getElementById('btnNuevoTerceroIa')?.addEventListener('click', () => iaModal.abrir());
    document.getElementById(IDGRID).addEventListener('click', (e) => {
        const link = e.target.closest('.tercero-ficha-link');
        if (link) fichaModal.abrir(link.dataset.terceroId);
    });

    document.getElementById('reloadGrid').addEventListener('click', () => {
        AdvancedGridUX.invalidateCache(grid);
        grid.refresh();
        stats.cargar(true);
    });

    const selectorCategoriasCliente = new ColorMultiSelectDropdown('#categoriasClienteDropdown', config.categoriasCliente, {
        multiple: true,
        visibleCount: 2,
        initialSelected: [],
        onChange: (idsSeleccionados) => {
            currentIdsCatClient = idsSeleccionados;
            updateGridQuery();
        },
    });
    const selectorCategoriasProveedor = new ColorMultiSelectDropdown('#categoriasProveedorDropdown', config.categoriasProveedor, {
        multiple: true,
        visibleCount: 2,
        initialSelected: [],
        onChange: (idsSeleccionados) => {
            currentIdsCatProv = idsSeleccionados;
            updateGridQuery();
        },
    });

    document.getElementById('resetGrid').addEventListener('click', () => {
        currentIdsCatClient = [];
        currentIdsCatProv = [];
        currentActividad = '';
        stats.limpiar();
        selectorCategoriasCliente.clear();
        selectorCategoriasProveedor.clear();

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
                catClient: currentIdsCatClient.slice().sort(),
                catProv: currentIdsCatProv.slice().sort(),
                actividad: currentActividad,
            });
            if (signature === lastQuerySignature) return;
            lastQuerySignature = signature;

            let query = new Query();
            if (currentIdsCatClient.length > 0) {
                query = query.addParams('currentIdsCatClient', JSON.stringify(currentIdsCatClient));
            }
            if (currentIdsCatProv.length > 0) {
                query = query.addParams('currentIdsCatProv', JSON.stringify(currentIdsCatProv));
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
            await downloadFile(config.exportXlsxUrl, `Terceros_${fechaActual}.xlsx`, {
                columnas: cleanColumns(gridInstance.getColumns()),
                where: adaptor?.lastWhere || [],
                sorted: adaptor?.lastSorted || [],
                actividad: currentActividad,
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
            await downloadFile(config.exportCsvUrl, `Terceros_${fechaActual}.csv`, {
                columnas: cleanColumns(gridInstance.getColumns()),
                separador: separadorValue === '2' ? ',' : ';',
                where: adaptor?.lastWhere || [],
                sorted: adaptor?.lastSorted || [],
                actividad: currentActividad,
            });
            Swal.fire({ title: 'Descarga exitosa', icon: 'success', timer: 2000 });
        } catch (e) {
            // downloadFile ya mostró el Swal de error
        }
    });
});
