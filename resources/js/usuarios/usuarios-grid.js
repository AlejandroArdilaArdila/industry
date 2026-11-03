import '../../css/syncfusion-theme.css';
import {
    Grid, Toolbar, ColumnChooser, Filter, Page, Sort, Resize, Reorder,
} from '@syncfusion/ej2-grids';
import { DataManager, Query, UrlAdaptor } from '@syncfusion/ej2-data';
import Swal from 'sweetalert2';
import { quitarCongeladasEnMovil, toolbarTitulo } from '../grid/toolbarButtons.js';
import { autoFilterTemplates, templateDropDownListSF } from '../grid/filterTemplates.js';
import { AdvancedGridUX, activarTooltips, createExtraFieldsColumns } from '../grid/gridUx.js';
import { ColorMultiSelectDropdown } from '../grid/colorMultiSelect.js';
import { UsuarioFichaModal } from './usuario-ficha.js';
import {
    downloadFile, ensureCsvModal, showCsvModal, hideCsvModal, limpiarFiltroPersistente, cleanColumns,
} from '../grid/exportHelpers.js';

const IDGRID = 'GridUsuarios';

function getToolbarTemplate() {
    return [
        { template: toolbarTitulo('fa-user-gear', 'Usuarios'), align: 'Left' },
        {
            template: `
                <div style="width: 350px;">
                    <div style="display:flex;align-items:center;">
                        <i class="fas fa-user-friends" style="font-size:16px; margin-right: 8px; color:#0891b2;"></i>
                        <div id="gruposDropdown" style="flex:1;"></div>
                    </div>
                </div>
            `,
            align: 'Left',
        },
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
    const config = window.USUARIOS_CONFIG;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    let currentIdsGrupo = [];
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
                        u_rowid: Number(item.u_rowid),
                        u_login: item.u_login,
                        u_fullname: item.u_fullname,
                        u_email: item.u_email,
                        u_job: item.u_job,
                        u_office_phone: item.u_office_phone,
                        u_town: item.u_town,
                        state_nom: item.state_nom,
                        region_nom: item.region_nom,
                        country_code_iso: item.country_code_iso,
                        u_datec: item.u_datec,
                        u_tms: item.u_tms,
                        u_admin: item.u_admin,
                        u_status: item.u_status,
                        u_import_key: item.u_import_key,
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

    const templateAdminFilter = templateDropDownListSF({
        id: 'u_admin',
        fieldName: 'u_admin',
        options: [
            { value: '', text: 'Todos' },
            { value: '1', text: 'Sí' },
            { value: '0', text: 'No' },
        ],
        idGrid: IDGRID,
    });

    const templateEstadoFilter = templateDropDownListSF({
        id: 'u_status',
        fieldName: 'u_status',
        options: [
            { value: '', text: 'Todos' },
            { value: '1', text: 'Activo' },
            { value: '0', text: 'Inactivo' },
        ],
        idGrid: IDGRID,
    });

    function buildColumns() {
        return [
            { field: 'u_rowid', headerText: 'ID', allowFiltering: true, type: 'string', width: 70, minWidth: 50, freeze: 'Left', visible: false, allowReordering: false },
            {
                field: 'u_login',
                headerText: 'Usuario',
                width: 130,
                minWidth: 50,
                freeze: 'Left',
                allowFiltering: true,
                template: (data) => (config.esAdmin
                    ? `
                    <button type="button" class="usuario-ficha-link" data-usuario-id="${data.u_rowid}" title="Ver / editar"
                            style="display:inline-flex;align-items:center;border:0;background:transparent;padding:0;cursor:pointer;color:inherit;">
                        <span class="fas fa-user" style="color:#a69944;margin-right:6px;"></span>
                        <strong style="text-decoration:underline dotted;text-underline-offset:3px;">${data.u_login ?? ''}</strong>
                    </button>`
                    : `
                    <span class="fas fa-user" style="color:#a69944;margin-right:6px;"></span>
                    <strong>${data.u_login ?? ''}</strong>`),
                allowReordering: false,
            },
            { field: 'u_fullname', headerText: 'Nombre completo', width: 220, minWidth: 50, allowFiltering: true, freeze: 'Left' },
            { field: 'u_email', headerText: 'Correo', width: 180, minWidth: 50, allowFiltering: true },
            { field: 'u_job', headerText: 'Cargo', width: 150, minWidth: 50, allowFiltering: true },
            { field: 'u_office_phone', headerText: 'Teléfono', width: 120, minWidth: 50, allowFiltering: true },
            { field: 'u_town', headerText: 'Ciudad', width: 120, minWidth: 50, allowFiltering: true },
            { field: 'state_nom', headerText: 'Departamento', width: 120, allowFiltering: true },
            { field: 'region_nom', headerText: 'Región', width: 80, allowFiltering: true },
            { field: 'country_code_iso', headerText: 'País', width: 50, allowFiltering: true },
            { field: 'u_datec', headerText: 'Fecha creación', type: 'date', width: 150, minWidth: 50, allowFiltering: true, format: { type: 'date', format: 'dd/MM/yyyy' }, textAlign: 'Right' },
            {
                field: 'u_admin',
                headerText: 'Admin',
                width: 100,
                freeze: 'Right',
                textAlign: 'Center',
                template: (data) => {
                    const isAdmin = String(data.u_admin) === '1';
                    const style = 'display:inline-block;padding:2px 10px;border-radius:9999px;font-size:11px;font-weight:600;'
                        + (isAdmin ? 'background:#e0e7ff;color:#4338ca;' : 'background:#f3f4f6;color:#6b7280;');
                    return `<span style="${style}">${isAdmin ? 'Sí' : 'No'}</span>`;
                },
                filterBarTemplate: templateAdminFilter,
                allowFiltering: true,
                allowReordering: false,
            },
            {
                field: 'u_status',
                headerText: 'Estado',
                width: 100,
                freeze: 'Right',
                textAlign: 'Center',
                template: (data) => {
                    const isActive = String(data.u_status) === '1';
                    const style = 'display:inline-block;padding:2px 10px;border-radius:9999px;font-size:11px;font-weight:600;'
                        + (isActive ? 'background:#dcfce7;color:#15803d;' : 'background:#fef3c7;color:#b45309;');
                    return `<span style="${style}">${isActive ? 'Activo' : 'Inactivo'}</span>`;
                },
                filterBarTemplate: templateEstadoFilter,
                allowFiltering: true,
                allowReordering: false,
            },
        ];
    }

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
        columns: autoFilterTemplates(buildColumns(), filterCtx, IDGRID),

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

    if (config.esAdmin) {
        const fichaModal = new UsuarioFichaModal({
            fichaUrl: config.fichaUrl,
            csrfToken,
            onSaved: () => grid.refresh(),
        });
        document.getElementById(IDGRID).addEventListener('click', (e) => {
            const link = e.target.closest('.usuario-ficha-link');
            if (link) fichaModal.abrir(link.dataset.usuarioId);
        });

        // /panel/usuarios?editar=ID (la antigua página de edición redirige aquí).
        const editar = new URLSearchParams(window.location.search).get('editar');
        if (editar) {
            history.replaceState(null, '', window.location.pathname);
            fichaModal.abrir(editar);
        }
    }

    document.getElementById('reloadGrid').addEventListener('click', () => {
        AdvancedGridUX.invalidateCache(grid);
        grid.refresh();
    });

    const selectorGrupos = new ColorMultiSelectDropdown('#gruposDropdown', config.grupos, {
        multiple: true,
        visibleCount: 2,
        initialSelected: [],
        onChange: (idsSeleccionados) => {
            currentIdsGrupo = idsSeleccionados;
            updateGridQuery();
        },
    });

    document.getElementById('resetGrid').addEventListener('click', () => {
        currentIdsGrupo = [];
        selectorGrupos.clear();

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
                grupo: currentIdsGrupo.slice().sort(),
            });
            if (signature === lastQuerySignature) return;
            lastQuerySignature = signature;

            let query = new Query();
            if (currentIdsGrupo.length > 0) {
                query = query.addParams('currentIdsGrupo', JSON.stringify(currentIdsGrupo));
            }

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
            await downloadFile(config.exportXlsxUrl, `Usuarios_${fechaActual}.xlsx`, {
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
            await downloadFile(config.exportCsvUrl, `Usuarios_${fechaActual}.csv`, {
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
