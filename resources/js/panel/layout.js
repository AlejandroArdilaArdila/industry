// Comportamiento del layout del panel, adaptado de sidebar.js/navbar.js del tema
// Cleopatra (github.com/moesaid/cleopatra, MIT). El render del menú se hace en
// Blade (server-side), aquí solo vive el comportamiento interactivo.

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
        btn.addEventListener('click', () => setColor(btn.dataset.themeColor));
    });

    document.querySelectorAll('.theme-mode-btn').forEach((btn) => {
        btn.addEventListener('click', () => setMode(btn.dataset.mode));
    });
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
