import '../../css/syncfusion-theme.css';
import '../../css/dashboard.css';
import {
    Grid, Toolbar, ColumnChooser, Filter, Page, Sort, Resize, Reorder,
} from '@syncfusion/ej2-grids';
import { DataManager, Query, UrlAdaptor } from '@syncfusion/ej2-data';
import Swal from 'sweetalert2';
import { quitarCongeladasEnMovil, toolbarTitulo } from '../grid/toolbarButtons.js';
import { autoFilterTemplates, templateDropDownListSF } from '../grid/filterTemplates.js';
import { AdvancedGridUX, activarTooltips } from '../grid/gridUx.js';
import {
    downloadFile, ensureCsvModal, showCsvModal, hideCsvModal, limpiarFiltroPersistente, cleanColumns,
} from '../grid/exportHelpers.js';
import { ActividadFichaModal } from '../panel/actividades-ficha.js';
import { SolicitudFichaModal } from './solicitud-ficha.js';
import { iniciarSolicitudesStats } from './solicitudes-stats.js';

const IDGRID = 'GridSolicitudes';
const ESTADO_INICIAL = '1'; // como el sistema anterior: por defecto solo las solicitudes activas

function toolbarBtn(id, icon, color, title, texto = '') {
    const style = `display:inline-flex;align-items:center;justify-content:center;gap:6px;height:32px;padding:0 ${texto ? 12 : 9}px;min-width:32px;font-size:13px;font-weight:600;`
        + `border:1px solid ${color};border-radius:6px;background:${texto ? color : '#fff'};color:${texto ? '#fff' : color};cursor:pointer;`;
    return `<button type="button" id="${id}" title="${title}" style="${style}"><i class="fa-solid ${icon}"></i>${texto}</button>`;
}

function getToolbarTemplate() {
    return [
        { template: toolbarTitulo('fa-inbox', 'Solicitudes'), align: 'Left' },
        { template: toolbarBtn('btnNuevaSolicitud', 'fa-plus', '#2563eb', 'Nueva solicitud', 'Nueva'), align: 'Right' },
        { template: toolbarBtn('btnDescargarExcel', 'fa-file-excel', '#198754', 'Exportar a Excel'), align: 'Right' },
        { template: toolbarBtn('btnModalCsv', 'fa-file-csv', '#0891b2', 'Exportar a CSV'), align: 'Right' },
        { template: toolbarBtn('reloadGrid', 'fa-sync-alt', '#b45309', 'Recargar'), align: 'Right' },
        { template: toolbarBtn('resetGrid', 'fa-recycle', '#dc2626', 'Restablecer filtros'), align: 'Right' },
    ];
}

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/** Colores de mora: verde hasta 15 días, ámbar hasta 30, rojo después. */
function moraHtml(dias) {
    const n = Number(dias) || 0;
    const [bg, fg] = n <= 15 ? ['#dcfce7', '#166534'] : n <= 30 ? ['#fef3c7', '#92400e'] : ['#fee2e2', '#991b1b'];
    return `<span style="background:${bg};color:${fg};padding:2px 8px;border-radius:999px;font-weight:600;font-size:12px;">${n} día${n === 1 ? '' : 's'}</span>`;
}

document.addEventListener('DOMContentLoaded', () => {
    const config = window.SOLICITUDES_CONFIG;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    Grid.Inject(Toolbar, ColumnChooser, Filter, Page, Sort, Resize, Reorder);

    // Mismo panel de seguimiento del tablero: al hacer clic en una solicitud se abre en media pantalla.
    const panel = new ActividadFichaModal({ panelUrl: config.panelUrl, csrfToken, onSaved: () => grid.refresh() });
    // Crear y editar la solicitud (datos, productos, detalle/correo y estado).
    const ficha = new SolicitudFichaModal({
        fichaUrl: config.fichaUrl, nuevoUrl: config.nuevaUrl, csrfToken, onSaved: () => { grid.refresh(); stats.cargar(true); },
    });
    let currentActividad = '';

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
            if (Array.isArray(payload.where) && payload.where.length === 0) delete payload.where;
            if (Array.isArray(payload.sorted) && payload.sorted.length === 0) delete payload.sorted;
            this.lastWhere = payload.where || [];
            this.lastSorted = payload.sorted || [];
            result.data = JSON.stringify(payload);
            return result;
        }

        processResponse(data) {
            const obj = typeof data === 'string' ? JSON.parse(data) : data;
            this.lastResponse = obj;
            const result = (obj.rowData || []).map((item) => ({
                ...item,
                t_rowid: Number(item.t_rowid),
                s_rowid: Number(item.s_rowid),
                mora: Number(item.mora),
            }));
            return { result, count: obj.count || 0 };
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
    const filterCtx = {
        get grid() { return grid; },
        customFilterFields,
        getApplying: () => applyingCustomFilter,
        setApplying: (v) => { applyingCustomFilter = v; },
    };

    const templateEstadoFilter = templateDropDownListSF({
        id: 'estado',
        fieldName: 'estado',
        options: [{ value: '', text: 'Todos' }, ...Object.entries(config.estados).map(([value, text]) => ({ value, text }))],
        idGrid: IDGRID,
        initialValue: ESTADO_INICIAL,
    });

    const templateEnvioFilter = templateDropDownListSF({
        id: 'envio_email',
        fieldName: 'envio_email',
        options: [{ value: '', text: 'Todos' }, { value: '1', text: 'Enviado' }, { value: '0', text: 'No enviado' }],
        idGrid: IDGRID,
    });

    const fechaCol = (field, headerText) => ({
        field, headerText, type: 'date', width: 130, minWidth: 50, allowFiltering: true, format: { type: 'date', format: 'dd/MM/yyyy' }, textAlign: 'Right',
    });

    const grid = new Grid({
        dataSource: dataManager,
        query: new Query(),
        locale: 'es',
        allowPaging: true,
        enableAltRow: true,
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
            columns: [{ field: 'estado', matchCase: false, operator: 'equal', predicate: 'and', value: ESTADO_INICIAL }],
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
            { field: 't_rowid', headerText: 'ID', width: 70, minWidth: 50, type: 'string', visible: false, allowReordering: false },
            {
                field: 'acciones',
                headerText: '',
                width: 84,
                minWidth: 84,
                freeze: 'Left',
                allowFiltering: false,
                allowSorting: false,
                allowReordering: false,
                textAlign: 'Center',
                template: (d) => `<button type="button" class="sol-ficha" data-id="${d.t_rowid}" title="Ver y editar la solicitud" style="border:0;background:none;color:#2563eb;cursor:pointer;padding:4px 6px;"><i class="fa-solid fa-pen-to-square"></i></button>`
                    + `<button type="button" class="sol-abrir" data-id="${d.t_rowid}" title="Seguimiento" style="border:0;background:none;color:#65953d;cursor:pointer;padding:4px 6px;"><i class="fa-solid fa-comments"></i></button>`,
            },
            {
                field: 't_ref',
                headerText: 'Ref',
                width: 150,
                minWidth: 50,
                freeze: 'Left',
                allowFiltering: true,
                allowReordering: false,
                template: (d) => `<a href="#" class="sol-ficha" data-id="${d.t_rowid}" style="color:inherit;text-decoration:none;">
                    <span class="fas fa-inbox" style="color:#65953d;margin-right:6px;"></span><strong>${esc(d.t_ref)}</strong></a>`,
            },
            {
                field: 's_nom',
                headerText: 'Cliente',
                width: 240,
                minWidth: 50,
                freeze: 'Left',
                allowFiltering: true,
                template: (d) => `<span class="fas fa-building" style="color:#6c6aa8;margin-right:6px;"></span>${esc(d.s_nom)}`,
            },
            fechaCol('fecha_solicitud', 'F.Solicitud'),
            {
                field: 'mora', headerText: 'Mora', width: 110, minWidth: 50, allowFiltering: true, type: 'number', textAlign: 'Center', template: (d) => moraHtml(d.mora),
            },
            { field: 'asunto', headerText: 'Asunto', width: 320, minWidth: 50, allowFiltering: true, template: (d) => `<div title="${esc(d.asunto)}" style="overflow:hidden;text-overflow:ellipsis;">${esc(d.asunto)}</div>` },
            fechaCol('fecha_proximo_contacto', 'Próx. contacto'),
            fechaCol('ultima_respuesta', 'Últ. respuesta'),
            fechaCol('date_creation', 'F.Creación'),
            {
                field: 'envio_email', headerText: 'Envío email', width: 130, minWidth: 50, allowFiltering: true, textAlign: 'Center', filterBarTemplate: templateEnvioFilter,
            },
            {
                field: 'creadoPor',
                headerText: 'Creado por',
                width: 200,
                minWidth: 50,
                allowFiltering: true,
                template: (d) => (d.creadoPor ? `<span class="fas fa-user" style="color:#65953d;margin-right:6px;"></span>${esc(d.creadoPor)}` : ''),
            },
            {
                field: 'estado',
                headerText: 'Estado',
                width: 240,
                allowFiltering: true,
                freeze: 'Right',
                allowReordering: false,
                filterBarTemplate: templateEstadoFilter,
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

    document.getElementById(IDGRID).addEventListener('click', (e) => {
        const a = e.target.closest('.sol-abrir, .sol-ficha');
        if (!a) return;
        e.preventDefault();
        if (a.classList.contains('sol-ficha')) ficha.abrir(Number(a.dataset.id));
        else panel.abrirDocumento('solicitud', Number(a.dataset.id));
    });
    // Tarjetas de arriba: elegir una filtra el listado por sus estados (y deja sin efecto el filtro «Estado» de la columna).
    const aplicarActividad = (actividad) => {
        currentActividad = actividad;
        if (actividad) {
            const lista = document.getElementById('estado')?.ej2_instances?.[0];
            if (lista?.value) lista.value = '';
        }
        grid.query = actividad ? new Query().addParams('actividad', actividad) : new Query();
        grid.refresh();
    };
    const stats = iniciarSolicitudesStats({ url: config.estadisticasUrl, onFiltro: ({ actividad }) => aplicarActividad(actividad) });

    document.getElementById('btnNuevaSolicitud').addEventListener('click', () => ficha.abrirNuevo());

    document.getElementById('reloadGrid').addEventListener('click', () => {
        AdvancedGridUX.invalidateCache(grid);
        grid.refresh();
        stats.cargar(true);
    });

    document.getElementById('resetGrid').addEventListener('click', () => {
        currentActividad = '';
        stats.limpiar();
        grid.query = new Query();
        grid.allowFiltering = false;
        grid.clearFiltering();
        grid.filterSettings.columns = [];
        grid.clearSorting();
        grid.searchSettings.key = '';
        grid.pageSettings.currentPage = 1;
        grid.allowFiltering = true;
    });

    const exportar = async (url, ext, extra = {}) => {
        const adaptor = document.getElementById(IDGRID).ej2_instances[0].dataSource?.adaptor;
        if (!(adaptor?.lastResponse?.count > 0)) {
            Swal.fire({ title: 'La consulta no trajo filas para exportar', icon: 'info' });
            return;
        }
        const fecha = new Date().toISOString().split('T')[0].replaceAll('-', '_');
        try {
            await downloadFile(url, `Solicitudes_${fecha}.${ext}`, {
                columnas: cleanColumns(grid.getColumns()).filter((c) => c.dataField !== 'acciones'),
                where: adaptor.lastWhere || [],
                sorted: adaptor.lastSorted || [],
                ...(currentActividad ? { actividad: currentActividad } : {}),
                ...extra,
            });
            Swal.fire({ title: 'Descarga exitosa', icon: 'success', timer: 2000 });
        } catch (e) {
            // downloadFile ya mostró el Swal de error
        }
    };

    document.getElementById('btnDescargarExcel').addEventListener('click', () => exportar(config.exportXlsxUrl, 'xlsx'));
    document.getElementById('btnModalCsv').addEventListener('click', () => {
        ensureCsvModal();
        showCsvModal();
    });
    document.addEventListener('click', (e) => {
        if (e.target.id !== 'btnDescargarCsv') return;
        const sep = document.getElementById('separadorCsv').value === '2' ? ',' : ';';
        hideCsvModal();
        exportar(config.exportCsvUrl, 'csv', { separador: sep });
    });
});
