@extends('panel.layouts.app')

@section('title', 'Panel')
@section('contentClass', 'p-0')

@section('content')
<div id="dash" class="dash relative min-h-[calc(100vh-4rem)] overflow-x-clip">
    <div class="dash-aurora" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-[1600px] px-4 pt-6 pb-10 sm:px-6 min-[1900px]:max-w-[2000px] min-[2400px]:max-w-[2600px] min-[3000px]:max-w-[3200px]">

        {{-- Vista de negociadores ($soloComercial): la cabecera (saludo, fechas y periodo) se conserva SIEMPRE; solo se ocultan los
             insights y los KPIs generales, con style inline (las clases no bastan: .dash-insights define su propio display).
             Siguen en el DOM porque dashboard.js los pinta. --}}
        {{-- Cabecera --}}
        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl" id="d-saludo">Hola, {{ $user->full_name }}</h1>
                <p class="mt-1 text-sm text-muted-foreground" id="d-subtitulo">&nbsp;</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="dash-seg" id="d-periodos" role="tablist">
                    @foreach ($periodos as $clave => $texto)
                        <button type="button" data-periodo="{{ $clave }}" class="{{ $clave === '30' ? 'on' : '' }}">{{ $texto }}</button>
                    @endforeach
                </div>
                <select id="d-vendedor" class="dash-select hidden" aria-label="Vendedor"></select>
                <span class="dash-live" id="d-live"><i></i><span id="d-live-txt">Cargando…</span></span>
                <button type="button" id="d-auto" class="dash-btn" title="Actualizar cada 60 s"><i class="fa-solid fa-bolt"></i> Auto</button>
                <button type="button" id="d-refrescar" class="dash-btn" title="Actualizar (R)"><i class="fa-solid fa-rotate"></i> Actualizar</button>
            </div>
        </div>

        <div id="d-insights" class="dash-insights" @if ($soloComercial) style="display:none" @endif></div>

        {{-- KPIs --}}
        {{-- Bloques SUSPENDIDOS: siguen en el DOM (dashboard.js los pinta por su id) pero ocultos. Para reactivar uno, cambia su variable a true. --}}
        @php
            $mostrarKpis = false;            // barra de KPIs generales
            $mostrarLeyenda = false;         // «Métricas de la leyenda» (y no se consulta al servidor)
            $mostrarCalorProductos = false;  // «Calendario de ventas» + «Productos más vendidos»
        @endphp
        <div id="d-kpis" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6" @if ($soloComercial || ! $mostrarKpis) style="display:none" @endif></div>

        {{-- Línea de proceso (solo negociadores): total y fecha más antigua por etapa, desde INFORMESSOLU_ANIO_DESDE --}}
        @if ($soloComercial)
            <div id="d-proceso" class="pr mb-4"><div class="vacio"><i class="fa-solid fa-circle-notch fa-spin"></i></div></div>
        @endif

        {{-- KPIs del tablero comercial (mismos del tablero de Dolibarr) --}}
        <div class="mt-5 mb-2 flex flex-wrap items-baseline gap-2">
            <h2 class="text-sm font-bold"><i class="fa-solid fa-gauge-high text-primary"></i> Tablero comercial</h2>
            <small class="text-xs text-muted-foreground" id="d-tab-nota"></small>
            @if ($soloComercial && $user->isAdmin())
                <a href="{{ route('panel.dashboard', ['completo' => 1]) }}" class="dash-btn dash-btn-sm ml-auto" title="Eres administrador: abre el panel con todos los indicadores"><i class="fa-solid fa-expand"></i> Ver panel completo</a>
            @endif
            @if ($user->isAdmin())
                <button type="button" class="dash-btn dash-btn-sm {{ $soloComercial ? '' : 'ml-auto' }}" data-colores title="Cambiar los colores de las cards (para todos los usuarios)"><i class="fa-solid fa-palette"></i> Colores</button>
            @endif
        </div>
        {{-- Tarjetas del tablero comercial comentadas a pedido (se pintaban desde dashboard-etapas.js, pintarKpisTablero):
        <div id="d-tab-kpis" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5"></div>
        --}}

        {{-- Flujo comercial (pestañas por etapa) + programador de actividades --}}
        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
            <section class="dash-card et-card xl:col-span-2" id="d-etapas-card" style="--i:6">
                <header class="dash-h">
                    <h2><i class="fa-solid fa-layer-group text-primary"></i> Flujo comercial <small id="d-et-sub">&nbsp;</small></h2>
                    <div class="flex items-center gap-2">
                        @if ($user->isAdmin())
                            <button type="button" class="dash-ib" data-colores title="Cambiar los colores de las cards (para todos los usuarios)"><i class="fa-solid fa-palette"></i></button>
                            <div class="dash-seg dash-seg-sm" id="d-et-alcance"><button type="button" data-a="todos" class="on">Todos</button><button type="button" data-a="mios">Míos</button></div>
                        @endif
                        <div class="dash-seg dash-seg-sm hidden" id="d-et-metrica"><button type="button" data-m="cantidad" class="on">Cantidad</button><button type="button" data-m="valor">Valor</button></div>
                        <button type="button" id="d-et-pausa" class="dash-ib" title="Pausar la rotación automática"><i class="fa-solid fa-pause"></i></button>
                    </div>
                </header>
                <div class="et-tabs" id="d-et-tabs" role="tablist" aria-label="Etapas del flujo comercial"></div>
                <div id="d-et-cuerpo">
                    <div class="et-stats" id="d-et-stats"></div>
                    <div class="et-main">
                        <div class="et-izq">
                            <div class="relative h-[250px] min-w-0"><canvas id="c-etapa"></canvas></div>
                            <div class="et-est" id="d-et-est">
                                <div class="et-est-h"><i class="fa-solid fa-hourglass-half"></i><b>Antigüedad de los documentos activos</b><small id="d-et-est-n"></small></div>
                                <div class="relative min-h-[170px] flex-1"><canvas id="c-estados"></canvas></div>
                                <div class="et-est-ap" id="d-et-est-b"></div>
                            </div>
                        </div>
                        <div class="et-docs">
                            <div class="et-docs-h">
                                <div class="et-salud" id="d-et-salud"></div>
                                <label class="et-busca"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="d-et-q" placeholder="Buscar referencia o cliente" autocomplete="off"></label>
                            </div>
                            <div class="et-lista" id="d-et-docs"></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="dash-card ag-card" id="d-act-card" style="--i:7">
                <header class="dash-h">
                    <h2><i class="fa-solid fa-calendar-check text-primary"></i> Programador de actividades</h2>
                    <div class="ag-ms hidden" id="d-act-ms"></div>
                    <button type="button" class="dash-btn dash-btn-pri" data-act-nueva><i class="fa-solid fa-plus"></i> Programar</button>
                </header>
                {{-- Una sola barra: los chips de color son los filtros (atrasadas, hoy, próximas, realizadas) y a la derecha el selector semana / mes. --}}
                <div class="ag-barra">
                    <div class="ag-resumen" id="d-act-resumen"></div>
                    <div class="ag-modo" id="d-act-modo"></div>
                </div>
                <div class="ag-fila" id="d-act-fila">
                    <button type="button" class="ag-flecha" data-sem="-1" id="d-act-prev" title="Anterior" aria-label="Anterior"><i class="fa-solid fa-chevron-left"></i></button>
                    <div class="ag-semana" id="d-act-semana"></div>
                    <button type="button" class="ag-flecha" data-sem="1" id="d-act-next" title="Siguiente" aria-label="Siguiente"><i class="fa-solid fa-chevron-right"></i></button>
                </div>
                <div class="ag-lista" id="d-act-lista"><div class="ag-vacio"><i class="fa-solid fa-circle-notch fa-spin"></i></div></div>
            </section>
        </div>

        {{-- Métricas de la leyenda del tablero --}}
        <div class="mt-4" @if (! $mostrarLeyenda) style="display:none" @endif>
            <div class="mb-2 flex flex-wrap items-baseline gap-2"><h2 class="text-sm font-bold"><i class="fa-solid fa-chart-pie text-primary"></i> Métricas de la leyenda</h2><small class="text-xs text-muted-foreground" id="d-ley-nota"></small></div>
            <div id="d-leyenda" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"></div>
        </div>

        {{-- 12 meses + clientes --}}
        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
            <section class="dash-card xl:col-span-2" style="--i:8">
                <header class="dash-h"><h2><i class="fa-solid fa-chart-column text-primary"></i> Últimos 12 meses <small>ventas netas y facturas</small></h2></header>
                <div class="relative h-[280px]"><canvas id="c-meses"></canvas></div>
            </section>
            <section class="dash-card" style="--i:9">
                <header class="dash-h"><h2><i class="fa-solid fa-trophy text-amber-500"></i> Mejores clientes</h2></header>
                <div id="d-clientes" class="dash-rank"></div>
            </section>
        </div>

        {{-- Calendario de calor + productos --}}
        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-3" @if (! $mostrarCalorProductos) style="display:none" @endif>
            <section class="dash-card xl:col-span-2" style="--i:10">
                <header class="dash-h"><h2><i class="fa-solid fa-fire text-orange-500"></i> Calendario de ventas <small>últimas 26 semanas</small></h2></header>
                <div class="overflow-x-auto pb-1"><div id="d-heat" class="dash-heat"></div></div>
                <div class="dash-heat-leg"><span>Menos</span><i data-n="0"></i><i data-n="1"></i><i data-n="2"></i><i data-n="3"></i><i data-n="4"></i><span>Más</span></div>
            </section>
            <section class="dash-card" style="--i:11">
                <header class="dash-h"><h2><i class="fa-solid fa-cube text-primary"></i> Productos más vendidos</h2></header>
                <div id="d-productos" class="dash-rank"></div>
            </section>
        </div>
    </div>
</div>

<script type="application/json" id="d-inicial">{!! json_encode($inicial, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
<script type="application/json" id="d-config">{!! json_encode([
    'url' => route('panel.dashboard.data'),
    'urlEtapas' => route('panel.dashboard.etapas'),
    'urlDocumentos' => route('panel.dashboard.etapa.documentos', ['etapa' => '__E__']),
    'urlLeyenda' => $mostrarLeyenda ? route('panel.dashboard.leyenda') : null,
    'urlProceso' => route('panel.dashboard.proceso'),
    'urlProcesoDocs' => route('panel.dashboard.proceso.documentos', ['etapa' => '__E__']),
    'urlColores' => $user->isAdmin() ? route('panel.dashboard.colores') : null,
    'urlActividades' => route('panel.actividades.index'),
    'urlPanelActividades' => route('panel.actividades.panel'),
    'urlTareaForm' => route('panel.tareas.formulario'),
    'urlTareaShow' => route('panel.tareas.show', ['tarea' => '__ID__']),
    'urlTareaEstado' => route('panel.tareas.estado', ['tarea' => '__ID__']),
    'urlTareaEliminar' => route('panel.tareas.destroy', ['tarea' => '__ID__']),
    'urlMicrosoft' => route('panel.microsoft.estado'),
    'urlMicrosoftConectar' => route('panel.microsoft.conectar'),
    'urlMicrosoftPrefs' => route('panel.microsoft.preferencias'),
    'urlMicrosoftSync' => route('panel.microsoft.sincronizar'),
    'urlMicrosoftEventos' => route('panel.microsoft.eventos'),
    'urlMicrosoftEventoEliminar' => route('panel.microsoft.evento.eliminar'),
    'urlMicrosoftEventoRechazar' => route('panel.microsoft.evento.rechazar'),
    'urlTareaRechazar' => route('panel.tareas.rechazar', ['tarea' => '__ID__']),
    'urlMicrosoftBase' => route('panel.microsoft.desconectar'),
    'csrf' => csrf_token(),
    'esAdmin' => (bool) $user->isAdmin(),
    'soloComercial' => (bool) $soloComercial,
    'usuario' => $user->firstname ?: $user->full_name,
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
@endsection

@section('scripts')
    @vite('resources/js/panel/dashboard.js')
@endsection
