@php
    $labelClass = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-muted-foreground';
    $inputClass = 'w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ($extrafieldDefs as $def)
        @php
            $valorActual = old('ef.'.$def->name, $extrafieldValores[$def->name] ?? null);
        @endphp

        <div class="{{ $def->type === 'boolean' ? 'flex items-center gap-2 pt-5' : '' }}">
            @if ($def->type === 'boolean')
                <input type="hidden" name="ef[{{ $def->name }}]" value="0">
                <input type="checkbox" id="ef_{{ $def->name }}" name="ef[{{ $def->name }}]" value="1"
                       @checked($valorActual == 1) class="h-4 w-4 rounded border-border text-primary focus:ring-primary">
                <label for="ef_{{ $def->name }}" class="text-sm text-foreground">{{ $def->label }}</label>
            @else
                <label class="{{ $labelClass }}">{{ $def->label }}</label>

                @if (in_array($def->type, ['select', 'sellist']))
                    <select name="ef[{{ $def->name }}]" class="{{ $inputClass }}">
                        <option value="">—</option>
                        @foreach ($opcionesExtrafield($def) as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected((string) $valorActual === (string) $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                @elseif ($def->type === 'date')
                    <input type="date" name="ef[{{ $def->name }}]"
                           value="{{ $valorActual ? \Illuminate\Support\Carbon::parse($valorActual)->format('Y-m-d') : '' }}"
                           class="{{ $inputClass }}">
                @elseif (in_array($def->type, ['int', 'double']))
                    <input type="number" step="{{ $def->type === 'double' ? '0.01' : '1' }}" name="ef[{{ $def->name }}]"
                           value="{{ $valorActual }}" class="{{ $inputClass }}">
                @else
                    <input type="text" name="ef[{{ $def->name }}]" value="{{ $valorActual }}" class="{{ $inputClass }}">
                @endif
            @endif
        </div>
    @endforeach
</div>
