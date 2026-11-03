{{-- Extrafields de un grupo: los booleanos se muestran como tarjetas con interruptor, el resto como campos. --}}
@php
    $booleanos = $defs->where('type', 'boolean');
    $campos = $defs->where('type', '!=', 'boolean');
@endphp

@if ($campos->isNotEmpty())
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @foreach ($campos as $def)
            @php
                $nombre = 'ef['.$def->name.']';
                $soloLectura = (int) $def->list === 5 || !$puedeEditar;
                $requerido = (int) $def->fieldrequired === 1;
            @endphp
            <div>
                <label class="{{ $labelClass }}" for="ef_{{ $def->name }}">
                    {{ $def->label }} @if ($requerido)<span class="text-destructive">*</span>@endif
                </label>

                @if (in_array($def->type, ['select', 'sellist']))
                    <select id="ef_{{ $def->name }}" name="{{ $nombre }}" class="{{ $inputClass }}" @disabled($soloLectura) @if ($requerido) data-required @endif>
                        <option value="">Selecciona…</option>
                        @foreach ($def->opciones as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected((string) $def->valor === (string) $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                @elseif (in_array($def->type, ['price', 'pricecy', 'double']))
                    <div class="relative">
                        @if ($def->type !== 'double')
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted-foreground">$</span>
                        @endif
                        <input type="number" step="0.01" id="ef_{{ $def->name }}" name="{{ $nombre }}"
                               value="{{ $def->valor !== null ? (float) $def->valor : '' }}"
                               class="{{ $inputClass }} {{ $def->type !== 'double' ? 'pl-7' : '' }} text-right tabular-nums" @disabled($soloLectura) @if ($requerido) data-required @endif>
                    </div>
                @elseif ($def->type === 'int')
                    <input type="number" step="1" id="ef_{{ $def->name }}" name="{{ $nombre }}" value="{{ $def->valor }}"
                           class="{{ $inputClass }} text-right tabular-nums" @disabled($soloLectura) @if ($requerido) data-required @endif>
                @elseif (in_array($def->type, ['date', 'datetime']))
                    <input type="date" id="ef_{{ $def->name }}" name="{{ $nombre }}"
                           value="{{ $def->valor ? \Illuminate\Support\Carbon::parse($def->valor)->format('Y-m-d') : '' }}"
                           class="{{ $inputClass }}" @disabled($soloLectura) @if ($requerido) data-required @endif>
                @elseif (in_array($def->type, ['text', 'html']))
                    <textarea id="ef_{{ $def->name }}" name="{{ $nombre }}" rows="3" class="{{ $inputClass }} h-auto py-2"
                              @disabled($soloLectura) @if ($requerido) data-required @endif>{{ $def->valor }}</textarea>
                @else
                    <input type="text" id="ef_{{ $def->name }}" name="{{ $nombre }}" value="{{ $def->valor }}"
                           class="{{ $inputClass }}" @disabled($soloLectura) @if ($requerido) data-required @endif>
                @endif

                @if ($def->help)
                    <p class="mt-1 text-xs text-muted-foreground">{{ $def->help }}</p>
                @endif
                <p data-error-for="ef.{{ $def->name }}" class="mt-1 hidden text-xs text-destructive"></p>
            </div>
        @endforeach
    </div>
@endif

@if ($booleanos->isNotEmpty())
    <div class="{{ $campos->isNotEmpty() ? 'mt-5' : '' }} grid grid-cols-1 gap-3 sm:grid-cols-2">
        @foreach ($booleanos as $def)
            @php $soloLectura = (int) $def->list === 5 || !$puedeEditar; @endphp
            <label class="group flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-background p-3.5 transition hover:border-primary/40 has-[:checked]:border-primary/50 has-[:checked]:bg-primary/5 {{ $soloLectura ? 'pointer-events-none opacity-60' : '' }}">
                <input type="hidden" name="ef[{{ $def->name }}]" value="0" @disabled($soloLectura)>
                <input type="checkbox" name="ef[{{ $def->name }}]" value="1" class="peer sr-only" @checked((int) $def->valor === 1) @disabled($soloLectura)>
                <span class="relative mt-0.5 inline-flex h-5 w-9 flex-shrink-0 rounded-full bg-muted-foreground/30 transition peer-checked:bg-primary peer-focus-visible:ring-3 peer-focus-visible:ring-primary/25
                             after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-4"></span>
                <span class="min-w-0">
                    <span class="block text-sm font-medium text-foreground">{{ $def->label }}</span>
                    @if ($def->help)
                        <span class="mt-0.5 block text-xs leading-snug text-muted-foreground">{{ $def->help }}</span>
                    @endif
                </span>
            </label>
        @endforeach
    </div>
@endif
