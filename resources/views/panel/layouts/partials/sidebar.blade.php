@php
    $navItems = [
        ['route' => 'panel.dashboard', 'active' => request()->routeIs('panel.dashboard'), 'icon' => 'fa-solid fa-gauge', 'label' => 'Mi tablero de soluciones'],
        ['route' => 'panel.solicitudes.index', 'active' => request()->routeIs('panel.solicitudes.*'), 'icon' => 'fa-solid fa-inbox', 'label' => 'Solicitudes'],
        ['route' => 'panel.localizacion.index', 'active' => request()->routeIs('panel.localizacion.*'), 'icon' => 'fa-solid fa-location-dot', 'label' => 'Localización'],
        ['route' => 'panel.cotizaciones.index', 'active' => request()->routeIs('panel.cotizaciones.*'), 'icon' => 'fa-solid fa-file-invoice-dollar', 'label' => 'Cotizaciones'],
        [
            'icon' => 'fa-solid fa-cash-register',
            'label' => 'Facturación',
            'items' => [
                ['route' => 'panel.facturas.index', 'active' => request()->routeIs('panel.facturas.index'), 'icon' => 'fa-solid fa-file-invoice-dollar', 'label' => 'Facturas'],
                ['route' => 'panel.facturas.proveedores.index', 'active' => request()->routeIs('panel.facturas.proveedores.index'), 'icon' => 'fa-solid fa-file-invoice', 'label' => 'Facturas Proveedor'],
            ],
        ],
        [
            'icon' => 'fa-solid fa-database',
            'label' => 'Maestros',
            'items' => [
                ['route' => 'panel.societe.terceros.index', 'active' => request()->routeIs('panel.societe.terceros.*'), 'icon' => 'fa-solid fa-users', 'label' => 'Terceros'],
                ['route' => 'panel.productos.index', 'active' => request()->routeIs('panel.productos.index'), 'icon' => 'fa-solid fa-cube', 'label' => 'Productos'],
                ['route' => 'panel.servicios.index', 'active' => request()->routeIs('panel.servicios.index'), 'icon' => 'fa-solid fa-concierge-bell', 'label' => 'Servicios'],
                ['route' => 'panel.usuarios.index', 'active' => request()->routeIs('panel.usuarios.*'), 'icon' => 'fa-solid fa-user-gear', 'label' => 'Usuarios'],
                ['route' => 'panel.categorias.index', 'active' => request()->routeIs('panel.categorias.*'), 'icon' => 'fa-solid fa-tags', 'label' => 'Etiquetas'],
            ],
        ],
    ];
@endphp

<nav class="sidebar-nav py-5">
    <div class="category-label px-6 pt-1 pb-2 text-[11px] font-semibold text-sidebar-foreground/45 uppercase tracking-wider">
        Panel
    </div>

    @foreach ($navItems as $item)
        @if (isset($item['items']))
            @php($abierto = collect($item['items'])->contains('active', true))
            <details class="menu-grupo group/grupo px-3 py-0.5" @if ($abierto) open @endif>
                <summary class="relative flex h-10 w-full cursor-pointer list-none items-center rounded-lg px-3 transition-colors duration-200 [&::-webkit-details-marker]:hidden
                                {{ $abierto ? 'text-sidebar-foreground font-semibold' : 'text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-foreground' }}">
                    <i class="{{ $item['icon'] }} w-5 flex-shrink-0 text-center {{ $abierto ? 'text-primary' : 'text-sidebar-foreground/45' }}"></i>
                    <span class="menu-text ml-3 flex-1 truncate text-left text-sm font-medium">{{ $item['label'] }}</span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-sidebar-foreground/45 transition-transform duration-200 group-open/grupo:rotate-180"></i>
                </summary>
                <div class="ml-5 mt-0.5 border-l border-sidebar-foreground/10 pl-2">
                    @foreach ($item['items'] as $sub)
                        <div class="menu-link relative py-0.5">
                            <a href="{{ route($sub['route']) }}"
                               class="relative flex h-9 w-full items-center rounded-lg px-3 transition-colors duration-200 group
                                      {{ $sub['active']
                                          ? 'bg-sidebar-primary text-sidebar-primary-foreground font-semibold'
                                          : 'text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-foreground' }}">
                                <i class="{{ $sub['icon'] }} w-5 flex-shrink-0 text-center text-[13px] {{ $sub['active'] ? 'text-sidebar-primary-foreground' : 'text-sidebar-foreground/45 group-hover:text-sidebar-primary-foreground' }}"></i>
                                <span class="menu-text ml-3 flex-1 truncate text-left text-sm font-medium">{{ $sub['label'] }}</span>
                            </a>
                        </div>
                    @endforeach
                </div>
            </details>
        @else
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
        @endif
    @endforeach
</nav>
