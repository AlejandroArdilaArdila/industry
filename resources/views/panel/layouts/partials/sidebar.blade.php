@php
    $navItems = [
        [
            'route' => 'panel.dashboard',
            'active' => request()->routeIs('panel.dashboard'),
            'icon' => 'fa-solid fa-gauge',
            'label' => 'Dashboard',
        ],
        [
            'route' => 'panel.solicitudes.index',
            'active' => request()->routeIs('panel.solicitudes.*'),
            'icon' => 'fa-solid fa-inbox',
            'label' => 'Solicitudes',
        ],
        [
            'route' => 'panel.societe.terceros.index',
            'active' => request()->routeIs('panel.societe.terceros.*'),
            'icon' => 'fa-solid fa-users',
            'label' => 'Terceros',
        ],
        [
            'route' => 'panel.usuarios.index',
            'active' => request()->routeIs('panel.usuarios.*'),
            'icon' => 'fa-solid fa-user-gear',
            'label' => 'Usuarios',
        ],
        [
            'route' => 'panel.productos.index',
            'active' => request()->routeIs('panel.productos.index'),
            'icon' => 'fa-solid fa-cube',
            'label' => 'Productos',
        ],
        [
            'route' => 'panel.servicios.index',
            'active' => request()->routeIs('panel.servicios.index'),
            'icon' => 'fa-solid fa-concierge-bell',
            'label' => 'Servicios',
        ],
        [
            'route' => 'panel.categorias.index',
            'active' => request()->routeIs('panel.categorias.*'),
            'icon' => 'fa-solid fa-tags',
            'label' => 'Etiquetas',
        ],
        [
            'route' => 'panel.facturas.index',
            'active' => request()->routeIs('panel.facturas.index'),
            'icon' => 'fa-solid fa-file-invoice-dollar',
            'label' => 'Facturas',
        ],
        [
            'route' => 'panel.facturas.proveedores.index',
            'active' => request()->routeIs('panel.facturas.proveedores.index'),
            'icon' => 'fa-solid fa-file-invoice',
            'label' => 'Facturas Proveedor',
        ],
    ];
@endphp

<nav class="sidebar-nav py-5">
    <div class="category-label px-6 pt-1 pb-2 text-[11px] font-semibold text-sidebar-foreground/45 uppercase tracking-wider">
        Panel
    </div>

    @foreach ($navItems as $item)
        <div class="menu-link relative px-3 py-0.5">
            <a href="{{ route($item['route']) }}"
               class="relative w-full h-10 flex items-center px-3 rounded-lg transition-colors duration-200 group
                      {{ $item['active']
                          ? 'bg-sidebar-primary text-sidebar-primary-foreground font-semibold before:absolute before:-left-3 before:top-2 before:bottom-2 before:w-1 before:rounded-r-full before:bg-primary'
                          : 'text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-foreground' }}">
                <i class="{{ $item['icon'] }} w-5 flex-shrink-0 text-center {{ $item['active'] ? 'text-sidebar-primary-foreground' : 'text-sidebar-foreground/45 group-hover:text-sidebar-primary-foreground' }}"></i>
                <span class="menu-text flex-1 text-left text-sm font-medium ml-3 truncate">{{ $item['label'] }}</span>
            </a>
        </div>
    @endforeach
</nav>
