@extends('panel.layouts.app')

@section('title', 'Cotizaciones')
@section('contentClass', 'h-[calc(100vh-4rem)]')

@section('content')
<div class="relative flex h-full flex-col">
    {{-- Línea de proceso comercial (la del panel): estamos en el paso «Cotización» --}}
    <div id="d-proceso" class="pr pr-barra flex-none" aria-label="Proceso comercial"><div class="vacio"><i class="fa-solid fa-circle-notch fa-spin"></i></div></div>

    <div class="kb min-h-0 flex-1" id="cotizaciones-kb">
        <div class="kb-barra">
            <h2><i class="fa-solid fa-file-invoice-dollar"></i> Cotizaciones</h2>
            <label class="kb-buscar"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-kb-q placeholder="Buscar ref, cliente, título o solicitud" autocomplete="off"></label>
            <select class="kb-select" data-kb-estado aria-label="Filtrar por estado">
                <option value="">Validadas y enviadas</option>
                @foreach ($tablero['estados'] as $e)
                    <option value="{{ $e['id'] }}">{{ $e['nombre'] }}</option>
                @endforeach
            </select>
            <div class="espacio">
                <button type="button" class="kb-btn" data-kb-recargar title="Recargar"><i class="fa-solid fa-rotate"></i></button>
            </div>
        </div>
        <div class="kb-cuerpo" data-kb-cuerpo></div>
    </div>
</div>
@endsection

@section('scripts')
    <script>
        window.COTIZACIONES_CONFIG = {
            columnas: @json($tablero['columnas']),
            columnaUrl: @json(route('panel.cotizaciones.tablero.columna', '__COL__')),
            seguimientoUrl: @json(route('panel.actividades.store')),
            panelUrl: @json(route('panel.actividades.panel')),
            procesoUrl: @json(route('panel.dashboard.proceso')),
            procesoDocsUrl: @json(route('panel.dashboard.proceso.documentos', ['etapa' => '__E__'])),
        };
    </script>
    @vite(['resources/js/cotizaciones/cotizaciones.js'])
@endsection
