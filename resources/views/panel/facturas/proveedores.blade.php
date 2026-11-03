@extends('panel.layouts.app')

@section('title', 'Facturas de proveedor')
@section('contentClass', 'h-[calc(100vh-4rem)]')

@section('content')
<div class="relative h-full">
    <div id="progresscontainer" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/30">
        <div class="h-10 w-10 animate-spin rounded-full border-4 border-white border-t-transparent"></div>
    </div>

    <div id="GridFacturasProveedor" class="e-grid e-lib e-responsive"></div>
</div>
@endsection

@section('scripts')
    <script>
        window.FACTURAS_PROVEEDOR_CONFIG = {
            dataUrl: @json(route('panel.facturas.proveedores.data')),
            exportXlsxUrl: @json(route('panel.facturas.proveedores.export.xlsx')),
            exportCsvUrl: @json(route('panel.facturas.proveedores.export.csv')),
            condicionesPago: @json($condicionesPago),
        };
    </script>
    @vite(['resources/js/facturas/facturas-proveedores-grid.js'])
@endsection
