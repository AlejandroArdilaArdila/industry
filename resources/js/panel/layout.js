// Comportamiento del layout del panel, adaptado de sidebar.js/navbar.js del tema
// Cleopatra (github.com/moesaid/cleopatra, MIT). El render del menú se hace en
// Blade (server-side), aquí solo vive el comportamiento interactivo.

import { aplicar as aplicarMarca, coloresDelLogo, guardados as marcaGuardada } from './tema-marca.js';

const THEME_COLOR_KEY = 'panel-theme-color';
const THEME_MODE_KEY = 'panel-theme-mode';

function setColor(color) {
    document.documentElement.setAttribute('data-theme', color);
    localStorage.setItem(THEME_COLOR_KEY, color);
    updateColorButtonStyles(color);
}

function setMode(mode) {
    const isDark = mode === 'dark';
    document.documentElement.classList.toggle('dark', isDark);
    // e-dark-mode activa la variante oscura de los componentes Syncfusion
    // (grid, etc.), que ya viene incluida en su CSS Material3 vía variables.
    document.documentElement.classList.toggle('e-dark-mode', isDark);
    localStorage.setItem(THEME_MODE_KEY, mode);
    updateModeButtonStyles(mode);
}

function updateColorButtonStyles(activeColor) {
    document.querySelectorAll('.theme-color-btn').forEach((btn) => {
        btn.classList.toggle('ring-foreground', btn.dataset.themeColor === activeColor);
        btn.classList.toggle('ring-transparent', btn.dataset.themeColor !== activeColor);
    });
}

function updateModeButtonStyles(activeMode) {
    document.querySelectorAll('.theme-mode-btn').forEach((btn) => {
        const isActive = btn.dataset.mode === activeMode;
        btn.classList.toggle('bg-accent', isActive);
        btn.classList.toggle('text-accent-foreground', isActive);
        btn.classList.toggle('border-transparent', isActive);
        btn.classList.toggle('text-muted-foreground', !isActive);
        btn.classList.toggle('border-border', !isActive);
    });
}

function initThemeSwitcher() {
    const toggleBtn = document.getElementById('theme-toggle-btn');
    const dropdown = document.getElementById('theme-dropdown');
    const container = document.getElementById('theme-dropdown-container');
    if (!toggleBtn || !dropdown || !container) return;

    const savedColor = localStorage.getItem(THEME_COLOR_KEY) || 'neutral';
    const savedMode = localStorage.getItem(THEME_MODE_KEY) || 'light';
    setColor(savedColor);
    setMode(savedMode);

    toggleBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
        document.getElementById('user-dropdown')?.classList.add('hidden');
    });

    document.addEventListener('click', (e) => {
        if (!container.contains(e.target)) dropdown.classList.add('hidden');
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') dropdown.classList.add('hidden');
    });

    document.querySelectorAll('.theme-color-btn').forEach((btn) => {
        // Elegir un tema predefinido quita los colores de marca personalizados.
        btn.addEventListener('click', () => { setColor(btn.dataset.themeColor); aplicarMarca(null); pintarMarca(); });
    });
    initMarca();

    document.querySelectorAll('.theme-mode-btn').forEach((btn) => {
        btn.addEventListener('click', () => setMode(btn.dataset.mode));
    });
}

const CAMPOS_MARCA = ['primary', 'accent', 'sidebar'];

/** Valores de partida de los selectores: lo guardado o, si no hay, el color real que tiene el panel ahora. */
function pintarMarca() {
    const g = marcaGuardada();
    const css = getComputedStyle(document.documentElement);
    const hex = (v) => { const c = document.createElement('canvas').getContext('2d'); c.fillStyle = v.trim() || '#000000'; return c.fillStyle; };
    const actual = { primary: '--primary', accent: '--brand-accent', sidebar: '--sidebar' };
    CAMPOS_MARCA.forEach((k) => {
        const inp = document.querySelector(`[data-marca="${k}"]`);
        if (!inp) return;
        inp.value = g?.[k] ?? (k === 'accent' && !css.getPropertyValue(actual[k]).trim() ? '#f97316' : hex(css.getPropertyValue(actual[k])));
        const t = document.querySelector(`[data-marca-hex="${k}"]`);
        if (t) t.textContent = inp.value.toUpperCase();
    });
    document.querySelector('[data-marca-reset]')?.classList.toggle('hidden', !g);
}

function initMarca() {
    const aviso = document.querySelector('[data-marca-aviso]');
    const decir = (t, error = false) => { if (aviso) { aviso.textContent = t; aviso.classList.toggle('text-destructive', error); } };
    const leer = () => Object.fromEntries(CAMPOS_MARCA.map((k) => [k, document.querySelector(`[data-marca="${k}"]`).value]));

    document.querySelectorAll('[data-marca]').forEach((inp) => inp.addEventListener('input', () => { aplicarMarca(leer()); pintarMarca(); decir(''); }));
    document.querySelector('[data-marca-logo]')?.addEventListener('click', async (e) => {
        try {
            aplicarMarca(await coloresDelLogo(e.currentTarget.dataset.marcaLogo));
            pintarMarca();
            decir('Colores tomados del logo. Puedes ajustarlos.');
        } catch (err) {
            decir(err.message || 'No pude leer el logo.', true);
        }
    });
    document.querySelector('[data-marca-reset]')?.addEventListener('click', () => { aplicarMarca(null); pintarMarca(); decir('Colores del tema restablecidos.'); });
    document.getElementById('theme-toggle-btn')?.addEventListener('click', pintarMarca);
    pintarMarca();
}

function initUserDropdown() {
    const btn = document.getElementById('user-avatar-btn');
    const dropdown = document.getElementById('user-dropdown');
    const container = document.getElementById('user-dropdown-container');
    if (!btn || !dropdown || !container) return;

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
    });

    document.addEventListener('click', (e) => {
        if (!container.contains(e.target)) dropdown.classList.add('hidden');
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') dropdown.classList.add('hidden');
    });
}

let isCollapsed = false;
let isMobileOpen = false;

function updateMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    if (!sidebar) return;

    if (isMobileOpen) {
        sidebar.classList.remove('-translate-x-full');
        overlay?.classList.remove('hidden', 'opacity-0');
        overlay?.classList.add('opacity-100');
        document.body.classList.add('overflow-hidden');
    } else {
        sidebar.classList.add('-translate-x-full');
        overlay?.classList.add('opacity-0');
        setTimeout(() => overlay?.classList.add('hidden'), 200);
        document.body.classList.remove('overflow-hidden');
    }
}

function toggleSidebarCollapse() {
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    if (!sidebar) return;

    isCollapsed = !isCollapsed;
    sidebar.classList.toggle('is-collapsed', isCollapsed);
    sidebar.classList.toggle('w-[260px]', !isCollapsed);
    sidebar.classList.toggle('w-[72px]', isCollapsed);
    content?.classList.toggle('lg:ml-[260px]', !isCollapsed);
    content?.classList.toggle('lg:ml-[72px]', isCollapsed);
}

function initSidebarBehavior() {
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const mobileToggle = document.getElementById('mobile-menu-toggle');
    const overlay = document.getElementById('sidebar-overlay');

    sidebarToggle?.addEventListener('click', toggleSidebarCollapse);

    mobileToggle?.addEventListener('click', () => {
        isMobileOpen = !isMobileOpen;
        updateMobileSidebar();
    });

    overlay?.addEventListener('click', () => {
        isMobileOpen = false;
        updateMobileSidebar();
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 1024 && isMobileOpen) {
            isMobileOpen = false;
            updateMobileSidebar();
        }
    });

    // Acordeón de submenús (grupos de la barra lateral con hijos)
    document.querySelectorAll('.menu-toggle').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const menuItem = toggle.closest('.menu-item');
            const submenu = menuItem.querySelector('.submenu');
            const arrow = menuItem.querySelector('.menu-arrow');
            const isOpen = menuItem.classList.contains('is-open');

            document.querySelectorAll('.menu-item.is-open').forEach((openItem) => {
                if (openItem !== menuItem) {
                    openItem.classList.remove('is-open');
                    const sub = openItem.querySelector('.submenu');
                    const arr = openItem.querySelector('.menu-arrow');
                    if (sub) sub.style.maxHeight = '0';
                    arr?.classList.remove('rotate-45');
                }
            });

            if (isOpen) {
                menuItem.classList.remove('is-open');
                submenu.style.maxHeight = '0';
                arrow?.classList.remove('rotate-45');
            } else {
                menuItem.classList.add('is-open');
                submenu.style.maxHeight = submenu.scrollHeight + 'px';
                arrow?.classList.add('rotate-45');
            }
        });
    });

    // Abrir sin animación el submenú que contiene el ítem activo
    document.querySelectorAll('.menu-item.is-open .submenu').forEach((submenu) => {
        submenu.style.maxHeight = submenu.scrollHeight + 'px';
    });
}

/**
 * Tabs genéricas: cualquier contenedor [data-tabs] con botones
 * [data-tab-target="clave"] y paneles [data-tab-panel="clave"]. Usado por
 * las páginas de perfil (Datos/Contraseña/Extra/Grupos/Permisos).
 */
function initTabs() {
    document.querySelectorAll('[data-tabs]').forEach((container) => {
        const buttons = container.querySelectorAll('[data-tab-target]');
        const panels = container.querySelectorAll('[data-tab-panel]');

        buttons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const target = btn.dataset.tabTarget;

                buttons.forEach((b) => {
                    const isActive = b.dataset.tabTarget === target;
                    b.classList.toggle('bg-accent', isActive);
                    b.classList.toggle('text-accent-foreground', isActive);
                    b.classList.toggle('text-muted-foreground', !isActive);
                });
                panels.forEach((p) => {
                    p.classList.toggle('hidden', p.dataset.tabPanel !== target);
                });
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initThemeSwitcher();
    initUserDropdown();
    initSidebarBehavior();
    initTabs();
});

// ---------------------------------------------------------------- sesión vencida
// Si el servidor responde 401 (sin sesión) o 419 (token vencido) a una petición hecha desde la página
// (fetch o XHR, p. ej. las grillas), avisamos y recargamos: el servidor redirige al login con el motivo.
let avisandoSesion = false;

function sesionVencida() {
    if (avisandoSesion || document.querySelector('form[action$="/login"]')) return;
    avisandoSesion = true;
    const ir = () => window.location.reload();
    import('sweetalert2')
        .then(({ default: Swal }) => Swal.fire({
            icon: 'warning',
            title: 'Tu sesión se cerró',
            text: 'Por seguridad la sesión se cierra tras un tiempo sin actividad, o si se inició desde otro lugar. Inicia sesión de nuevo para continuar.',
            confirmButtonText: 'Ir a iniciar sesión',
            allowOutsideClick: false,
            allowEscapeKey: false,
        }))
        .then(ir, ir);
}

const mismoOrigen = (url) => {
    try { return new URL(url, window.location.href).origin === window.location.origin; } catch { return false; }
};

const fetchOriginal = window.fetch.bind(window);
window.fetch = async (input, init) => {
    const r = await fetchOriginal(input, init);
    if ((r.status === 401 || r.status === 419) && mismoOrigen(typeof input === 'string' ? input : input.url ?? String(input))) sesionVencida();
    return r;
};

const xhrSend = XMLHttpRequest.prototype.send;
XMLHttpRequest.prototype.send = function (...args) {
    this.addEventListener('loadend', () => {
        if ((this.status === 401 || this.status === 419) && mismoOrigen(this.responseURL)) sesionVencida();
    });
    return xhrSend.apply(this, args);
};

// Cerrar sesión siempre pide confirmación (el formulario del menú del usuario se envía solo si se acepta).
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !/\/logout$/.test(new URL(form.action, window.location.href).pathname) || form.dataset.confirmado) return;
    e.preventDefault();
    import('sweetalert2').then(({ default: Swal }) => Swal.fire({
        icon: 'question',
        title: '¿Cerrar sesión?',
        text: 'Saldrás del panel. Lo que tengas sin guardar se perderá.',
        showCancelButton: true,
        confirmButtonText: 'Sí, cerrar sesión',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
        focusCancel: true,
    })).then((r) => {
        if (!r.isConfirmed) return;
        form.dataset.confirmado = '1';
        form.submit();
    });
});
