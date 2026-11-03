@extends('panel.layouts.app')

@section('title', 'Configuración')
@section('pageTitle', 'Configuración')
@section('pageDescription', 'Tu perfil y los parámetros de inteligencia artificial del panel (solo administradores)')

@section('content')
@php
    $tabBtn = 'cfg-tab px-4 py-2 text-sm font-medium rounded-lg text-muted-foreground hover:bg-muted transition-colors';
    $subBtn = 'cfg-sub px-3 py-1.5 text-[13px] font-medium rounded-full border border-border text-muted-foreground hover:bg-muted transition-colors';
    $abrirPerfil = session('status') || $errors->any();
@endphp

<div id="cfg" data-abrir-perfil="{{ $abrirPerfil ? '1' : '0' }}">
    <div class="mb-4 flex flex-wrap gap-2 border-b border-border pb-3">
        <button type="button" data-cfg-tab="perfil" class="{{ $tabBtn }}"><i class="fa-solid fa-user-pen mr-1.5"></i>Mi perfil</button>
        <button type="button" data-cfg-tab="panel" class="{{ $tabBtn }}"><i class="fa-solid fa-table-columns mr-1.5"></i>Panel</button>
        <button type="button" data-cfg-tab="ia" class="{{ $tabBtn }}"><i class="fa-solid fa-wand-magic-sparkles mr-1.5"></i>Parámetros IA</button>
    </div>

    {{-- Mi perfil: datos, contraseña, grupos y permisos --}}
    <div data-cfg-panel="perfil" class="hidden">
        @include('panel.perfil._contenido', $perfil)
    </div>

    {{-- Panel: quién ve qué en el dashboard --}}
    <div data-cfg-panel="panel" class="hidden space-y-4">
        <div id="cfg-panel" class="rounded-xl border border-border bg-card p-5 shadow-sm"></div>
        <div id="cfg-proceso" class="rounded-xl border border-border bg-card p-5 shadow-sm"></div>
    </div>

    {{-- Parámetros IA --}}
    <div data-cfg-panel="ia" class="hidden">
        <div class="mb-4 flex flex-wrap gap-2">
            <button type="button" data-cfg-sub="proveedores" class="{{ $subBtn }}"><i class="fa-solid fa-key mr-1"></i>Proveedores y tokens</button>
            <button type="button" data-cfg-sub="terceros" class="{{ $subBtn }}"><i class="fa-solid fa-building mr-1"></i>Terceros</button>
            <button type="button" data-cfg-sub="solicitudes" class="{{ $subBtn }}"><i class="fa-solid fa-inbox mr-1"></i>Solicitudes</button>
            <button type="button" data-cfg-sub="productos" class="{{ $subBtn }}"><i class="fa-solid fa-cube mr-1"></i>Productos</button>
            <button type="button" data-cfg-sub="cotizaciones" class="{{ $subBtn }}"><i class="fa-solid fa-file-invoice-dollar mr-1"></i>Cotizaciones</button>
        </div>

        <div data-cfg-subpanel="proveedores" class="hidden rounded-xl border border-border bg-card p-5 shadow-sm">
            <div id="cfg-proveedores"></div>
        </div>

        <div data-cfg-subpanel="terceros" class="hidden rounded-xl border border-border bg-card p-5 shadow-sm">
            <div id="cfg-terceros"></div>
        </div>

        <div data-cfg-subpanel="solicitudes" class="hidden rounded-xl border border-border bg-card p-5 shadow-sm">
            <div id="cfg-solicitudes"></div>
        </div>

        @foreach (['productos' => 'Productos', 'cotizaciones' => 'Cotizaciones'] as $k => $n)
            <div data-cfg-subpanel="{{ $k }}" class="hidden rounded-xl border border-dashed border-border bg-card p-10 text-center text-sm text-muted-foreground">
                <i class="fa-solid fa-hourglass-half mb-3 block text-2xl opacity-60"></i>
                El asistente de IA para <b>{{ $n }}</b> estará disponible próximamente. Usará los mismos proveedores y tokens configurados aquí.
            </div>
        @endforeach
    </div>
</div>

<script type="application/json" id="cfg-datos">{!! json_encode([
    'ia' => $ia,
    'panel' => $panel,
    'terceros' => $terceros,
    'promptsSolicitud' => $promptsSolicitud,
    'catalogo' => $catalogoTerceros,
    'tiposDato' => $tiposDato,
    'urls' => $urls,
    'csrf' => csrf_token(),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
@endsection

@section('scripts')
    @vite('resources/js/panel/configuracion.js')
@endsection
