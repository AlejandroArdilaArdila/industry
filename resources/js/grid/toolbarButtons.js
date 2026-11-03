/**
 * Botón principal "Nuevo …" para la toolbar de los grids, con el color
 * primario del tema (las grids se mantienen claras también en modo oscuro,
 * así que se usa el primario claro, --grid-light-primary).
 */
export function toolbarBtnNuevo(id, texto) {
    const style = 'display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border:0;border-radius:6px;'
        + 'background:rgb(var(--grid-light-primary, 8, 145, 178));color:rgb(var(--grid-light-on-primary, 255, 255, 255));'
        + 'font-size:13px;font-weight:600;cursor:pointer;';
    return `<button type="button" id="${id}" title="${texto}" style="${style}"><i class="fa-solid fa-plus"></i> ${texto}</button>`;
}

/**
 * Título de la toolbar de una grid: icono en una pastilla con el primario
 * del tema y el nombre en negrita, con el color de texto de la grid (que se
 * mantiene clara también en modo oscuro).
 */
export function toolbarTitulo(icono, texto) {
    return '<span style="display:inline-flex;align-items:center;gap:10px;margin-right:6px;">'
        + '<span style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9px;'
        + 'background:rgba(var(--grid-light-primary, 8, 145, 178), 0.12);color:rgb(var(--grid-light-primary, 8, 145, 178));font-size:16px;">'
        + `<i class="fa-solid ${icono}"></i></span>`
        + `<span style="font-size:16px;font-weight:600;color:rgb(var(--color-sf-on-surface));white-space:nowrap;">${texto}</span>`
        + '</span>';
}

/**
 * En pantallas angostas las columnas congeladas (sobre todo las de la derecha)
 * se comen media grid: se descongelan antes de renderizar.
 */
export function quitarCongeladasEnMovil(grid) {
    if (!window.matchMedia('(max-width: 767px)').matches) return;
    grid.columns.forEach((c) => { delete c.freeze; delete c.isFrozen; });
}
