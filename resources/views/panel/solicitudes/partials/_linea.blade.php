{{-- Una fila de la tabla de productos. $i es el índice (o __I__ en la plantilla para nuevas filas). --}}
@php
    $ref = $l && $l->fk_product ? trim((string) $l->producto_ref) : '';
    $norm = fn ($t) => strtolower(preg_replace('/[^A-Za-z0-9]/', '', \Illuminate\Support\Str::ascii((string) $t)));
    $mismoNombre = $ref !== '' && $norm($ref) === $norm($l->producto_label ?? '');
    $celda = 'w-full rounded-md border border-transparent bg-transparent px-2 py-1.5 text-sm text-foreground outline-none transition hover:border-border focus:border-primary focus:bg-card disabled:hover:border-transparent';
@endphp
<tr data-linea data-key="{{ $l->rowid ?? 'n'.$i }}" class="border-t border-border align-top"
    @if (! empty($l->analisis)) data-analisis-guardado="{{ json_encode($l->analisis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" @endif>
    <td class="px-3 py-3">
        <input type="hidden" name="lineas[{{ $i }}][id]" value="{{ $l->rowid ?? '' }}">
        <input type="hidden" name="lineas[{{ $i }}][analisis]" value="" data-analisis-json>
        <input type="hidden" name="lineas[{{ $i }}][fk_product]" value="{{ $l->fk_product ?? '' }}" data-producto-id>
        <div class="mb-2 flex flex-wrap items-center gap-2">
            @if ($ref !== '')
                <span data-linea-ref class="rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-semibold text-primary">{{ $mismoNombre ? 'Catálogo' : $ref }}</span>
            @else
                <span data-linea-ref class="rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">Sin catálogo</span>
            @endif
            <input type="text" name="lineas[{{ $i }}][model]" value="{{ $l->model ?? '' }}" maxlength="40" placeholder="Modelo" title="Modelo" style="width:10rem" class="{{ $celda }} border-border" @disabled($disabled)>
            <label class="ml-auto flex items-center gap-1.5 text-xs text-muted-foreground">Cantidad
                <input type="number" step="any" min="0" name="lineas[{{ $i }}][qty]" value="{{ $l ? rtrim(rtrim(number_format((float) $l->qty, 4, '.', ''), '0'), '.') : '1' }}" style="width:6rem" class="{{ $celda }} border-border text-right" @disabled($disabled)>
            </label>
        </div>
        <textarea name="lineas[{{ $i }}][description]" rows="5" placeholder="Descripción del producto" class="{{ $celda }} min-h-32 resize-y border-border" @disabled($disabled)>{{ $l->description ?? '' }}</textarea>
    </td>
    <td data-precio data-col-ia class="hidden w-44 min-w-40 border-l border-border px-3 py-3 text-xs"></td>
    <td data-analisis data-col-ia class="hidden min-w-[20rem] border-l border-border bg-muted/20 px-3 py-3 text-xs"></td>
    <td class="w-12 px-2 py-3 text-center">
        @unless ($disabled)
            <button type="button" data-quitar-linea title="Quitar producto" class="flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive"><i class="fa-solid fa-trash-can text-xs"></i></button>
        @endunless
    </td>
</tr>
