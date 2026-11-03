@extends('panel.layouts.app')

@section('title', 'Facturas')
@section('contentClass', 'h-[calc(100vh-4rem)]')

@section('content')
<div class="relative h-full">
    <div id="progresscontainer" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/30">
        <div class="h-10 w-10 animate-spin rounded-full border-4 border-white border-t-transparent"></div>
    </div>

    <div id="GridFacturas" class="e-grid e-lib e-responsive"></div>
</div>
@endsection

@section('scripts')
    <script>
        window.FACTURAS_CONFIG = {
            dataUrl: @json(route('panel.facturas.data')),
            exportXlsxUrl: @json(route('panel.facturas.export.xlsx')),
            exportCsvUrl: @json(route('panel.facturas.export.csv')),
        };
    </script>
    @vite(['resources/js/facturas/facturas-grid.js'])
@endsection
