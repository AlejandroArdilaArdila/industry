@extends('panel.layouts.app')

@section('title', 'Solicitudes')
@section('contentClass', 'h-[calc(100vh-4rem)]')

@section('content')
<div class="relative flex h-full flex-col">
    <div id="progresscontainer" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/30">
        <div class="h-10 w-10 animate-spin rounded-full border-4 border-white border-t-transparent"></div>
    </div>

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
        <div class="absolute inset-0">
            <div id="GridSolicitudes" class="e-grid e-lib e-responsive"></div>
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
            estados: @json($estados),
        };
    </script>
    @vite(['resources/js/solicitudes/solicitudes-grid.js'])
@endsection
