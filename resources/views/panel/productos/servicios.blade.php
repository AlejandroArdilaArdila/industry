@extends('panel.layouts.app')

@section('title', 'Servicios')
@section('contentClass', 'h-[calc(100vh-4rem)]')

@section('content')
<div class="relative h-full">
    <div id="progresscontainer" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/30">
        <div class="h-10 w-10 animate-spin rounded-full border-4 border-white border-t-transparent"></div>
    </div>

    <div id="{{ $gridId }}" class="e-grid e-lib e-responsive"></div>
</div>
@endsection

@section('scripts')
    <script>
        window.PRODUCTOS_CONFIG = {
            gridId: @json($gridId),
            type: @json($type),
            titulo: 'Servicios',
            icono: 'fa-concierge-bell',
            dataUrl: @json(route('panel.productos.data')),
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
