{{--
    Ficha de una categoría (cualquier tipo) en modal: un solo formulario para
    crear y editar (ver categoria-ficha.js). El tipo solo se elige al crear:
    los elementos de la categoría viven en la tabla de enlace de ese tipo.
--}}
@php
    $labelClass = 'mb-1.5 block text-xs font-medium text-muted-foreground';
    $inputClass = 'w-full h-10 rounded-lg border border-input bg-background px-3 text-sm text-foreground shadow-xs transition placeholder:text-muted-foreground/60 focus:border-primary focus:outline-none focus:ring-3 focus:ring-primary/15 disabled:cursor-not-allowed disabled:opacity-60';
    $errorClass = 'mt-1 hidden text-xs text-destructive';

    $clavesPasos = ['general'];
    $disabled = !$puedeEditar;
    $tipoActual = (int) $categoria->type;
    $tipoInfo = collect($tipos)->firstWhere('rowid', $tipoActual);
    $color = $categoria->color ? '#'.ltrim($categoria->color, '#') : '';
    $paleta = ['#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6', '#6366f1', '#8b5cf6', '#d946ef', '#ec4899', '#64748b'];
    $fechaHora = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y H:i') : null;
@endphp

<div data-ficha data-puede-editar="{{ $puedeEditar ? '1' : '0' }}" data-tipo="{{ $tipoActual }}" class="flex h-full min-h-0 flex-col"
     @if ($esNuevo) data-nuevo="1" data-store-url="{{ route('panel.categorias.store') }}"
     @else data-update-url="{{ route('panel.categorias.update', $categoria->rowid) }}" @endif>

    <script type="application/json" data-ficha-catalogos>{!! json_encode([
        'fk_parent' => $padres->where('tipo', $tipoActual)->values(),
    ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
    <script type="application/json" data-padres-json>{!! json_encode($padres->values(), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>

    {{-- ============ Cabecera ============ --}}
    <header class="relative flex-shrink-0 overflow-hidden border-b border-border bg-gradient-to-br from-primary/10 via-card to-card px-5 pt-5 pb-4 sm:px-7">
        <button type="button" data-ficha-close title="Cerrar (Esc)"
                class="absolute top-4 right-4 flex h-9 w-9 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>

        <div class="flex flex-col gap-4 pr-10 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex min-w-0 items-center gap-4">
            <div data-ficha-avatar class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl text-xl text-white shadow-md"
                 style="background: {{ $color ?: 'var(--primary)' }}">
                <i class="fa-solid {{ $esNuevo ? 'fa-plus' : 'fa-tag' }}"></i>
            </div>
            <div class="min-w-0">
                <h2 data-ficha-titulo class="truncate text-lg font-semibold text-foreground sm:text-xl">{{ $esNuevo ? 'Nueva etiqueta' : $categoria->label }}</h2>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm text-muted-foreground">
                    @if ($esNuevo)
                        <span>Elige el tipo, ponle nombre y, si quieres, ubícala dentro de otra categoría.</span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                            <i class="fa-solid {{ $tipoInfo['icono'] ?? 'fa-tag' }}"></i> {{ $tipoInfo['label'] ?? 'Tipo '.$tipoActual }}
                        </span>
                        <span class="font-mono text-xs">ID {{ $categoria->rowid }}</span>
                        @if ($protegida)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                <i class="fa-solid fa-lock"></i> Usada por el POS
                            </span>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        @unless ($esNuevo)
            <dl class="grid flex-shrink-0 grid-cols-3 gap-2 text-center sm:gap-3">
                <div class="rounded-xl border border-border bg-background/70 px-3 py-2">
                    <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Elementos</dt>
                    <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ number_format($elementos, 0, ',', '.') }}</dd>
                </div>
                <div class="rounded-xl border border-border bg-background/70 px-3 py-2">
                    <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Subcategorías</dt>
                    <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ $subcategorias }}</dd>
                </div>
                <div class="rounded-xl border border-border bg-background/70 px-3 py-2">
                    <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Promociones</dt>
                    <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ $enPromociones }}</dd>
                </div>
            </dl>
        @endunless
        </div>
    </header>

    {{-- ============ Formulario ============ --}}
    <div data-ficha-scroll class="min-h-0 flex-1 overflow-y-auto">
        <form data-step="general" class="flex min-h-full flex-col p-5 sm:p-7" novalidate>

            {{-- Tipo --}}
            <div class="mb-5">
                <p class="{{ $labelClass }}">Tipo <span class="text-destructive">*</span></p>
                @if ($esNuevo)
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach ($tipos as $tipo)
                            <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-border bg-background px-3 py-2 text-sm transition hover:border-primary/40 has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:checked]:shadow-sm">
                                <input type="radio" name="tipo" value="{{ $tipo['rowid'] }}" class="sr-only" @checked($tipo['rowid'] === $tipoActual) @disabled($disabled)>
                                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg text-sm" style="background: #{{ $tipo['color'] }}1f; color: #{{ $tipo['color'] }}">
                                    <i class="fa-solid {{ $tipo['icono'] }}"></i>
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-foreground">{{ $tipo['label'] }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ $tipo['total'] }} {{ $tipo['total'] === 1 ? 'categoría' : 'categorías' }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <p data-error-for="tipo" class="{{ $errorClass }}"></p>
                @else
                    <div class="flex items-center gap-3 rounded-xl border border-border bg-muted/30 px-3 py-2.5 text-sm">
                        <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg" style="background: #{{ $tipoInfo['color'] ?? '64748b' }}1f; color: #{{ $tipoInfo['color'] ?? '64748b' }}">
                            <i class="fa-solid {{ $tipoInfo['icono'] ?? 'fa-tag' }}"></i>
                        </span>
                        <span class="font-medium text-foreground">{{ $tipoInfo['label'] ?? 'Tipo '.$tipoActual }}</span>
                        <span class="ml-auto text-xs text-muted-foreground">El tipo no se puede cambiar después de crearla.</span>
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="cat_label">Nombre <span class="text-destructive">*</span></label>
                    <input type="text" id="cat_label" name="label" value="{{ $categoria->label }}" maxlength="180" required data-required
                           placeholder="Ej. Bebidas, Clientes VIP…" class="{{ $inputClass }}" @disabled($disabled) @readonly($protegida)>
                    <p data-error-for="label" class="{{ $errorClass }}"></p>
                </div>

                <div>
                    <label class="{{ $labelClass }}">Categoría padre</label>
                    <input type="hidden" name="fk_parent" value="{{ (int) $categoria->fk_parent ?: '' }}">
                    <div data-combo="fk_parent" data-placeholder="Ninguna (categoría principal)" @if ($disabled || $protegida) data-disabled @endif></div>
                    <p class="mt-1 text-xs text-muted-foreground">Solo se listan categorías del mismo tipo.</p>
                    <p data-error-for="fk_parent" class="{{ $errorClass }}"></p>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
            {{-- Color --}}
            <div>
                <p class="{{ $labelClass }}">Color</p>
                <input type="hidden" name="color" value="{{ $color }}" data-color-valor>
                <div class="flex flex-wrap items-center gap-2 {{ $disabled ? 'pointer-events-none opacity-60' : '' }}">
                    @foreach ($paleta as $hex)
                        <button type="button" data-color="{{ $hex }}" title="{{ $hex }}"
                                class="h-7 w-7 rounded-full border-2 border-transparent ring-offset-2 ring-offset-card transition hover:scale-110 data-[active]:ring-2 data-[active]:ring-foreground/60"
                                style="background: {{ $hex }}"></button>
                    @endforeach
                    <label title="Otro color" class="relative flex h-7 w-7 cursor-pointer items-center justify-center rounded-full border border-dashed border-muted-foreground/50 text-xs text-muted-foreground transition hover:border-primary hover:text-primary">
                        <i class="fa-solid fa-eye-dropper"></i>
                        <input type="color" data-color-picker value="{{ $color ?: '#3b82f6' }}" class="absolute inset-0 h-full w-full cursor-pointer opacity-0">
                    </label>
                    <button type="button" data-color="" class="ml-1 inline-flex h-7 items-center gap-1.5 rounded-full border border-border px-2.5 text-xs text-muted-foreground transition hover:bg-muted data-[active]:border-foreground/40 data-[active]:text-foreground">
                        <i class="fa-solid fa-ban"></i> Sin color
                    </button>

                    <span class="inline-flex items-center gap-2 rounded-full border border-border bg-background px-3 py-1 text-sm">
                        <span data-color-muestra class="h-2.5 w-2.5 rounded-full"></span>
                        <span data-label-muestra class="max-w-48 truncate">{{ $categoria->label ?: 'Vista previa' }}</span>
                    </span>
                </div>
                <p data-error-for="color" class="{{ $errorClass }}"></p>
            </div>

            <div>
                <label class="{{ $labelClass }}" for="cat_description">Descripción</label>
                <textarea id="cat_description" name="description" rows="2" maxlength="5000" placeholder="Opcional"
                          class="{{ $inputClass }} h-auto py-2" @disabled($disabled)>{{ $categoria->description }}</textarea>
                <p data-error-for="description" class="{{ $errorClass }}"></p>
            </div>
            </div>

            @unless ($esNuevo)
                <p class="mt-5 text-xs text-muted-foreground">
                    @if ($fechaHora($categoria->date_creation))
                        Creada el {{ $fechaHora($categoria->date_creation) }}@if ($creador) por {{ $creador }}@endif.
                    @endif
                    @if ($editor) Última modificación por {{ $editor }} el {{ $fechaHora($categoria->tms) }}. @endif
                </p>

                @if ($puedeEliminar && !$protegida)
                    <div class="mt-4 flex flex-col gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4 sm:flex-row sm:items-center">
                        <div class="min-w-0 flex-1 text-sm">
                            <p class="font-medium text-foreground">Eliminar categoría</p>
                            <p class="text-xs text-muted-foreground">
                                Se quita de {{ $elementos === 1 ? '1 elemento' : number_format($elementos, 0, ',', '.').' elementos' }}; los elementos no se borran.
                                @if ($subcategorias) Sus {{ $subcategorias }} subcategorías suben un nivel. @endif
                            </p>
                        </div>
                        <button type="button" data-eliminar="{{ route('panel.categorias.destroy', $categoria->rowid) }}"
                                data-elementos="{{ $elementos }}" data-subcategorias="{{ $subcategorias }}" data-promociones="{{ $enPromociones }}"
                                class="inline-flex h-9 flex-shrink-0 items-center justify-center gap-2 rounded-lg border border-destructive/40 bg-background px-3 text-sm font-medium text-destructive transition hover:bg-destructive hover:text-white">
                            <i class="fa-solid fa-trash-can"></i> Eliminar
                        </button>
                    </div>
                @endif
            @endunless

            @include('panel.partials.ficha._paso-footer', ['clave' => 'general'])
        </form>
    </div>
</div>
