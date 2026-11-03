@extends('panel.layouts.app')

@section('title', 'Usuarios')
@section('contentClass', 'h-[calc(100vh-4rem)]')

@section('content')
<div class="relative h-full">
    <div id="progresscontainer" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/30">
        <div class="h-10 w-10 animate-spin rounded-full border-4 border-white border-t-transparent"></div>
    </div>

    <div id="GridUsuarios" class="e-grid e-lib e-responsive"></div>
</div>
@endsection

@section('scripts')
    <script>
        window.USUARIOS_CONFIG = {
            dataUrl: @json(route('panel.usuarios.data')),
            exportXlsxUrl: @json(route('panel.usuarios.export.xlsx')),
            exportCsvUrl: @json(route('panel.usuarios.export.csv')),
            grupos: @json($grupos),
            esAdmin: @json($esAdmin),
            fichaUrl: @json(route('panel.usuarios.ficha', ['usuario' => '__ID__'])),
        };
    </script>
    @vite(['resources/js/usuarios/usuarios-grid.js'])
@endsection
