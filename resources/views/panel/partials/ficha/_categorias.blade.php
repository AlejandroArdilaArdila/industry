{{--
    Selector de categorías de una ficha (ver resources/js/panel/selector-categorias.js).
    Espera: $categorias (colección de {id,label,color,parent}), $seleccionadas (ids),
    $name (nombre del campo, p. ej. "categorias[]"), $disabled, $labelClass,
    $inputClass y opcionalmente $titulo y $vacio (texto si no hay categorías).
--}}
<div data-selector-categorias>
    @if ($categorias->isEmpty())
        <p class="rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">
            {{ $vacio ?? 'No hay categorías creadas.' }}
            <a href="{{ route('panel.categorias.index') }}" target="_blank" class="font-medium text-primary hover:underline">Crear en Etiquetas <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i></a>
        </p>
    @else
        <p class="{{ $labelClass }}">{{ $titulo ?? 'Asignadas' }}</p>
        <div data-categorias-seleccionadas class="mb-3 flex min-h-11 flex-wrap content-start gap-2 rounded-xl border border-dashed border-border p-2"></div>

        <div class="relative mb-3">
            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"></i>
            <input type="search" data-filtro-categorias placeholder="Buscar entre {{ number_format($categorias->count(), 0, ',', '.') }} categorías…" class="{{ $inputClass }} pl-9" @disabled($disabled)>
        </div>
        <div data-categorias-lista class="grid max-h-[22rem] grid-cols-1 gap-1 overflow-y-auto rounded-xl border border-border p-2 sm:grid-cols-2 {{ $disabled ? 'pointer-events-none opacity-60' : '' }}"></div>
        <p data-categorias-info class="mt-2 text-xs text-muted-foreground"></p>

        <script type="application/json" data-categorias-json>{!! json_encode(['categorias' => $categorias->values(), 'seleccionadas' => $seleccionadas, 'disabled' => $disabled, 'name' => $name], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
</div>
