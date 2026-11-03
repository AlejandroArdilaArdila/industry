@extends('panel.layouts.app')

@section('title', 'Etiquetas')
@section('contentClass', 'h-[calc(100vh-4rem)]')

@section('content')
<div class="relative h-full">
    <div id="GridCategorias" class="e-grid e-lib e-responsive"></div>
</div>
@endsection

@section('scripts')
    <script>
        window.CATEGORIAS_CONFIG = {
            dataUrl: @json(route('panel.categorias.data')),
            exportXlsxUrl: @json(route('panel.categorias.export.xlsx')),
            exportCsvUrl: @json(route('panel.categorias.export.csv')),
            fichaUrl: @json(route('panel.categorias.ficha', ['categoria' => '__ID__'])),
            nuevoUrl: @json(route('panel.categorias.nuevo')),
            tipos: @json($tipos),
            puedeCrear: @json($puedeCrear),
        };
    </script>
    @vite(['resources/js/categorias/categorias-grid.js'])
@endsection
