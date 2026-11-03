{{--
    Pie de un paso del panel de ficha (ver resources/js/panel/ficha-modal.js).
    Espera del padre: $clave, $clavesPasos, $puedeEditar y opcionalmente
    $esNuevo (el primer paso crea el registro y desbloquea los demás).
--}}
@php
    $indice = array_search($clave, $clavesPasos, true);
    $esUltimo = $indice === count($clavesPasos) - 1;
    $creando = ($esNuevo ?? false) && $indice === 0;
    $textoGuardar = match (true) {
        $creando && $esUltimo => 'Crear',
        $creando => 'Crear y continuar',
        $esUltimo => 'Guardar y cerrar',
        default => 'Guardar y continuar',
    };
@endphp
<div class="h-8 flex-shrink-0"></div>
<div class="sticky bottom-0 -mx-5 mt-auto -mb-5 flex items-center gap-3 border-t border-border bg-card/95 px-5 py-3 backdrop-blur sm:-mx-7 sm:-mb-7 sm:px-7">
    @if ($indice > 0)
        <button type="button" data-step-prev class="inline-flex h-9 items-center gap-2 rounded-lg px-3 text-sm font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground">
            <i class="fa-solid fa-arrow-left text-xs"></i> Anterior
        </button>
    @endif

    <span data-step-status class="mr-auto ml-1 text-xs text-muted-foreground">Paso {{ $indice + 1 }} de {{ count($clavesPasos) }}</span>

    @if ($puedeEditar)
        <button type="button" data-step-discard class="hidden h-9 items-center rounded-lg px-3 text-sm font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground">
            Descartar
        </button>
        <button type="submit" data-step-save data-ultimo="{{ $esUltimo ? '1' : '0' }}" @if ($creando) data-crear="1" @endif
                class="inline-flex h-9 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow-sm transition hover:opacity-90 disabled:cursor-wait disabled:opacity-60">
            <span data-spinner class="hidden"><i class="fa-solid fa-circle-notch fa-spin"></i></span>
            <span data-label>{{ $textoGuardar }}</span>
            @unless ($esUltimo)<i class="fa-solid fa-arrow-right text-xs"></i>@endunless
        </button>
    @elseif (!$esUltimo)
        <button type="button" data-step-next class="inline-flex h-9 items-center gap-2 rounded-lg border border-border px-4 text-sm font-medium text-foreground transition hover:bg-muted">
            Siguiente <i class="fa-solid fa-arrow-right text-xs"></i>
        </button>
    @endif
</div>
