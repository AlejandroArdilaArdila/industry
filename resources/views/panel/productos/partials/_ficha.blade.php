{{--
    Ficha del producto/servicio en modal, como asistente por pasos. Cada paso
    es un <form data-step> que se guarda solo (ver producto-ficha.js).
    Con $esNuevo el primer paso crea el producto y desbloquea los demás.
--}}
@php
    $labelClass = 'mb-1.5 block text-xs font-medium text-muted-foreground';
    $inputClass = 'w-full h-10 rounded-lg border border-input bg-background px-3 text-sm text-foreground shadow-xs transition placeholder:text-muted-foreground/60 focus:border-primary focus:outline-none focus:ring-3 focus:ring-primary/15 disabled:cursor-not-allowed disabled:opacity-60';
    $errorClass = 'mt-1 hidden text-xs text-destructive';
    $switchClass = 'relative inline-flex h-5 w-9 flex-shrink-0 rounded-full bg-muted-foreground/30 transition after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-4';

    $tipoTexto = $esServicio ? 'servicio' : 'producto';
    $icono = $esServicio ? 'fa-concierge-bell' : 'fa-cube';
    $efTributario = $extrafieldsPorGrupo['tributario'];
    $efOtros = $extrafieldsPorGrupo['otros'];

    $pasos = array_filter([
        'general' => ['General', 'Referencia y nombre', 'fa-circle-info'],
        'precios' => ['Precios', 'Venta, IVA y costo', 'fa-tags'],
        'codigos' => ['Códigos de barras', count($codigosBarras).' registrado'.(count($codigosBarras) === 1 ? '' : 's'), 'fa-barcode'],
        'categorias' => ['Categorías', count($categoriasDe).' asignada'.(count($categoriasDe) === 1 ? '' : 's'), 'fa-folder-tree'],
        'tributario' => $efTributario->isNotEmpty() ? ['Tributario y contable', 'Impuestos y plantilla', 'fa-scale-balanced'] : null,
        'adicionales' => $efOtros->isNotEmpty() ? ['Opciones POS', 'Venta, puntos y más', 'fa-cash-register'] : null,
        'notas' => ['Notas', 'Pública y privada', 'fa-note-sticky'],
    ]);
    $clavesPasos = array_keys($pasos);

    $disabled = !$puedeEditar;
    $dinero = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
    $num = fn ($v) => $v !== null && $v !== '' ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : '';
    $nivelesForm = $niveles ?: [1 => 'Precio de venta'];
@endphp

<div data-ficha data-puede-editar="{{ $puedeEditar ? '1' : '0' }}" class="flex h-full flex-col"
     @if ($esNuevo) data-nuevo="1" data-store-url="{{ route('panel.productos.store', ['tipo' => (int) $producto->fk_product_type]) }}"
     @else data-update-url="{{ route('panel.productos.update', $producto->rowid) }}" @endif>

    {{-- ============ Cabecera ============ --}}
    <header class="relative flex-shrink-0 overflow-hidden border-b border-border bg-gradient-to-br from-primary/10 via-card to-card px-5 pt-5 pb-4 sm:px-7">
        <button type="button" data-ficha-close title="Cerrar (Esc)"
                class="absolute top-4 right-4 flex h-9 w-9 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>

        <div class="flex flex-col gap-4 pr-10 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl bg-primary text-xl text-primary-foreground shadow-md">
                    <i class="fa-solid {{ $esNuevo ? 'fa-plus' : $icono }}"></i>
                </div>
                <div class="min-w-0">
                    <h2 data-ficha-titulo class="truncate text-lg font-semibold text-foreground sm:text-xl">{{ $esNuevo ? 'Nuevo '.$tipoTexto : $producto->label }}</h2>
                    @if ($esNuevo)
                        <p class="mt-1 text-sm text-muted-foreground">Completa la referencia y el nombre para crearlo; luego podrás asignar precios, códigos y categorías.</p>
                    @else
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm text-muted-foreground">
                            <button type="button" data-copy="{{ $producto->ref }}" title="Copiar referencia"
                                    class="inline-flex items-center gap-1.5 rounded-md font-mono text-foreground/80 transition hover:text-primary">
                                <span data-ficha-ref>{{ $producto->ref }}</span>
                                <i class="fa-regular fa-copy text-xs"></i>
                            </button>
                            <span class="flex items-center gap-1.5">
                                <span class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium capitalize text-muted-foreground">{{ $tipoTexto }}</span>
                                <span data-ficha-estado class="rounded-full px-2 py-0.5 text-xs font-medium {{ (int) $producto->tosell === 1 ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/15 text-amber-700 dark:text-amber-300' }}">
                                    {{ (int) $producto->tosell === 1 ? 'En venta' : 'No a la venta' }}
                                </span>
                                <span data-ficha-compra class="{{ (int) $producto->tobuy === 1 ? '' : 'hidden' }} rounded-full bg-sky-500/15 px-2 py-0.5 text-xs font-medium text-sky-700 dark:text-sky-300">En compra</span>
                            </span>
                        </div>
                    @endif
                </div>
            </div>

            @unless ($esNuevo)
                <dl class="grid flex-shrink-0 grid-cols-3 gap-2 text-center sm:gap-3">
                    <div class="rounded-xl border border-border bg-background/70 px-3 py-2">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Precio + IVA</dt>
                        <dd data-kpi-precio class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ $dinero($producto->price_ttc) }}</dd>
                    </div>
                    <div class="rounded-xl border border-border bg-background/70 px-3 py-2">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">IVA</dt>
                        <dd data-kpi-iva class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ $num($producto->tva_tx) ?: '0' }} %</dd>
                    </div>
                    <div class="rounded-xl border border-border bg-background/70 px-3 py-2" @if ($stock && $stock['bodegas']->isNotEmpty()) title="{{ $stock['bodegas']->map(fn ($b) => ($b->ref ?? '—').': '.$num($b->reel))->implode("\n") }}" @endif>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{{ $esServicio ? 'Niveles' : 'Stock' }}</dt>
                        <dd class="mt-0.5 text-base font-semibold tabular-nums {{ !$esServicio && ($stock['total'] ?? 0) <= 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground' }}">
                            {{ $esServicio ? count($preciosPorNivel) : ($num($stock['total'] ?? 0) ?: '0') }}
                        </dd>
                    </div>
                </dl>
            @endunless
        </div>
    </header>

    {{-- ============ Cuerpo: pasos + formulario ============ --}}
    <div class="flex min-h-0 flex-1 flex-col md:flex-row">

        <nav class="flex-shrink-0 border-b border-border bg-muted/30 md:w-64 md:border-r md:border-b-0">
            <ol class="flex gap-1 overflow-x-auto p-2 md:flex-col md:p-3">
                @foreach ($pasos as $clave => [$titulo, $subtitulo, $iconoPaso])
                    <li class="flex-shrink-0">
                        <button type="button" data-step-target="{{ $clave }}"
                                class="group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-background data-[active]:bg-background data-[active]:shadow-sm">
                            <span data-step-badge class="relative flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full border border-border bg-background text-sm text-muted-foreground transition group-data-[active]:border-primary group-data-[active]:bg-primary group-data-[active]:text-primary-foreground">
                                <i class="fa-solid {{ $iconoPaso }}"></i>
                                <span data-step-dot class="absolute -top-0.5 -right-0.5 hidden h-3 w-3 rounded-full border-2 border-card"></span>
                            </span>
                            <span class="hidden min-w-0 sm:block">
                                <span data-step-title class="block text-sm font-medium text-foreground">{{ $titulo }}</span>
                                <span data-step-sub class="block truncate text-xs text-muted-foreground" data-default="{{ $subtitulo }}">{{ $subtitulo }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ol>

            <div data-ficha-meta class="{{ $esNuevo ? 'hidden' : '' }} space-y-1 px-5 pt-3 pb-5 text-xs text-muted-foreground max-md:hidden">
                @unless ($esNuevo)
                    <p><i class="fa-regular fa-calendar-plus mr-1.5 w-3"></i>Creado {{ $producto->datec ? \Illuminate\Support\Carbon::parse($producto->datec)->format('d/m/Y') : '—' }}@if ($creador) · {{ $creador }}@endif</p>
                    <p><i class="fa-regular fa-pen-to-square mr-1.5 w-3"></i>Modificado {{ $producto->tms ? \Illuminate\Support\Carbon::parse($producto->tms)->format('d/m/Y H:i') : '—' }}@if ($modificador) · {{ $modificador }}@endif</p>
                @endunless
                <p><i class="fa-solid fa-hashtag mr-1.5 w-3"></i>ID <span data-ficha-id>{{ $producto->rowid }}</span></p>
            </div>
        </nav>

        <div class="relative min-h-0 flex-1 overflow-y-auto" data-ficha-scroll>
            @if (!$puedeEditar)
                <div class="mx-5 mt-5 flex items-center gap-2 rounded-xl border border-border bg-muted/50 px-4 py-3 text-sm text-muted-foreground sm:mx-7">
                    <i class="fa-solid fa-lock"></i> Solo lectura: tu usuario no tiene el permiso de Dolibarr para modificar {{ $tipoTexto }}s.
                </div>
            @endif

            {{-- ---------- 1. General ---------- --}}
            <form data-step="general" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'General', 'descripcion' => 'Cómo se identifica el '.$tipoTexto.' en el POS, facturas e inventario.'])

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="p_ref">Referencia <span class="text-destructive">*</span></label>
                        <input id="p_ref" name="ref" value="{{ $producto->ref }}" maxlength="128" data-required autocomplete="off" class="{{ $inputClass }} font-mono" @disabled($disabled)>
                        <p data-error-for="ref" class="{{ $errorClass }}"></p>
                    </div>
                    <div class="sm:col-span-4">
                        <label class="{{ $labelClass }}" for="p_label">Nombre <span class="text-destructive">*</span></label>
                        <input id="p_label" name="label" value="{{ $producto->label }}" maxlength="255" data-required class="{{ $inputClass }}" @disabled($disabled)>
                        <p data-error-for="label" class="{{ $errorClass }}"></p>
                    </div>
                    <div class="sm:col-span-6">
                        <label class="{{ $labelClass }}" for="p_description">Descripción</label>
                        <textarea id="p_description" name="description" rows="4" class="{{ $inputClass }} h-auto py-2" @disabled($disabled)>{{ $producto->description }}</textarea>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['tosell', 'En venta', 'Se puede vender en el POS y en facturas.', 'peer-checked:bg-emerald-500'],
                        ['tobuy', 'En compra', 'Se puede comprar a proveedores.', 'peer-checked:bg-sky-500'],
                    ] as [$campo, $titulo, $ayuda, $color])
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-background p-3.5 transition hover:border-primary/40 has-[:checked]:border-primary/50 has-[:checked]:bg-primary/5 {{ $disabled ? 'pointer-events-none opacity-60' : '' }}">
                            <input type="hidden" name="{{ $campo }}" value="0">
                            <input type="checkbox" name="{{ $campo }}" value="1" class="peer sr-only" @checked((int) $producto->{$campo} === 1) @disabled($disabled)>
                            <span class="{{ $switchClass }} {{ $color }} mt-0.5"></span>
                            <span>
                                <span class="block text-sm font-medium text-foreground">{{ $titulo }}</span>
                                <span class="block text-xs text-muted-foreground">{{ $ayuda }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-6 grid grid-cols-1 gap-4 border-t border-border pt-6 sm:grid-cols-2">
                    @unless ($esServicio)
                        <div>
                            <label class="{{ $labelClass }}" for="p_seuil">Stock mínimo (alerta)</label>
                            <input id="p_seuil" type="number" step="any" min="0" name="seuil_stock_alerte" value="{{ $num($producto->seuil_stock_alerte) }}" class="{{ $inputClass }} text-right tabular-nums" @disabled($disabled)>
                            <p data-error-for="seuil_stock_alerte" class="{{ $errorClass }}"></p>
                        </div>
                        <div>
                            <label class="{{ $labelClass }}" for="p_desired">Stock deseado</label>
                            <input id="p_desired" type="number" step="any" min="0" name="desiredstock" value="{{ $num($producto->desiredstock) }}" class="{{ $inputClass }} text-right tabular-nums" @disabled($disabled)>
                            <p data-error-for="desiredstock" class="{{ $errorClass }}"></p>
                        </div>
                    @endunless
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="p_url">URL pública</label>
                        <div class="relative">
                            <i class="fa-solid fa-globe pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"></i>
                            <input id="p_url" name="url" value="{{ $producto->url }}" maxlength="255" class="{{ $inputClass }} pl-9" placeholder="https://tienda.com/producto" @disabled($disabled)>
                        </div>
                    </div>
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'general'])
            </form>

            {{-- ---------- 2. Precios ---------- --}}
            <form data-step="precios" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Precios', 'descripcion' => 'Digita los precios con IVA incluido; el valor sin IVA se calcula solo. Cada cambio queda en el historial.'])

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="p_tva">Tarifa de IVA <span class="text-destructive">*</span></label>
                        <select id="p_tva" name="tva_tx" data-required data-iva class="{{ $inputClass }}" @disabled($disabled)>
                            @foreach ($tarifasIva as $t)
                                <option value="{{ $num($t->taux) ?: '0' }}" @selected((float) $producto->tva_tx === (float) $t->taux)>{{ $num($t->taux) ?: '0' }} %{{ $t->note ? ' · '.$t->note : '' }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-muted-foreground">También define la plantilla contable del {{ $tipoTexto }}.</p>
                        <p data-error-for="tva_tx" class="{{ $errorClass }}"></p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="p_cost">Costo (sin IVA)</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted-foreground">$</span>
                            <input id="p_cost" type="number" step="any" min="0" name="cost_price" data-costo value="{{ $num($producto->cost_price) }}" class="{{ $inputClass }} pl-7 text-right tabular-nums" @disabled($disabled)>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">Se usa para calcular el margen de cada precio.</p>
                        <p data-error-for="cost_price" class="{{ $errorClass }}"></p>
                    </div>
                </div>

                <div class="mt-6 overflow-hidden rounded-xl border border-border">
                    <div class="grid grid-cols-12 gap-3 border-b border-border bg-muted/40 px-4 py-2 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                        <span class="col-span-4">Nivel</span>
                        <span class="col-span-4 text-right">Precio + IVA</span>
                        <span class="col-span-2 text-right">Sin IVA</span>
                        <span class="col-span-2 text-right">Margen</span>
                    </div>
                    @foreach ($nivelesForm as $nivel => $etiqueta)
                        @php $vigente = $preciosPorNivel[$nivel] ?? null; @endphp
                        <div data-nivel class="grid grid-cols-12 items-center gap-3 border-b border-border px-4 py-3 last:border-b-0">
                            <div class="col-span-4">
                                <p class="text-sm font-medium text-foreground">{{ $etiqueta }} @if ($nivel === 1)<span class="text-destructive">*</span>@endif</p>
                                <p class="text-xs text-muted-foreground">{{ $nivel === 1 ? 'Precio principal' : 'Lista de precios '.$nivel }}</p>
                            </div>
                            <div class="col-span-4">
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted-foreground">$</span>
                                    <input type="number" step="any" min="0" name="precios[{{ $nivel }}]" data-precio
                                           value="{{ $vigente && ($nivel === 1 || $vigente->date_price) ? $num($vigente->price_ttc) : '' }}"
                                           @if ($nivel === 1) data-required @else placeholder="Igual al precio 1" @endif
                                           class="{{ $inputClass }} pl-7 text-right tabular-nums" @disabled($disabled)>
                                </div>
                                <p data-error-for="precios.{{ $nivel }}" class="{{ $errorClass }}"></p>
                            </div>
                            <span data-sin-iva class="col-span-2 text-right text-sm tabular-nums text-muted-foreground">—</span>
                            <span data-margen class="col-span-2 text-right text-sm font-medium tabular-nums text-muted-foreground">—</span>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="p_min">Precio mínimo de venta (+ IVA)</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted-foreground">$</span>
                            <input id="p_min" type="number" step="any" min="0" name="precio_min" value="{{ (float) $producto->price_min_ttc > 0 ? $num($producto->price_min_ttc) : '' }}"
                                   class="{{ $inputClass }} pl-7 text-right tabular-nums" placeholder="Sin mínimo" @disabled($disabled)>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">El POS no deja vender por debajo de este valor.</p>
                        <p data-error-for="precio_min" class="{{ $errorClass }}"></p>
                    </div>
                </div>

                @if ($historialPrecios->isNotEmpty())
                    <div class="mt-6 border-t border-border pt-6">
                        <p class="mb-3 text-sm font-medium text-foreground"><i class="fa-solid fa-clock-rotate-left mr-1.5 text-muted-foreground"></i>Últimos cambios de precio</p>
                        <div class="overflow-x-auto rounded-xl border border-border">
                            <table class="w-full text-sm">
                                <thead class="bg-muted/40 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                    <tr>
                                        <th class="px-4 py-2 font-medium">Fecha</th>
                                        <th class="px-4 py-2 font-medium">Nivel</th>
                                        <th class="px-4 py-2 text-right font-medium">Precio + IVA</th>
                                        <th class="px-4 py-2 text-right font-medium">IVA</th>
                                        <th class="px-4 py-2 font-medium">Usuario</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($historialPrecios as $h)
                                        <tr>
                                            <td class="px-4 py-2 tabular-nums text-muted-foreground">{{ \Illuminate\Support\Carbon::parse($h->date_price)->format('d/m/Y H:i') }}</td>
                                            <td class="px-4 py-2">{{ max(1, (int) $h->price_level) }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ $dinero($h->price_ttc) }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ $num($h->tva_tx) ?: '0' }} %</td>
                                            <td class="px-4 py-2 text-muted-foreground">{{ $h->login ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @include('panel.partials.ficha._paso-footer', ['clave' => 'precios'])
            </form>

            {{-- ---------- 3. Códigos de barras ---------- --}}
            <form data-step="codigos" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Códigos de barras', 'descripcion' => 'Todos los códigos con los que el POS encuentra este '.$tipoTexto.'. Uno por línea.'])

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="p_codigos">Códigos</label>
                        <textarea id="p_codigos" name="codigos_barras" rows="10" data-codigos spellcheck="false"
                                  class="{{ $inputClass }} h-auto py-2 font-mono" placeholder="7701234567890&#10;7709876543210" @disabled($disabled)>{{ implode("\n", $codigosBarras) }}</textarea>
                        <p data-error-for="codigos_barras" class="{{ $errorClass }}"></p>
                        <p class="mt-1 text-xs text-muted-foreground">Máximo 20 caracteres por código. Puedes pegar varios separados por comas o saltos de línea.</p>
                    </div>
                    <div>
                        <p class="{{ $labelClass }}">Vista previa</p>
                        <div data-codigos-preview class="flex min-h-24 flex-wrap content-start gap-2 rounded-xl border border-dashed border-border p-3"></div>
                    </div>
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'codigos'])
            </form>

            {{-- ---------- 4. Categorías ---------- --}}
            <form data-step="categorias" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Categorías', 'descripcion' => 'Agrupan el '.$tipoTexto.' en el POS, los filtros del listado y las promociones.'])

                @include('panel.partials.ficha._categorias', [
                    'seleccionadas' => $categoriasDe,
                    'name' => 'categorias[]',
                    'vacio' => 'No hay categorías de producto creadas.',
                ])

                @include('panel.partials.ficha._paso-footer', ['clave' => 'categorias'])
            </form>

            {{-- ---------- 5. Tributario y contable ---------- --}}
            @if ($efTributario->isNotEmpty())
                <form data-step="tributario" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                    @include('panel.partials.ficha._paso-header', ['titulo' => 'Tributario y contable', 'descripcion' => 'Impuestos especiales, retenciones y plantilla contable.'])
                    @include('panel.partials.ficha._extrafields', ['defs' => $efTributario])
                    @include('panel.partials.ficha._paso-footer', ['clave' => 'tributario'])
                </form>
            @endif

            {{-- ---------- 6. Opciones POS ---------- --}}
            @if ($efOtros->isNotEmpty())
                <form data-step="adicionales" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                    @include('panel.partials.ficha._paso-header', ['titulo' => 'Opciones POS', 'descripcion' => 'Cómo se vende en el POS y otros campos adicionales de Dolibarr.'])
                    @include('panel.partials.ficha._extrafields', ['defs' => $efOtros])
                    @include('panel.partials.ficha._paso-footer', ['clave' => 'adicionales'])
                </form>
            @endif

            {{-- ---------- 7. Notas ---------- --}}
            <form data-step="notas" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Notas', 'descripcion' => 'La nota pública puede salir en documentos; la privada solo la ve tu equipo.'])

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="p_note_public"><i class="fa-solid fa-eye mr-1"></i> Nota pública</label>
                        <textarea id="p_note_public" name="note_public" rows="8" class="{{ $inputClass }} h-auto py-2" @disabled($disabled)>{{ $producto->note_public }}</textarea>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="p_note"><i class="fa-solid fa-lock mr-1"></i> Nota privada</label>
                        <textarea id="p_note" name="note" rows="8" class="{{ $inputClass }} h-auto py-2" @disabled($disabled)>{{ $producto->note }}</textarea>
                    </div>
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'notas'])
            </form>
        </div>
    </div>
</div>
