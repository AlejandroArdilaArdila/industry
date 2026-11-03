import { DatePicker, DateRangePicker } from '@syncfusion/ej2-calendars';
import { DropDownList } from '@syncfusion/ej2-dropdowns';

/**
 * Plantillas de filterBarTemplate reutilizables para columnas de grids
 * Syncfusion. Port de libsIntegros/syncfusion/globalsFilters.js +
 * custom/integros/js/global.js del ERP Dolibarr, adaptado a módulos ES.
 */

function formatDateToQueryString(date) {
    if (!date || Number.isNaN(date.getTime())) return null;
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * Filtro de texto/número con input + selector de operador (contiene, empieza
 * con, igual...) + botón de limpiar. Requiere `ctx.grid` en scope.
 */
export function getFilterTemplate(field, type = 'string', ctx) {
    let inputRef = null;
    let btnRef = null;
    let clearBtnRef = null;
    let debounceTimer = null;

    ctx.customFilterFields[field] = true;

    const stringOps = [
        { value: 'contains', text: 'Contiene', icon: '<i class="fas fa-search"></i>' },
        { value: 'startswith', text: 'Empieza con', icon: '<i class="fas fa-indent"></i>' },
        { value: 'endswith', text: 'Termina con', icon: '<i class="fas fa-outdent"></i>' },
        { value: 'equal', text: 'Igual', icon: '<i class="fas fa-equals"></i>' },
        { value: 'notequal', text: 'No igual', icon: '<i class="fas fa-not-equal"></i>' },
    ];
    const numberOps = [
        { value: 'equal', text: 'Igual', icon: '<i class="fas fa-equals"></i>' },
        { value: 'greaterthan', text: 'Mayor que', icon: '<i class="fas fa-greater-than"></i>' },
        { value: 'lessthan', text: 'Menor que', icon: '<i class="fas fa-less-than"></i>' },
        { value: 'greaterthanorequal', text: 'Mayor o igual', icon: '<i class="fas fa-greater-than-equal"></i>' },
        { value: 'lessthanorequal', text: 'Menor o igual', icon: '<i class="fas fa-less-than-equal"></i>' },
    ];

    const ops = type === 'number' ? numberOps : stringOps;
    let currentOperator = ops[0].value;

    const btnStyle = `
        cursor:pointer; background:transparent; border:none; padding:0;
        font-size:0.65rem; height:18px; width:14px; min-width:0; flex-shrink:0;
        line-height:1; vertical-align:middle; text-align:center; outline:none;
        transition: color 0.2s; display:inline-flex;
        align-items:center; justify-content:center;
    `;

    function updateClearBtn() {
        if (!clearBtnRef) return;
        clearBtnRef.style.display = inputRef && inputRef.value.trim() ? 'inline-flex' : 'none';
    }

    function applyFilter() {
        const value = inputRef ? inputRef.value.trim() : '';
        clearTimeout(debounceTimer);
        ctx.setApplying(true);
        if (value) {
            ctx.grid.filterByColumn(field, currentOperator, value);
        } else {
            ctx.grid.removeFilteredColsByField(field);
        }
        updateClearBtn();
        setTimeout(() => ctx.setApplying(false), 50);
    }

    function clearFilter() {
        if (!inputRef) return;
        inputRef.value = '';
        clearTimeout(debounceTimer);
        ctx.setApplying(true);
        ctx.grid.removeFilteredColsByField(field);
        updateClearBtn();
        setTimeout(() => ctx.setApplying(false), 50);
        inputRef.focus();
    }

    function applyInputStyles() {
        inputRef.style.setProperty('border', 'none', 'important');
        inputRef.style.setProperty('border-bottom', '1px solid #ccc', 'important');
        inputRef.style.setProperty('border-radius', '0', 'important');
        inputRef.style.setProperty('box-shadow', 'none', 'important');
        inputRef.style.setProperty('outline', 'none', 'important');
        inputRef.style.setProperty('background', 'transparent', 'important');
        inputRef.style.setProperty('flex', '1', 'important');
        inputRef.style.setProperty('min-width', '0', 'important');
        inputRef.style.setProperty('height', '24px', 'important');
        inputRef.style.setProperty('width', 'calc(100% - 32px)', 'important');
        inputRef.style.setProperty('padding', '0 4px', 'important');
    }

    return {
        create() {
            inputRef = document.createElement('input');
            inputRef.className = 'e-input';
            return inputRef;
        },

        write() {
            if (!inputRef || !inputRef.parentNode) return;
            inputRef.placeholder = '';
            setTimeout(applyInputStyles, 0);

            inputRef.addEventListener('focus', () => {
                applyInputStyles();
                inputRef.style.setProperty('border-bottom', '2px solid #0891b2', 'important');
            });
            inputRef.addEventListener('blur', applyInputStyles);

            btnRef = document.createElement('button');
            btnRef.type = 'button';
            btnRef.innerHTML = ops[0].icon;
            btnRef.title = ops[0].text;
            btnRef.setAttribute('data-operator', currentOperator);
            btnRef.style.cssText = btnStyle + 'color:#888;';
            btnRef.onmouseenter = () => { btnRef.style.color = '#0891b2'; };
            btnRef.onmouseleave = () => { btnRef.style.color = '#888'; };

            clearBtnRef = document.createElement('button');
            clearBtnRef.type = 'button';
            clearBtnRef.innerHTML = '<i class="fas fa-times"></i>';
            clearBtnRef.title = 'Limpiar filtro';
            clearBtnRef.style.cssText = btnStyle + 'color:#bbb; display:none;';
            clearBtnRef.onmouseenter = () => { clearBtnRef.style.color = '#e53935'; };
            clearBtnRef.onmouseleave = () => { clearBtnRef.style.color = '#bbb'; };
            clearBtnRef.onclick = (e) => { e.preventDefault(); e.stopPropagation(); clearFilter(); };

            const dropdown = document.createElement('div');
            dropdown.classList.add('sf-filter-dropdown');
            dropdown.style.cssText = `
                display:none; position:fixed; z-index:99999; background:#fff;
                border:1px solid #d0d0d0; border-radius:6px;
                box-shadow:0 4px 12px rgba(0,0,0,.15); padding:4px 0; min-width:170px;
            `;

            ops.forEach((op, index) => {
                const item = document.createElement('div');
                item.style.cssText = 'padding:7px 14px; cursor:pointer; font-size:13px; white-space:nowrap; display:flex; align-items:center; gap:10px;';
                if (index === 0) item.style.fontWeight = 'bold';

                const iconSpan = document.createElement('span');
                iconSpan.innerHTML = op.icon;
                iconSpan.style.cssText = 'font-size:13px; min-width:18px; text-align:center; color:#555;';

                const textSpan = document.createElement('span');
                textSpan.textContent = op.text;

                item.appendChild(iconSpan);
                item.appendChild(textSpan);
                item.onmouseenter = () => { item.style.background = '#f0f4ff'; };
                item.onmouseleave = () => { item.style.background = ''; };
                item.onclick = (e) => {
                    e.stopPropagation();
                    currentOperator = op.value;
                    btnRef.innerHTML = op.icon;
                    btnRef.title = op.text;
                    btnRef.setAttribute('data-operator', currentOperator);
                    dropdown.querySelectorAll('div').forEach((d) => { d.style.fontWeight = 'normal'; });
                    item.style.fontWeight = 'bold';
                    dropdown.style.display = 'none';
                    clearTimeout(debounceTimer);
                    applyFilter();
                };
                dropdown.appendChild(item);
            });

            btnRef.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                document.querySelectorAll('.sf-filter-dropdown').forEach((d) => { d.style.display = 'none'; });
                const rect = btnRef.getBoundingClientRect();
                dropdown.style.top = `${rect.bottom + 2}px`;
                dropdown.style.left = `${rect.left}px`;
                dropdown.style.display = 'block';
            };

            document.addEventListener('click', (e) => {
                if (!dropdown.contains(e.target) && e.target !== btnRef) {
                    dropdown.style.display = 'none';
                }
            });

            document.body.appendChild(dropdown);

            ['keyup', 'keydown', 'keypress', 'input', 'change'].forEach((evt) => {
                inputRef.addEventListener(evt, (e) => e.stopImmediatePropagation());
            });
            inputRef.addEventListener('input', (e) => {
                e.stopImmediatePropagation();
                clearTimeout(debounceTimer);
                updateClearBtn();
                debounceTimer = setTimeout(applyFilter, 800);
            }, true);
            inputRef.addEventListener('keydown', (e) => {
                e.stopImmediatePropagation();
                if (e.key === 'Enter') { clearTimeout(debounceTimer); applyFilter(); }
            }, true);

            const btnCol = document.createElement('div');
            btnCol.style.cssText = `
                display:flex; flex-direction:column;
                align-items:center; justify-content:center;
                flex-shrink:0; width:14px; height:100%; gap:1px;
            `;
            btnCol.appendChild(btnRef);
            btnCol.appendChild(clearBtnRef);
            inputRef.parentNode.style.cssText = 'display:flex; align-items:stretch; gap:2px; width:100%; height:100%';
            inputRef.insertAdjacentElement('afterend', btnCol);
        },

        read() {},
    };
}

/**
 * Asigna filterBarTemplate automáticamente a columnas sin uno definido,
 * según el tipo de dato (string/number/date). Columnas con filterBarTemplate
 * ya definido, allowFiltering:false o sin campo se omiten.
 */
export function autoFilterTemplates(columns, filterCtx, idGrid, dateFormat = 'dd/MM/yyyy') {
    columns.forEach((col) => {
        if (!col.field || col.allowFiltering !== true || col.filterBarTemplate) return;
        if (col.filter && col.filter.type) return;
        const t = (col.type || 'string').toLowerCase();
        if (t === 'string') {
            col.filterBarTemplate = getFilterTemplate(col.field, 'string', filterCtx);
        } else if (t === 'number') {
            col.filterBarTemplate = getFilterTemplate(col.field, 'number', filterCtx);
        } else if (t === 'date') {
            col.filterBarTemplate = templateDateRangePickerSF({
                id: `${col.field}_auto_drp`,
                fieldName: col.field,
                idGrid,
                format: dateFormat,
                filterCtx,
            });
        }
    });
    return columns;
}

/** Filtro de fecha única (DatePicker) para columnas del grid. */
export function templateDatePickerSF({ id, fieldName, idGrid = 'Grid', format = 'dd/MM/yyyy', onFilter = null }) {
    return {
        create: () => {
            const input = document.createElement('input');
            input.id = id;
            return input;
        },
        write: (args) => {
            const grid = document.getElementById(idGrid).ej2_instances[0];
            const input = document.getElementById(id);

            const datepicker = new DatePicker({
                value: args?.filteredValue ? new Date(args.filteredValue) : null,
                format,
                change: (arg) => {
                    const dateValue = arg.value;
                    if (!dateValue) {
                        grid.removeFilteredColsByField(fieldName);
                        return;
                    }
                    if (typeof onFilter === 'function') {
                        onFilter(dateValue, fieldName, args, datepicker);
                    } else {
                        grid.filterByColumn(fieldName, 'equal', dateValue);
                    }
                },
            });
            datepicker.appendTo(input);
        },
        destroy: () => {
            const input = document.getElementById(id);
            input?.ej2_instances?.[0]?.destroy?.();
        },
    };
}

/** Filtro de rango de fechas (DateRangePicker) para columnas del grid. */
export function templateDateRangePickerSF({
    id, fieldName, idGrid = 'Grid', format = 'dd/MM/yyyy',
    defaultCurrentMonth = false, onFilter = null, filterCtx = null,
}) {
    const dispId = `${id}_disp`;

    function fmt(d) {
        if (!d) return '';
        const dd = String(d.getDate()).padStart(2, '0');
        const MM = String(d.getMonth() + 1).padStart(2, '0');
        const yyyy = d.getFullYear();
        return format.replace('dd', dd).replace('MM', MM).replace('yyyy', yyyy);
    }

    function renderDisplay(display, start, end, clearBtn) {
        if (!start || !end) {
            display.innerHTML = '<span style="color:#bbb;font-size:9px;font-style:italic;white-space:nowrap;">Selecciona rango</span>';
            if (clearBtn) clearBtn.style.display = 'none';
            return;
        }
        display.innerHTML = `
            <span style="display:block;font-size:9px;line-height:1.35;white-space:nowrap;overflow:hidden;">
                <span style="color:#888;">De&nbsp;</span>
                <span style="color:#0891b2;font-weight:600;">${fmt(start)}</span>
            </span>
            <span style="display:block;font-size:9px;line-height:1.35;white-space:nowrap;overflow:hidden;">
                <span style="color:#888;">A&nbsp;&nbsp;&nbsp;</span>
                <span style="color:#e65100;font-weight:600;">${fmt(end)}</span>
            </span>`;
        if (clearBtn) clearBtn.style.display = 'inline-flex';
    }

    return {
        create: () => {
            const wrap = document.createElement('div');
            wrap.id = `${id}_wrap`;
            wrap.style.cssText = 'display:flex; align-items:center; gap:2px; width:100%; height:100%; position:relative;';

            const input = document.createElement('input');
            input.id = id;
            input.style.cssText = 'position:absolute; width:1px; height:1px; opacity:0; pointer-events:none; border:none; padding:0; overflow:hidden;';
            wrap.appendChild(input);

            const display = document.createElement('div');
            display.id = dispId;
            display.style.cssText = 'flex:1; min-width:0; display:flex; flex-direction:column; justify-content:center;'
                + 'cursor:pointer; padding:0 2px; overflow:hidden; border-bottom:1px solid #e0e0e0;'
                + 'transition: border-color 0.15s;';
            display.innerHTML = '<span style="color:#bbb;font-size:9px;font-style:italic;white-space:nowrap;">Selecciona rango</span>';
            display.onmouseenter = () => { display.style.borderBottomColor = '#0891b2'; };
            display.onmouseleave = () => { display.style.borderBottomColor = '#e0e0e0'; };
            wrap.appendChild(display);

            return wrap;
        },

        write: (args) => {
            const grid = document.getElementById(idGrid).ej2_instances[0];
            const input = document.getElementById(id);
            const display = document.getElementById(dispId);
            const wrap = document.getElementById(`${id}_wrap`);

            let firstDayOfMonth = null;
            let lastDayOfMonth = null;
            if (defaultCurrentMonth) {
                const now = new Date();
                firstDayOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
                lastDayOfMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            }

            const hasInitialValue = args && args.filteredValue && args.filteredValue.length;

            const btnStyle = 'cursor:pointer; background:transparent; border:none; padding:0;'
                + 'font-size:0.65rem; height:18px; width:14px; min-width:0; flex-shrink:0;'
                + 'line-height:1; vertical-align:middle; text-align:center; outline:none;'
                + 'transition: color 0.2s; display:inline-flex;'
                + 'align-items:center; justify-content:center;';

            const calBtn = document.createElement('button');
            calBtn.type = 'button';
            calBtn.innerHTML = '<i class="fas fa-calendar-alt"></i>';
            calBtn.title = 'Seleccionar rango de fechas';
            calBtn.style.cssText = `${btnStyle}color:#888;`;
            calBtn.onmouseenter = () => { calBtn.style.color = '#0891b2'; };
            calBtn.onmouseleave = () => { calBtn.style.color = '#888'; };

            const clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.innerHTML = '<i class="fas fa-times"></i>';
            clearBtn.title = 'Limpiar filtro';
            clearBtn.style.cssText = `${btnStyle}color:#bbb; display:${hasInitialValue ? 'inline-flex' : 'none'};`;
            clearBtn.onmouseenter = () => { clearBtn.style.color = '#e53935'; };
            clearBtn.onmouseleave = () => { clearBtn.style.color = '#bbb'; };

            if (hasInitialValue) {
                renderDisplay(display, new Date(args.filteredValue[0]), new Date(args.filteredValue[1]), clearBtn);
            }

            const datepicker = new DateRangePicker({
                format,
                startDate: hasInitialValue ? new Date(args.filteredValue[0]) : firstDayOfMonth,
                endDate: hasInitialValue ? new Date(args.filteredValue[1]) : lastDayOfMonth,
                locale: 'es',
                change: (arg) => {
                    renderDisplay(display, arg.startDate, arg.endDate, clearBtn);

                    if (!arg.startDate || !arg.endDate) {
                        if (filterCtx) filterCtx.setApplying(true);
                        grid.filterSettings.columns = (grid.filterSettings.columns || []).filter((f) => f.field !== fieldName);
                        if (filterCtx) setTimeout(() => filterCtx.setApplying(false), 50);
                        return;
                    }
                    const correctedEnd = new Date(arg.endDate.getTime());
                    correctedEnd.setDate(correctedEnd.getDate() + 1);

                    const startStr = formatDateToQueryString(arg.startDate);
                    const endStr = formatDateToQueryString(correctedEnd);

                    if (typeof onFilter === 'function') {
                        onFilter(startStr, endStr, fieldName, args, datepicker);
                    } else {
                        if (filterCtx) filterCtx.setApplying(true);
                        const filters = (grid.filterSettings.columns || []).filter((f) => f.field !== fieldName);
                        filters.push(
                            { field: fieldName, operator: 'greaterthanorequal', value: startStr, type: 'string', predicate: 'and' },
                            { field: fieldName, operator: 'lessthan', value: endStr, type: 'string', predicate: 'and' },
                        );
                        grid.filterSettings.columns = filters;
                        if (filterCtx) setTimeout(() => filterCtx.setApplying(false), 50);
                    }
                },
            });

            datepicker.appendTo(input);

            const sfWrapper = input.parentNode;
            if (sfWrapper && sfWrapper !== wrap) {
                sfWrapper.style.cssText = 'position:absolute; width:1px; height:1px; overflow:hidden; opacity:0; pointer-events:none;';
            }

            display.onclick = (e) => { e.stopPropagation(); datepicker.show(); };
            calBtn.onclick = (e) => { e.preventDefault(); e.stopPropagation(); datepicker.show(); };

            clearBtn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                datepicker.startDate = null;
                datepicker.endDate = null;
                datepicker.dataBind();
                if (filterCtx) filterCtx.setApplying(true);
                grid.filterSettings.columns = (grid.filterSettings.columns || []).filter((f) => f.field !== fieldName);
                if (filterCtx) setTimeout(() => filterCtx.setApplying(false), 50);
                renderDisplay(display, null, null, clearBtn);
            };

            const btnCol = document.createElement('div');
            btnCol.style.cssText = 'display:flex; flex-direction:column;'
                + 'align-items:center; justify-content:center;'
                + 'flex-shrink:0; width:14px; height:100%; gap:1px;';
            btnCol.appendChild(calBtn);
            btnCol.appendChild(clearBtn);

            if (wrap) wrap.appendChild(btnCol);
        },

        read: () => {
            const input = document.getElementById(id);
            const instance = input?.ej2_instances?.[0];
            return instance ? [instance.startDate, instance.endDate] : null;
        },

        destroy: () => {
            const input = document.getElementById(id);
            input?.ej2_instances?.[0]?.destroy?.();
        },
    };
}

/** Filtro tipo DropDownList (select) para columnas del grid. */
export function templateDropDownListSF({
    id, fieldName, options = [], uppercase = false, idGrid = 'Grid', onFilter = null, initialValue = null,
}) {
    let savedValue = null;
    return {
        create: () => {
            const dropdown = document.createElement('select');
            dropdown.id = id;
            options.forEach((o) => {
                const opt = document.createElement('option');
                const optValue = o.value === null || o.value === '' ? '' : o.value;
                opt.value = optValue;
                opt.innerText = uppercase ? o.text.charAt(0).toUpperCase() + o.text.slice(1) : o.text;
                if (optValue === initialValue && initialValue !== null) {
                    opt.selected = true;
                }
                dropdown.appendChild(opt);
            });
            return dropdown;
        },
        write: (args) => {
            const grid = document.getElementById(idGrid).ej2_instances[0];
            const dropdown = document.getElementById(id);
            const valorInicial = savedValue !== null ? savedValue : (args.filteredValue ?? initialValue ?? '');

            const dropdownlist = new DropDownList({
                value: valorInicial,
                change: (arg) => {
                    const val = arg.value;
                    savedValue = val;
                    if (typeof onFilter === 'function') {
                        onFilter(val, fieldName, args);
                    } else if (val === '' || val === null) {
                        grid.removeFilteredColsByField(fieldName);
                    } else {
                        grid.filterByColumn(fieldName, 'equal', val);
                    }
                },
            });
            dropdownlist.appendTo(dropdown);
        },
        read: () => {
            const dropdown = document.getElementById(id);
            return dropdown?.ej2_instances?.[0]?.value ?? null;
        },
        destroy: () => {
            const dropdown = document.getElementById(id);
            dropdown?.ej2_instances?.[0]?.destroy?.();
        },
    };
}
