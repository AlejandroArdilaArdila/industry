@php
    $tabBtn = 'px-4 py-2 text-sm font-medium rounded-lg text-muted-foreground hover:bg-muted transition-colors';
@endphp

<div class="rounded-xl border border-border bg-card p-6 shadow-sm">
    @if (session('status'))
        <div class="mb-4 rounded-lg border border-success/30 bg-success/10 px-4 py-2 text-sm text-success">
            {{ session('status') }}
        </div>
    @endif

    <div data-tabs>
        <div class="mb-6 flex flex-wrap gap-2 border-b border-border pb-3">
            <button type="button" data-tab-target="datos" class="{{ $tabBtn }} bg-accent text-accent-foreground">Datos personales</button>
            <button type="button" data-tab-target="password" class="{{ $tabBtn }}">Contraseña</button>
            @if ($extrafieldDefs->isNotEmpty())
                <button type="button" data-tab-target="extra" class="{{ $tabBtn }}">Campos adicionales</button>
            @endif
            @if ($esAdmin)
                <button type="button" data-tab-target="grupos" class="{{ $tabBtn }}">Grupos</button>
                <button type="button" data-tab-target="permisos" class="{{ $tabBtn }}">Permisos</button>
            @endif
        </div>

        <form method="POST" action="{{ route('panel.perfil.update') }}">
            @csrf
            @method('PUT')

            <div data-tab-panel="datos">
                @include('panel.usuarios.partials._form-datos')
            </div>

            @if ($extrafieldDefs->isNotEmpty())
                <div data-tab-panel="extra" class="hidden">
                    @include('panel.usuarios.partials._form-extrafields')
                </div>
            @endif

            <div class="mt-6 border-t border-border pt-4">
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90">
                    Guardar cambios
                </button>
            </div>
        </form>

        <div data-tab-panel="password" class="hidden">
            @include('panel.usuarios.partials._form-password')
        </div>

        @if ($esAdmin)
            <div data-tab-panel="grupos" class="hidden">
                @include('panel.usuarios.partials._form-grupos')
            </div>
            <div data-tab-panel="permisos" class="hidden">
                @include('panel.usuarios.partials._form-permisos')
            </div>
        @endif
    </div>
</div>
