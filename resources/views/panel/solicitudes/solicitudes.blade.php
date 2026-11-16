@extends('panel.layouts.app')

@section('title', 'Solicitudes')
@section('contentClass', 'h-[calc(100vh-4rem)]')

@section('content')
<div class="relative flex h-full flex-col">
    <div id="progresscontainer" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/30">
        <div class="h-10 w-10 animate-spin rounded-full border-4 border-white border-t-transparent"></div>
    </div>

    {{-- Línea de proceso comercial (la del panel): estamos en el paso «Solicitud» --}}
    <div id="d-proceso" class="pr pr-barra flex-none" aria-label="Proceso comercial"><div class="vacio"><i class="fa-solid fa-circle-notch fa-spin"></i></div></div>

    <section id="solicitudes-stats" class="st flex-none" aria-label="Estadísticas de solicitudes">
        <div class="st-bar">
            <b><i class="fa-solid fa-chart-simple"></i> Resumen de solicitudes</b>
            <span data-hora></span>
            <span class="st-filtro" data-filtro><i class="fa-solid fa-filter"></i><span></span><button type="button" title="Quitar el filtro"><i class="fa-solid fa-xmark"></i></button></span>
            <button type="button" class="st-toggle" data-toggle title="Mostrar u ocultar las estadísticas"><i class="fa-solid fa-chevron-up"></i></button>
        </div>
        <div class="st-cuerpo"><div><div class="st-fila" id="solicitudes-stats-fila"></div></div></div>
    </section>

    <div class="relative min-h-0 flex-1">
        <div id="vistaTablero" class="kb absolute inset-0">
            <div class="kb-barra">
                <h2><i class="fa-solid fa-table-columns"></i> Solicitudes</h2>
                <label class="kb-buscar"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-kb-q placeholder="Buscar ref, cliente o asunto" autocomplete="off"></label>
                <select class="kb-select" data-kb-estado aria-label="Filtrar por estado">
                    <option value="">Todos los estados</option>
                    @foreach ($tablero['estados'] as $e)
                        @if ($e['id'] !== 9)<option value="{{ $e['id'] }}">{{ $e['nombre'] }}</option>@endif
                    @endforeach
                </select>
                <div class="kb-vista kb-fechas" role="group" aria-label="Filtrar por fecha de creación" data-kb-fechas>
                    <button type="button" data-rango="" class="on">Todo</button>
                    <button type="button" data-rango="1">Último mes</button>
                    <button type="button" data-rango="3">3 meses</button>
                    <button type="button" data-rango="6">6 meses</button>
                    <button type="button" data-rango="rango">Rango</button>
                </div>
                <div class="kb-rango" data-kb-rango hidden>
                    <input type="date" data-kb-desde aria-label="Desde"><span>–</span><input type="date" data-kb-hasta aria-label="Hasta">
                </div>
                <div class="espacio">
                    <button type="button" class="kb-btn" data-kb-recargar title="Recargar"><i class="fa-solid fa-rotate"></i></button>
                    @if (auth('web')->user()?->isAdmin())
                        <button type="button" class="kb-btn" data-kb-config title="Configurar las columnas: estados y condiciones de cada grupo"><i class="fa-solid fa-sliders"></i></button>
                    @endif
                    <button type="button" class="kb-btn asistente" data-kb-asistente title="Crear la solicitud conversando: cliente, contacto, elemento, historial y análisis con IA"><i class="fa-solid fa-wand-magic-sparkles"></i>Crear con asistente</button>
                    <button type="button" class="kb-btn primario" data-kb-nueva><i class="fa-solid fa-plus"></i>Nueva</button>
                    <div class="kb-vista" role="group" aria-label="Vista">
                        <button type="button" data-vista="tablero" class="on"><i class="fa-solid fa-table-columns"></i>Tablero</button>
                        <button type="button" data-vista="tabla"><i class="fa-solid fa-table-list"></i>Tabla</button>
                    </div>
                </div>
            </div>
            <div class="kb-cuerpo" data-kb-cuerpo></div>
        </div>
        <div id="vistaTabla" class="absolute inset-0 vista-oculta">
            <div class="absolute inset-0 flex flex-col">
                <div class="kb-vista" style="align-self:flex-end;margin:6px 12px 0" role="group" aria-label="Vista">
                    <button type="button" data-vista="tablero"><i class="fa-solid fa-table-columns"></i>Tablero</button>
                    <button type="button" data-vista="tabla" class="on"><i class="fa-solid fa-table-list"></i>Tabla</button>
                </div>
                <div class="relative min-h-0 flex-1"><div class="absolute inset-0"><div id="GridSolicitudes" class="e-grid e-lib e-responsive"></div></div></div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
    <script>
        window.SOLICITUDES_CONFIG = {
            dataUrl: @json(route('panel.solicitudes.data')),
            estadisticasUrl: @json(route('panel.solicitudes.estadisticas')),
            exportXlsxUrl: @json(route('panel.solicitudes.export.xlsx')),
            exportCsvUrl: @json(route('panel.solicitudes.export.csv')),
            fichaUrl: @json(route('panel.solicitudes.ficha', '__ID__')),
            nuevaUrl: @json(route('panel.solicitudes.nueva')),
            panelUrl: @json(route('panel.actividades.panel')),
            procesoUrl: @json(route('panel.dashboard.proceso')),
            procesoDocsUrl: @json(route('panel.dashboard.proceso.documentos', ['etapa' => '__E__'])),
            estados: @json($estados),
            asistente: {
                clientesUrl: @json(route('panel.solicitudes.asistente.clientes')),
                clienteUrl: @json(route('panel.solicitudes.asistente.cliente', '__ID__')),
                historialUrl: @json(route('panel.solicitudes.asistente.historial')),
                correoContextoUrl: @json(route('panel.solicitudes.asistente.correo.contexto')),
                correoRedactarUrl: @json(route('panel.solicitudes.asistente.correo.redactar')),
                interpretarUrl: @json(route('panel.solicitudes.asistente.interpretar')),
                analizarUrl: @json(route('panel.solicitudes.asistente.analizar')),
                promptUrl: @json(route('panel.solicitudes.asistente.prompt')),
                productosUrl: @json(route('panel.solicitudes.productos')),
                storeUrl: @json(route('panel.solicitudes.store')),
            },
            tablero: {
                columnas: @json($tablero['columnas']),
                estados: @json($tablero['estados']),
                estadosTablero: @json($tablero['estadosTablero']),
                columnasUrl: @json(route('panel.solicitudes.tablero.columnas.guardar')),
                columnaUrl: @json(route('panel.solicitudes.tablero.columna', '__COL__')),
                motivosUrl: @json(route('panel.solicitudes.tablero.motivos')),
                moverUrl: @json(route('panel.solicitudes.mover', '__ID__')),
                urgencias: @json($tablero['urgencias']),
                urgenciaUrl: @json(route('panel.solicitudes.urgencia', '__ID__')),
                seguimientoUrl: @json(route('panel.actividades.store')),
                seguimientoUrls: {
                    ctx: @json(route('panel.solicitudes.seguimiento.contexto', '__ID__')),
                    guardar: @json(route('panel.solicitudes.seguimiento.guardar', '__ID__')),
                    ia: @json(route('panel.solicitudes.seguimiento.ia', '__ID__')),
                },
            },
        };
    </script>
    @vite(['resources/js/solicitudes/solicitudes-grid.js'])
@endsection
