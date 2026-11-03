@extends('panel.layouts.app')

@section('title', 'Terceros')
@section('contentClass', 'h-[calc(100vh-4rem)]')

@section('content')
<div class="relative flex h-full flex-col">
    <div id="progresscontainer" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/30">
        <div class="h-10 w-10 animate-spin rounded-full border-4 border-white border-t-transparent"></div>
    </div>

    <section id="terceros-stats" class="st flex-none" aria-label="Estadísticas de terceros">
        <div class="st-bar">
            <b><i class="fa-solid fa-chart-simple"></i> Resumen de terceros</b>
            <span data-hora></span>
            <span class="st-filtro" data-filtro><i class="fa-solid fa-filter"></i><span></span><button type="button" title="Quitar el filtro"><i class="fa-solid fa-xmark"></i></button></span>
            <button type="button" class="st-toggle" data-toggle title="Mostrar u ocultar las estadísticas"><i class="fa-solid fa-chevron-up"></i></button>
        </div>
        <div class="st-cuerpo"><div><div class="st-fila" id="terceros-stats-fila"></div></div></div>
    </section>

    <div class="relative min-h-0 flex-1">
        <div class="absolute inset-0">
            <div id="GridTerceros" class="e-grid e-lib e-responsive"></div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
    <script>
        window.TERCEROS_CONFIG = {
            dataUrl: @json(route('panel.societe.terceros.data')),
            estadisticasUrl: @json(route('panel.societe.terceros.estadisticas')),
            exportXlsxUrl: @json(route('panel.societe.terceros.export.xlsx')),
            exportCsvUrl: @json(route('panel.societe.terceros.export.csv')),
            fichaUrl: @json(route('panel.societe.terceros.ficha', ['tercero' => '__ID__'])),
            nuevoUrl: @json(route('panel.societe.terceros.nuevo')),
            puedeCrear: @json($puedeCrear),
            iaInicioUrl: @json(route('panel.societe.terceros.ia.inicio')),
            iaMensajeUrl: @json(route('panel.societe.terceros.ia.mensaje')),
            iaRutUrl: @json(route('panel.societe.terceros.ia.rut')),
            iaCrearUrl: @json(route('panel.societe.terceros.ia.crear')),
            categoriasCliente: @json($categoriasCliente),
            categoriasProveedor: @json($categoriasProveedor),
        };
    </script>
    @vite(['resources/js/societe/terceros-grid.js'])
@endsection
