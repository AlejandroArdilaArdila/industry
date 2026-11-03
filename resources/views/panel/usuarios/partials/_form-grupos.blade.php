<form method="POST" action="{{ route('panel.usuarios.grupos', $usuario) }}" class="space-y-4">
    @csrf
    @method('PUT')

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($grupos as $grupo)
            <label class="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm">
                <input type="checkbox" name="grupos[]" value="{{ $grupo->rowid }}"
                       @checked(in_array((int) $grupo->rowid, $gruposDe, true))
                       class="h-4 w-4 rounded border-border text-primary focus:ring-primary">
                {{ $grupo->nom }}
            </label>
        @empty
            <p class="text-sm text-muted-foreground">No hay grupos de usuario creados en Dolibarr.</p>
        @endforelse
    </div>

    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90">
        Guardar grupos
    </button>
</form>
