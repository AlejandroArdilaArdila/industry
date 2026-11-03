@extends('panel.layouts.app')

@section('title', 'Productos')
@section('contentClass', 'h-[calc(100vh-4rem)]')

@section('content')
<div class="relative flex h-full flex-col">
    <div id="progresscontainer" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/30">
        <div class="h-10 w-10 animate-spin rounded-full border-4 border-white border-t-transparent"></div>
    </div>

    <section id="productos-stats" class="st flex-none" aria-label="Estadísticas de productos">
        <div class="st-bar">
            <b><i class="fa-solid fa-chart-simple"></i> Resumen de productos</b>
            <span data-hora></span>
            <span class="st-filtro" data-filtro><i class="fa-solid fa-filter"></i><span></span><button type="button" title="Quitar el filtro"><i class="fa-solid fa-xmark"></i></button></span>
            <button type="button" class="st-toggle" data-toggle title="Mostrar u ocultar las estadísticas"><i class="fa-solid fa-chevron-up"></i></button>
        </div>
        <div class="st-cuerpo"><div><div class="st-fila" id="productos-stats-fila"></div></div></div>
    </section>

    <div class="relative min-h-0 flex-1">
        <div class="absolute inset-0">
            <div id="{{ $gridId }}" class="e-grid e-lib e-responsive"></div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
    <script>
        window.PRODUCTOS_CONFIG = {
            gridId: @json($gridId),
            type: @json($type),
            titulo: 'Productos',
            icono: 'fa-cube',
            dataUrl: @json(route('panel.productos.data')),
            estadisticasUrl: @json(route('panel.productos.estadisticas')),
            exportXlsxUrl: @json(route('panel.productos.export.xlsx')),
            exportCsvUrl: @json(route('panel.productos.export.csv')),
            categorias: @json($categorias),
            fichaUrl: @json(route('panel.productos.ficha', ['producto' => '__ID__'])),
            nuevoUrl: @json(route('panel.productos.nuevo')),
            puedeCrear: @json($puedeCrear),
        };
    </script>
    @vite(['resources/js/productos/productos-grid.js'])
@endsection
