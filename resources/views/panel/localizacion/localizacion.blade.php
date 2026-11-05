@extends('panel.layouts.app')

@section('title', 'Localización')
@section('contentClass', 'h-[calc(100vh-4rem)]')

@section('content')
<div class="relative flex h-full flex-col">
    {{-- Línea de proceso comercial (la del panel): estamos en el paso «Localización» --}}
    <div id="d-proceso" class="pr pr-barra flex-none" aria-label="Proceso comercial"><div class="vacio"><i class="fa-solid fa-circle-notch fa-spin"></i></div></div>

    <div class="loc kb min-h-0 flex-1">
        <div class="kb-barra">
            <h2><i class="fa-solid fa-location-dot"></i> Localización</h2>
            <label class="kb-buscar"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-kb-q placeholder="Buscar ref, cliente o asunto" autocomplete="off"></label>
            <select class="kb-select" data-kb-estado aria-label="Filtrar por estado">
                <option value="">Todos los estados</option>
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
        window.LOCALIZACION_CONFIG = {
            columnas: @json($tablero['columnas']),
            columnaUrl: @json(route('panel.localizacion.tablero.columna', '__COL__')),
            seguimientoUrl: @json(route('panel.actividades.store')),
            solicitudUrl: @json(route('panel.localizacion.solicitud', '__ID__')),
            sugeridosUrl: @json(route('panel.localizacion.sugeridos')),
            proveedoresUrl: @json(route('panel.localizacion.proveedores')),
            proveedorUrl: @json(route('panel.localizacion.proveedor', '__ID__')),
            redactarUrl: @json(route('panel.localizacion.redactar', '__ID__')),
            enviarUrl: @json(route('panel.localizacion.enviar', '__ID__')),
            contextoUrl: @json(route('panel.localizacion.contexto')),
            promptUrl: @json(route('panel.localizacion.prompt')),
            fichaUrl: @json(route('panel.solicitudes.ficha', '__ID__')),
            panelUrl: @json(route('panel.actividades.panel')),
            procesoUrl: @json(route('panel.dashboard.proceso')),
            procesoDocsUrl: @json(route('panel.dashboard.proceso.documentos', ['etapa' => '__E__'])),
        };
    </script>
    @vite(['resources/js/localizacion/localizacion.js'])
@endsection
