<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="solicitud-seguimiento" content="{{ json_encode(['ctx' => route('panel.solicitudes.seguimiento.contexto', '__ID__'), 'guardar' => route('panel.solicitudes.seguimiento.guardar', '__ID__'), 'ia' => route('panel.solicitudes.seguimiento.ia', '__ID__')]) }}">
    <title>@yield('title', config('app.name', 'Panel'))</title>
    <script>
        // Aplica el color/modo guardados antes de pintar, para evitar parpadeo.
        (function () {
            var html = document.documentElement;
            var color = localStorage.getItem('panel-theme-color');
            if (color) html.setAttribute('data-theme', color);
            if (localStorage.getItem('panel-theme-mode') === 'dark') html.classList.add('dark', 'e-dark-mode');
            try {
                var marca = JSON.parse(localStorage.getItem('panel-theme-brand-vars') || 'null');
                if (marca) Object.keys(marca).forEach(function (k) { html.style.setProperty(k, marca[k]); });
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/panel/layout.js'])
    @yield('styles')
</head>
<body class="min-h-screen bg-background font-sans text-foreground">
    @auth('web')
        <div id="app" class="min-h-screen">
            <header class="fixed top-0 left-0 right-0 h-16 bg-sidebar border-b border-sidebar-border z-50 flex items-center">
                <div class="w-[260px] h-full flex items-center px-5 border-r border-sidebar-border flex-shrink-0 hidden lg:flex">
                    <a href="{{ route('panel.dashboard') }}" class="flex items-center">
                        <img src="{{ config('app.company_logo_url') ?: asset('logo.png') }}" alt="{{ config('app.name') }}" class="h-10 w-auto max-w-[200px] object-contain">
                    </a>
                </div>

                <div class="flex items-center px-4 lg:hidden">
                    <button id="mobile-menu-toggle"
                        class="w-10 h-10 flex items-center justify-center text-sidebar-foreground/60 hover:text-sidebar-foreground hover:bg-sidebar-accent rounded-lg transition-colors mr-2">
                        <i class="fa-solid fa-bars text-xl"></i>
                    </button>
                    <a href="{{ route('panel.dashboard') }}" class="flex items-center">
                        <img src="{{ config('app.company_logo_url') ?: asset('logo.png') }}" alt="{{ config('app.name') }}" class="h-9 w-auto max-w-[160px] object-contain">
                    </a>
                </div>

                <div class="flex-1 h-full flex items-center px-4 lg:px-5">
                    <button id="sidebar-toggle"
                        class="hidden lg:flex w-8 h-8 items-center justify-center text-sidebar-foreground/60 hover:text-sidebar-foreground hover:bg-sidebar-accent rounded-lg transition-colors">
                        <i class="fa-solid fa-arrow-right-arrow-left text-lg"></i>
                    </button>

                    <div class="flex items-center gap-1 sm:gap-2 ml-auto">
                        <div class="relative" id="theme-dropdown-container">
                            <button id="theme-toggle-btn"
                                class="w-10 h-10 flex items-center justify-center text-sidebar-foreground/60 hover:text-sidebar-foreground hover:bg-sidebar-accent rounded-lg transition-colors">
                                <i class="fa-solid fa-palette text-xl"></i>
                            </button>

                            <div id="theme-dropdown"
                                class="absolute right-0 top-12 w-64 bg-popover text-popover-foreground rounded-xl shadow-xl border border-border z-50 hidden overflow-hidden">
                                <div class="p-3 border-b border-border">
                                    <h4 class="font-semibold text-sm">Tema</h4>
                                    <p class="text-xs text-muted-foreground">Personaliza tu interfaz</p>
                                </div>

                                <div class="p-3 border-b border-border">
                                    <p class="text-xs font-medium text-muted-foreground mb-2 uppercase tracking-wide">Color</p>
                                    <div class="grid grid-cols-5 gap-2" id="theme-colors">
                                        @foreach ([
                                            'neutral' => 'bg-gray-900',
                                            'blue' => 'bg-blue-600',
                                            'green' => 'bg-emerald-600',
                                            'orange' => 'bg-orange-600',
                                            'yellow' => 'bg-yellow-400',
                                            'stone' => 'bg-stone-600',
                                            'slate' => 'bg-slate-600',
                                            'sky' => 'bg-sky-600',
                                            'azure' => 'bg-indigo-600',
                                            'rose' => 'bg-rose-600',
                                        ] as $themeName => $swatchClass)
                                            <button type="button" data-theme-color="{{ $themeName }}" title="{{ ucfirst($themeName) }}"
                                                class="theme-color-btn w-8 h-8 rounded-full {{ $swatchClass }} ring-2 ring-offset-2 ring-offset-popover ring-transparent transition-all"></button>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="p-3 border-b border-border">
                                    <p class="text-xs font-medium text-muted-foreground mb-2 uppercase tracking-wide">Colores de marca</p>
                                    <div class="space-y-2">
                                        @foreach (['primary' => 'Botones y énfasis', 'accent' => 'Acento', 'sidebar' => 'Barra superior y lateral'] as $campo => $etiqueta)
                                            <label class="flex items-center justify-between gap-3 text-sm">
                                                <span>{{ $etiqueta }}</span>
                                                <span class="flex items-center gap-2">
                                                    <span class="font-mono text-[11px] text-muted-foreground" data-marca-hex="{{ $campo }}"></span>
                                                    <input type="color" data-marca="{{ $campo }}" class="h-7 w-9 cursor-pointer rounded border border-border bg-transparent p-0.5">
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="mt-3 flex gap-2">
                                        <button type="button" data-marca-logo="{{ asset('logo.png') }}"
                                            class="flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-lg bg-primary text-primary-foreground text-sm font-medium hover:opacity-90">
                                            <i class="fa-solid fa-wand-magic-sparkles"></i> Tomar del logo
                                        </button>
                                        <button type="button" data-marca-reset title="Volver a los colores del tema"
                                            class="hidden px-3 py-2 rounded-lg border border-border text-sm font-medium text-muted-foreground hover:bg-muted">
                                            <i class="fa-solid fa-rotate-left"></i>
                                        </button>
                                    </div>
                                    <p class="mt-2 text-xs text-muted-foreground" data-marca-aviso></p>
                                </div>

                                <div class="p-3">
                                    <p class="text-xs font-medium text-muted-foreground mb-2 uppercase tracking-wide">Modo</p>
                                    <div class="flex gap-2" id="theme-modes">
                                        <button type="button" data-mode="light"
                                            class="theme-mode-btn flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-border text-sm font-medium transition-colors">
                                            <i class="fa-solid fa-sun"></i> Claro
                                        </button>
                                        <button type="button" data-mode="dark"
                                            class="theme-mode-btn flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-border text-sm font-medium transition-colors">
                                            <i class="fa-solid fa-moon"></i> Oscuro
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="relative ml-1 sm:ml-2" id="user-dropdown-container">
                            <button id="user-avatar-btn"
                                class="w-9 h-9 rounded-full bg-sidebar-primary text-sidebar-primary-foreground text-sm font-semibold flex items-center justify-center ring-2 ring-primary/30">
                                {{ Str::of(auth('web')->user()->full_name)->explode(' ')->map(fn ($p) => Str::substr($p, 0, 1))->take(2)->join('') }}
                            </button>

                            <div id="user-dropdown"
                                class="absolute right-0 top-12 w-64 bg-popover text-popover-foreground rounded-xl shadow-xl border border-border z-50 hidden overflow-hidden">
                                <div class="p-4 border-b border-border">
                                    <h3 class="font-semibold truncate">{{ auth('web')->user()->full_name }}</h3>
                                    <p class="text-sm text-muted-foreground truncate">{{ auth('web')->user()->login }}</p>
                                </div>
                                <div class="p-2 border-b border-border">
                                    <a href="{{ route('panel.perfil.edit') }}"
                                        class="flex items-center gap-3 px-3 py-2 text-sm text-foreground rounded-lg hover:bg-muted transition-colors">
                                        <i class="fa-solid fa-user-pen text-muted-foreground"></i>
                                        Mi perfil
                                    </a>
                                    @if (auth('web')->user()->isAdmin())
                                        <a href="{{ route('panel.configuracion.index') }}"
                                            class="flex items-center gap-3 px-3 py-2 text-sm text-foreground rounded-lg hover:bg-muted transition-colors">
                                            <i class="fa-solid fa-gear text-muted-foreground"></i>
                                            Configuración
                                        </a>
                                    @endif
                                </div>
                                <div class="p-3">
                                    <form method="POST" action="{{ route('panel.logout') }}">
                                        @csrf
                                        <button type="submit"
                                            class="w-full px-4 py-2 text-sm font-medium text-muted-foreground border border-border rounded-lg hover:bg-muted hover:text-foreground transition-colors">
                                            Cerrar sesión
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <div id="sidebar-overlay"
                class="fixed inset-0 bg-black/50 z-40 hidden opacity-0 transition-opacity duration-300 lg:hidden"></div>

            <aside id="sidebar"
                class="sidebar fixed top-16 left-0 w-[260px] h-[calc(100vh-4rem)] bg-sidebar text-sidebar-foreground border-r border-sidebar-border z-40 transition-all duration-300 lg:translate-x-0 -translate-x-full overflow-y-auto">
                @include('panel.layouts.partials.sidebar')
            </aside>

            <main id="content" class="lg:ml-[260px] pt-16 min-h-screen transition-all duration-300">
                @hasSection('pageTitle')
                    <div class="bg-card border-b border-border px-4 sm:px-6 py-4 sm:py-5">
                        <h1 class="text-lg sm:text-xl font-semibold">@yield('pageTitle')</h1>
                        @hasSection('pageDescription')
                            <p class="text-sm text-muted-foreground mt-0.5">@yield('pageDescription')</p>
                        @endif
                    </div>
                @endif

                <div class="@yield('contentClass', 'p-4 sm:p-6')">
                    @yield('content')
                </div>
            </main>
        </div>
    @else
        @yield('content')
    @endauth

    @auth('web')
        <div id="trm-widget"
             data-user="{{ auth('web')->id() }}"
             data-url="{{ route('panel.trm.index') }}"
             data-url-historial="{{ route('panel.trm.historial') }}"
             data-url-config="{{ route('panel.trm.update') }}"
             data-url-cargar="{{ route('panel.trm.cargar') }}"
             data-csrf="{{ csrf_token() }}"></div>
        @vite('resources/js/panel/trm-widget.js')
    @endauth

    @yield('scripts')
</body>
</html>
