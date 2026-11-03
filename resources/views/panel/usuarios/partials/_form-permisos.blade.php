<form method="POST" action="{{ route('panel.usuarios.permisos', $usuario) }}" class="space-y-3">
    @csrf
    @method('PUT')

    <p class="text-xs text-muted-foreground">
        Permisos directos del usuario, agrupados por módulo de Dolibarr. Los permisos heredados de
        un grupo (pestaña Grupos) no se muestran aquí.
    </p>

    <div class="space-y-2">
        @foreach ($permisosAgrupados as $modulo => $permisos)
            <details class="rounded-lg border border-border">
                <summary class="cursor-pointer select-none px-4 py-2.5 text-sm font-medium text-foreground hover:bg-muted">
                    {{ $modulo ?: 'Sin módulo' }}
                    <span class="ml-2 text-xs text-muted-foreground">({{ $permisos->count() }})</span>
                </summary>
                <div class="grid grid-cols-1 gap-2 border-t border-border p-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($permisos as $permiso)
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <span class="relative inline-flex h-5 w-9 flex-shrink-0 items-center">
                                <input type="checkbox" name="permisos[]" value="{{ $permiso->id }}"
                                       @checked(in_array((int) $permiso->id, $permisosDe, true))
                                       class="peer sr-only">
                                <span class="absolute inset-0 rounded-full bg-muted transition-colors peer-checked:bg-primary"></span>
                                <span class="absolute left-0.5 h-4 w-4 rounded-full bg-background shadow transition-transform peer-checked:translate-x-4"></span>
                            </span>
                            <span>{{ $permiso->libelle }}</span>
                        </label>
                    @endforeach
                </div>
            </details>
        @endforeach
    </div>

    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90">
        Guardar permisos
    </button>
</form>
