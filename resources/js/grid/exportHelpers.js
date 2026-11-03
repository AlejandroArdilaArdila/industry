import Swal from 'sweetalert2';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Descarga un archivo generado por el backend (xlsx/csv) vía fetch + Blob.
 * Reemplaza axiosDownloadFile del legado (que dependía de axios): usa la
 * sesión/cookie del panel (credentials same-origin) + token CSRF en vez de
 * DOLAPIKEY, ya que estas rutas viven bajo el guard de sesión del panel.
 */
export async function downloadFile(url, fileName, payload = {}) {
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                Accept: 'application/json, */*',
            },
            body: JSON.stringify(payload),
        });
    } catch (e) {
        Swal.fire({ html: 'Ocurrió un error al generar el documento, inténtelo nuevamente', icon: 'error', confirmButtonText: 'Confirmar' });
        throw new Error('Error de red al descargar el archivo');
    }

    const contentType = response.headers.get('content-type') || '';

    if (!response.ok || contentType.includes('application/json')) {
        let message = 'Error en la exportación.';
        try {
            const json = await response.json();
            message = json.message || message;
        } catch (e) { /* respuesta sin JSON legible */ }
        Swal.fire({ html: message, icon: response.ok ? 'info' : 'error', confirmButtonText: 'Confirmar' });
        throw new Error(message);
    }

    const blob = await response.blob();
    const href = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = href;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.URL.revokeObjectURL(href);
}

/** Crea (una sola vez) el modal Tailwind para elegir separador de CSV. */
export function ensureCsvModal() {
    if (document.getElementById('descargarCsvModal')) return;

    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
        <div id="descargarCsvModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
            <div class="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
                <h3 class="mb-4 text-base font-semibold text-gray-900">Descargar CSV</h3>
                <p class="mb-2 text-sm text-gray-600">Seleccione el separador para el archivo CSV</p>
                <select id="separadorCsv" class="mb-6 w-full rounded border border-gray-300 px-3 py-2 text-sm">
                    <option value="1" selected>Punto y coma (;)</option>
                    <option value="2">Coma (,)</option>
                </select>
                <div class="flex justify-end gap-2">
                    <button type="button" id="btnCerrarCsvModal" class="rounded border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="button" id="btnDescargarCsv" class="flex items-center gap-2 rounded bg-cyan-600 px-3 py-2 text-sm font-medium text-white hover:bg-cyan-700">
                        <i class="fa-solid fa-download"></i> Descargar
                    </button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(wrapper.firstElementChild);

    const modal = document.getElementById('descargarCsvModal');
    modal.addEventListener('click', (e) => { if (e.target === modal) hideCsvModal(); });
    document.getElementById('btnCerrarCsvModal').addEventListener('click', hideCsvModal);
}

export function showCsvModal() {
    const modal = document.getElementById('descargarCsvModal');
    modal?.classList.remove('hidden');
    modal?.classList.add('flex');
}

export function hideCsvModal() {
    const modal = document.getElementById('descargarCsvModal');
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

/** Limpia solo el filtro guardado en localStorage de un grid persistente. */
export function limpiarFiltroPersistente(gridId) {
    const storageKey = `grid${gridId}`;
    const persistedData = localStorage.getItem(storageKey);
    if (!persistedData) return;

    try {
        const data = JSON.parse(persistedData);
        if (data.filterSettings && data.filterSettings.columns) {
            data.filterSettings.columns = [];
            if (data.searchSettings) {
                data.searchSettings.key = '';
                data.searchSettings.fields = [];
            }
        }
        if (Array.isArray(data.columns)) {
            data.columns.forEach((col) => { if (col.filter) delete col.filter; });
        }
        localStorage.setItem(storageKey, JSON.stringify(data));
    } catch (e) {
        console.error('Error al parsear o modificar el JSON de persistencia:', e);
    }
}

/** Prepara las columnas visibles del grid para mandarlas al backend en export. */
export function cleanColumns(columns) {
    return columns
        .filter((col) => col.field !== 's_rowid')
        .map((col) => ({
            label: col.headerText,
            dataField: col.field,
            dataType: col.type,
            align: col.textAlign ?? null,
            cellsAlign: col.textAlign ?? null,
        }));
}
