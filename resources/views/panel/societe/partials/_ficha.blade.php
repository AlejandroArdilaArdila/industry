{{--
    Ficha del tercero en modal, como asistente por pasos. Cada paso es un
    <form data-step> independiente que se guarda solo (ver tercero-ficha.js).
    Con $esNuevo el primer paso crea el tercero y desbloquea los demás.
--}}
@php
    $labelClass = 'mb-1.5 block text-xs font-medium text-muted-foreground';
    $inputClass = 'w-full h-10 rounded-lg border border-input bg-background px-3 text-sm text-foreground shadow-xs transition placeholder:text-muted-foreground/60 focus:border-primary focus:outline-none focus:ring-3 focus:ring-primary/15 disabled:cursor-not-allowed disabled:opacity-60';
    $errorClass = 'mt-1 hidden text-xs text-destructive';

    $iniciales = collect(preg_split('/\s+/', trim((string) $tercero->nom)))
        ->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');

    $efTributario = $extrafieldsPorGrupo['tributario'];
    $efCredito = $extrafieldsPorGrupo['credito'];
    $efOtros = $extrafieldsPorGrupo['otros'];
    $porActualizar = (int) ($efOtros->firstWhere('name', 'por_actualizar')?->valor ?? 0) === 1;

    $totalCategorias = count($categoriasClienteDe) + count($categoriasProveedorDe);
    $esCliente = in_array((int) $tercero->client, [1, 2, 3], true);
    $esProveedor = (int) $tercero->fournisseur === 1;

    $pasos = array_filter([
        'identificacion' => ['Identificación', 'Datos obligatorios', 'fa-id-card'],
        'contacto' => ['Contacto y ubicación', 'Dirección, ciudad y medios', 'fa-location-dot'],
        'comercial' => ['Comercial y crédito', 'Precios, pagos y cupo', 'fa-handshake'],
        'tributario' => ['Tributario', 'IVA y retenciones', 'fa-scale-balanced'],
        'categorias' => ['Categorías', $totalCategorias.' asignada'.($totalCategorias === 1 ? '' : 's'), 'fa-tags'],
        'adicionales' => $efOtros->isNotEmpty() ? ['Campos adicionales', 'Extrafields de Dolibarr', 'fa-puzzle-piece'] : null,
        'notas' => ['Notas', 'Pública y privada', 'fa-note-sticky'],
    ]);
    $clavesPasos = array_keys($pasos);

    $disabled = !$puedeEditar;
    $catalogos = [
        'town' => $ciudades,
        'ape' => $ciius,
        'fk_departement' => $departamentos,
        'fk_pays' => $paises,
    ];
    $dinero = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
@endphp

<div data-ficha data-puede-editar="{{ $puedeEditar ? '1' : '0' }}" class="flex h-full flex-col"
     @if ($esNuevo) data-nuevo="1" data-store-url="{{ route('panel.societe.terceros.store') }}"
     @else data-update-url="{{ route('panel.societe.terceros.update', $tercero->rowid) }}" @endif>

    {{-- ============ Cabecera ============ --}}
    <header class="relative flex-shrink-0 overflow-hidden border-b border-border bg-gradient-to-br from-primary/10 via-card to-card px-5 pt-5 pb-4 sm:px-7">
        <button type="button" data-ficha-close title="Cerrar (Esc)"
                class="absolute top-4 right-4 flex h-9 w-9 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>

        <div class="flex flex-col gap-4 pr-10 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl bg-primary text-lg font-semibold text-primary-foreground shadow-md">
                    @if ($esNuevo)<i class="fa-solid fa-user-plus"></i>@else{{ $iniciales ?: '?' }}@endif
                </div>
                <div class="min-w-0">
                    <h2 data-ficha-titulo class="truncate text-lg font-semibold text-foreground sm:text-xl">{{ $esNuevo ? 'Nuevo tercero' : $tercero->nom }}</h2>
                    @if ($esNuevo)
                        <p class="mt-1 text-sm text-muted-foreground">Completa la identificación para crearlo; luego podrás llenar los demás pasos.</p>
                    @else
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm text-muted-foreground">
                        <button type="button" data-copy="{{ $tercero->siren }}" title="Copiar documento"
                                class="inline-flex items-center gap-1.5 rounded-md font-mono text-foreground/80 transition hover:text-primary">
                            <span data-ficha-doc>{{ $tercero->siret }} {{ $tercero->siren }}</span>
                            <i class="fa-regular fa-copy text-xs"></i>
                        </button>
                        @if ($tercero->name_alias)
                            <span class="truncate">· {{ $tercero->name_alias }}</span>
                        @endif
                        <span class="flex items-center gap-1.5">
                            @if (in_array((int) $tercero->client, [1, 3]))
                                <span class="rounded-full bg-cyan-500/15 px-2 py-0.5 text-xs font-medium text-cyan-700 dark:text-cyan-300">Cliente</span>
                            @endif
                            @if (in_array((int) $tercero->client, [2, 3]))
                                <span class="rounded-full bg-violet-500/15 px-2 py-0.5 text-xs font-medium text-violet-700 dark:text-violet-300">Prospecto</span>
                            @endif
                            @if ((int) $tercero->fournisseur === 1)
                                <span class="rounded-full bg-orange-500/15 px-2 py-0.5 text-xs font-medium text-orange-700 dark:text-orange-300">Proveedor</span>
                            @endif
                            <span data-ficha-estado class="rounded-full px-2 py-0.5 text-xs font-medium {{ (int) $tercero->status === 1 ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/15 text-amber-700 dark:text-amber-300' }}">
                                {{ (int) $tercero->status === 1 ? 'Activo' : 'Inactivo' }}
                            </span>
                        </span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @if ($tercero->email)
                            <a href="mailto:{{ $tercero->email }}" class="inline-flex items-center gap-1.5 rounded-full border border-border bg-background/70 px-2.5 py-1 text-xs text-foreground/80 transition hover:border-primary/40 hover:text-primary">
                                <i class="fa-regular fa-envelope"></i> {{ $tercero->email }}
                            </a>
                        @endif
                        @if ($tercero->phone)
                            <a href="tel:{{ $tercero->phone }}" class="inline-flex items-center gap-1.5 rounded-full border border-border bg-background/70 px-2.5 py-1 text-xs text-foreground/80 transition hover:border-primary/40 hover:text-primary">
                                <i class="fa-solid fa-phone"></i> {{ $tercero->phone }}
                            </a>
                        @endif
                        @if ($tercero->phone_mobile)
                            <a href="https://wa.me/{{ preg_replace('/\D/', '', str_starts_with($tercero->phone_mobile, '+') ? $tercero->phone_mobile : '57'.$tercero->phone_mobile) }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 rounded-full border border-border bg-background/70 px-2.5 py-1 text-xs text-foreground/80 transition hover:border-emerald-500/50 hover:text-emerald-600">
                                <i class="fa-brands fa-whatsapp"></i> {{ $tercero->phone_mobile }}
                            </a>
                        @endif
                    </div>
                    @endif
                </div>
            </div>

            @if ($kpis)
            <dl class="grid flex-shrink-0 grid-cols-3 gap-2 text-center sm:gap-3">
                <div class="rounded-xl border border-border bg-background/70 px-3 py-2">
                    <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Facturas</dt>
                    <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ number_format($kpis['facturas'], 0, ',', '.') }}</dd>
                </div>
                <div class="rounded-xl border border-border bg-background/70 px-3 py-2">
                    <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Cartera</dt>
                    <dd class="mt-0.5 text-base font-semibold tabular-nums {{ $kpis['cartera'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground' }}">{{ $dinero($kpis['cartera']) }}</dd>
                </div>
                <div class="rounded-xl border border-border bg-background/70 px-3 py-2">
                    <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Última factura</dt>
                    <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ $kpis['ultimaFactura'] ? \Illuminate\Support\Carbon::parse($kpis['ultimaFactura'])->format('d/m/Y') : '—' }}</dd>
                </div>
            </dl>
            @endif
        </div>
    </header>

    @if (! empty($actividad))
        <div class="flex flex-shrink-0 flex-wrap items-center gap-2 border-b border-border bg-muted/20 px-5 py-2.5 sm:px-7" data-ficha-actividad>
            <span class="mr-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><i class="fa-solid fa-diagram-project mr-1"></i>Actividad comercial</span>
            @foreach ($actividad as $a)
                @php($hay = $a['activos'] > 0)
                <span class="inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs transition {{ $hay ? 'border-border bg-background shadow-sm' : 'border-dashed border-border/70 bg-transparent opacity-60' }}"
                      title="{{ $a['etiqueta'] }}: {{ $a['activos'] }} {{ $a['clave'] === 'seguimientos' ? 'pendientes' : 'activos' }}{{ $a['total'] !== null ? ' · '.$a['total'].' en total' : '' }}">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full text-[10px] text-white" style="background: {{ $hay ? $a['color'] : '#94a3b8' }}"><i class="fa-solid {{ $a['icono'] }}"></i></span>
                    <span class="{{ $hay ? 'text-foreground' : 'text-muted-foreground' }}">{{ $a['etiqueta'] }}</span>
                    <b class="tabular-nums {{ $hay ? 'text-foreground' : 'text-muted-foreground' }}">{{ number_format($a['activos'], 0, ',', '.') }}</b>
                    @if ($hay && $a['total'] !== null && $a['total'] > $a['activos'])
                        <span class="text-muted-foreground">de {{ number_format($a['total'], 0, ',', '.') }}</span>
                    @endif
                    @if ($a['extra'])
                        <span class="rounded-full bg-red-500/15 px-1.5 text-[10px] font-semibold text-red-600 dark:text-red-400">{{ $a['extra'] }}</span>
                    @endif
                </span>
            @endforeach
        </div>
    @endif

    @if (! empty($catalogosFaltantes))
        <div class="flex flex-shrink-0 items-start gap-2 border-b border-amber-500/30 bg-amber-500/10 px-5 py-2.5 text-xs text-amber-700 sm:px-7 dark:text-amber-300">
            <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
            <p><b>Faltan catálogos en la base de datos:</b> {{ implode(', ', $catalogosFaltantes) }}. Esos combos aparecen vacíos y sus valores no se modifican al guardar.</p>
        </div>
    @endif

    {{-- ============ Cuerpo: pasos + formulario ============ --}}
    <div class="flex min-h-0 flex-1 flex-col md:flex-row">

        <nav class="flex-shrink-0 border-b border-border bg-muted/30 md:w-64 md:border-r md:border-b-0">
            <ol class="flex gap-1 overflow-x-auto p-2 md:flex-col md:p-3">
                @foreach ($pasos as $clave => [$titulo, $subtitulo, $icono])
                    <li class="flex-shrink-0">
                        <button type="button" data-step-target="{{ $clave }}"
                                class="group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-background data-[active]:bg-background data-[active]:shadow-sm">
                            <span data-step-badge class="relative flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full border border-border bg-background text-sm text-muted-foreground transition group-data-[active]:border-primary group-data-[active]:bg-primary group-data-[active]:text-primary-foreground">
                                <i class="fa-solid {{ $icono }}"></i>
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
                    <p><i class="fa-regular fa-calendar-plus mr-1.5 w-3"></i>Creado {{ $tercero->datec ? \Illuminate\Support\Carbon::parse($tercero->datec)->format('d/m/Y') : '—' }}@if ($creador) · {{ $creador }}@endif</p>
                    <p><i class="fa-regular fa-pen-to-square mr-1.5 w-3"></i>Modificado {{ $tercero->tms ? \Illuminate\Support\Carbon::parse($tercero->tms)->format('d/m/Y H:i') : '—' }}@if ($modificador) · {{ $modificador }}@endif</p>
                @endunless
                <p><i class="fa-solid fa-hashtag mr-1.5 w-3"></i>ID <span data-ficha-id>{{ $tercero->rowid }}</span></p>
            </div>
        </nav>

        <div class="relative min-h-0 flex-1 overflow-y-auto" data-ficha-scroll>
            @if (!$puedeEditar)
                <div class="mx-5 mt-5 flex items-center gap-2 rounded-xl border border-border bg-muted/50 px-4 py-3 text-sm text-muted-foreground sm:mx-7">
                    <i class="fa-solid fa-lock"></i> Solo lectura: tu usuario no tiene el permiso de Dolibarr para modificar terceros.
                </div>
            @endif

            @if ($porActualizar)
                <div class="mx-5 mt-5 flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-800 sm:mx-7 dark:text-amber-200">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                    <div>
                        <p class="font-medium">Tercero creado desde el POS con datos de Consumidor Final</p>
                        <p class="mt-0.5 text-amber-800/80 dark:text-amber-200/80">Completa ciudad, departamento y clasificación tributaria, y luego desmarca <em>Por actualizar</em>.</p>
                    </div>
                </div>
            @endif

            {{-- ---------- 1. Identificación ---------- --}}
            <form data-step="identificacion" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Identificación', 'descripcion' => 'Datos obligatorios para facturar y reportar a la DIAN.'])

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        <label class="{{ $labelClass }}" for="f_nom">Razón social / Nombre completo <span class="text-destructive">*</span></label>
                        <input id="f_nom" name="nom" value="{{ $tercero->nom }}" maxlength="128" data-required class="{{ $inputClass }}" @disabled($disabled)>
                        <p data-error-for="nom" class="{{ $errorClass }}"></p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="f_siret">Tipo de documento <span class="text-destructive">*</span></label>
                        <select id="f_siret" name="siret" data-required class="{{ $inputClass }}" @disabled($disabled)>
                            <option value="">Selecciona…</option>
                            @foreach ($tiposDocumento as $valor => $etiqueta)
                                <option value="{{ $valor }}" @selected($tercero->siret === $valor)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                        <p data-error-for="siret" class="{{ $errorClass }}"></p>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="{{ $labelClass }}" for="f_siren">Número de documento <span class="text-destructive">*</span></label>
                        <div class="relative">
                            <input id="f_siren" name="siren" value="{{ $tercero->siren }}" maxlength="128" data-required inputmode="numeric" autocomplete="off"
                                   class="{{ $inputClass }} pr-16 font-mono" @disabled($disabled)>
                            <span data-dv class="pointer-events-none absolute inset-y-1 right-1 hidden items-center rounded-md bg-muted px-2 font-mono text-xs text-muted-foreground" title="Dígito de verificación calculado"></span>
                        </div>
                        <p data-error-for="siren" class="{{ $errorClass }}"></p>
                    </div>
                    <div class="sm:col-span-1">
                        <label class="{{ $labelClass }}">Estado</label>
                        <label class="flex h-10 cursor-pointer items-center gap-2 {{ $disabled ? 'pointer-events-none opacity-60' : '' }}">
                            <input type="hidden" name="status" value="0">
                            <input type="checkbox" name="status" value="1" class="peer sr-only" @checked((int) $tercero->status === 1) @disabled($disabled)>
                            <span class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full bg-muted-foreground/30 transition peer-checked:bg-emerald-500 after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-4"></span>
                            <span class="text-sm text-foreground">Activo</span>
                        </label>
                    </div>

                    <div class="sm:col-span-3">
                        <label class="{{ $labelClass }}" for="f_alias">Nombre comercial (alias)</label>
                        <input id="f_alias" name="name_alias" value="{{ $tercero->name_alias }}" maxlength="128" class="{{ $inputClass }}" @disabled($disabled)>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="{{ $labelClass }}" for="f_typent">Tipo de tercero</label>
                        <select id="f_typent" name="fk_typent" class="{{ $inputClass }}" @disabled($disabled)>
                            <option value="">—</option>
                            @foreach ($tiposTercero as $t)
                                <option value="{{ $t->id }}" @selected((int) $tercero->fk_typent === (int) $t->id)>{{ $t->label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="sm:col-span-6">
                        <label class="{{ $labelClass }}">Actividad económica (CIIU)</label>
                        <input type="hidden" name="ape" value="{{ in_array($tercero->ape, ['-1', ''], true) ? '' : $tercero->ape }}">
                        <div data-combo="ape" data-placeholder="Busca por código o descripción…" @if ($disabled) data-disabled @endif></div>
                    </div>

                    @if ($formasJuridicas->isNotEmpty())
                        <div class="sm:col-span-6">
                            <label class="{{ $labelClass }}" for="f_forma">Forma jurídica</label>
                            <select id="f_forma" name="fk_forme_juridique" class="{{ $inputClass }}" @disabled($disabled)>
                                <option value="">—</option>
                                @foreach ($formasJuridicas as $f)
                                    <option value="{{ $f->id }}" @selected((string) $tercero->fk_forme_juridique === (string) $f->id)>{{ $f->label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>

                <div class="mt-6 rounded-xl border border-border p-4">
                    <p class="mb-3 text-sm font-medium text-foreground">Naturaleza</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="{{ $labelClass }}" for="f_client">Relación como cliente</label>
                            <select id="f_client" name="client" class="{{ $inputClass }}" @disabled($disabled)>
                                @foreach ([0 => 'No es cliente', 1 => 'Cliente', 2 => 'Prospecto', 3 => 'Prospecto / Cliente'] as $v => $l)
                                    <option value="{{ $v }}" @selected((int) $tercero->client === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="{{ $labelClass }}" for="f_code_client">Código de cliente</label>
                            <input id="f_code_client" name="code_client" value="{{ $tercero->code_client }}" maxlength="24" class="{{ $inputClass }} font-mono" @disabled($disabled)
                                   @if ($esNuevo) placeholder="Automático (CU{{ now()->format('ym') }}-…)" @endif>
                            <p data-error-for="code_client" class="{{ $errorClass }}"></p>
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">Proveedor</label>
                            <label class="flex h-10 cursor-pointer items-center gap-2 {{ $disabled ? 'pointer-events-none opacity-60' : '' }}">
                                <input type="hidden" name="fournisseur" value="0">
                                <input type="checkbox" name="fournisseur" value="1" class="peer sr-only" @checked((int) $tercero->fournisseur === 1) @disabled($disabled)>
                                <span class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full bg-muted-foreground/30 transition peer-checked:bg-orange-500 after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-4"></span>
                                <span class="text-sm text-foreground">Es proveedor</span>
                            </label>
                        </div>
                        <div>
                            <label class="{{ $labelClass }}" for="f_code_fourn">Código de proveedor</label>
                            <input id="f_code_fourn" name="code_fournisseur" value="{{ $tercero->code_fournisseur }}" maxlength="24" class="{{ $inputClass }} font-mono" @disabled($disabled)
                                   @if ($esNuevo) placeholder="Automático (SU{{ now()->format('ym') }}-…)" @endif>
                            <p data-error-for="code_fournisseur" class="{{ $errorClass }}"></p>
                        </div>
                    </div>
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'identificacion'])
            </form>

            {{-- ---------- 2. Contacto y ubicación ---------- --}}
            <form data-step="contacto" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Contacto y ubicación', 'descripcion' => 'La ciudad define el código DANE que se envía en la factura electrónica.'])

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="f_address">Dirección</label>
                        <input id="f_address" name="address" value="{{ $tercero->address }}" maxlength="255" data-recommended class="{{ $inputClass }}" @disabled($disabled)>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}">Ciudad / Municipio</label>
                        <input type="hidden" name="town" value="{{ ctype_digit((string) $tercero->town) ? $tercero->town : '' }}" data-recommended>
                        <div data-combo="town" data-placeholder="Escribe para buscar la ciudad…" @if ($disabled) data-disabled @endif></div>
                        <p class="mt-1 text-xs text-muted-foreground">Código DANE: <span data-zip class="font-mono">{{ $tercero->zip ?: '—' }}</span></p>
                        <p data-error-for="town" class="{{ $errorClass }}"></p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Departamento</label>
                        <input type="hidden" name="fk_departement" value="{{ $tercero->fk_departement ?: '' }}">
                        <div data-combo="fk_departement" data-placeholder="Departamento" @if ($disabled) data-disabled @endif></div>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">País</label>
                        <input type="hidden" name="fk_pays" value="{{ $tercero->fk_pays ?: 70 }}">
                        <div data-combo="fk_pays" data-placeholder="País" @if ($disabled) data-disabled @endif></div>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-4 border-t border-border pt-6 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="f_email">Correo electrónico</label>
                        <div class="relative">
                            <i class="fa-regular fa-envelope pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"></i>
                            <input id="f_email" type="email" name="email" value="{{ $tercero->email }}" maxlength="128" data-recommended class="{{ $inputClass }} pl-9" placeholder="facturacion@empresa.com" @disabled($disabled)>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">Aquí llega la factura electrónica.</p>
                        <p data-error-for="email" class="{{ $errorClass }}"></p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="f_url">Sitio web</label>
                        <div class="relative">
                            <i class="fa-solid fa-globe pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"></i>
                            <input id="f_url" name="url" value="{{ $tercero->url }}" maxlength="255" class="{{ $inputClass }} pl-9" placeholder="www.empresa.com" @disabled($disabled)>
                        </div>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="f_phone">Teléfono</label>
                        <div class="relative">
                            <i class="fa-solid fa-phone pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"></i>
                            <input id="f_phone" type="tel" name="phone" value="{{ $tercero->phone }}" maxlength="30" data-recommended class="{{ $inputClass }} pl-9" @disabled($disabled)>
                        </div>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="f_mobile">Celular / WhatsApp</label>
                        <div class="relative">
                            <i class="fa-brands fa-whatsapp pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"></i>
                            <input id="f_mobile" type="tel" name="phone_mobile" value="{{ $tercero->phone_mobile }}" maxlength="30" class="{{ $inputClass }} pl-9" @disabled($disabled)>
                        </div>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="f_fax">Fax</label>
                        <input id="f_fax" type="tel" name="fax" value="{{ $tercero->fax }}" maxlength="30" class="{{ $inputClass }}" @disabled($disabled)>
                    </div>
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'contacto'])
            </form>

            {{-- ---------- 3. Comercial y crédito ---------- --}}
            <form data-step="comercial" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Comercial y crédito', 'descripcion' => 'Condiciones con las que se le vende o se le compra.'])

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @if (!empty($nivelesPrecio))
                        <div>
                            <label class="{{ $labelClass }}" for="f_price_level">Lista de precios</label>
                            <select id="f_price_level" name="price_level" class="{{ $inputClass }}" @disabled($disabled)>
                                <option value="">Por defecto</option>
                                @foreach ($nivelesPrecio as $n => $l)
                                    <option value="{{ $n }}" @selected((int) $tercero->price_level === $n)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <label class="{{ $labelClass }}" for="f_remise">Descuento general</label>
                        <div class="relative">
                            <input id="f_remise" type="number" step="0.01" min="0" max="100" name="remise_client" value="{{ (float) $tercero->remise_client ?: '' }}"
                                   class="{{ $inputClass }} pr-8 text-right tabular-nums" placeholder="0" @disabled($disabled)>
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">%</span>
                        </div>
                        <p data-error-for="remise_client" class="{{ $errorClass }}"></p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="f_mode">Medio de pago</label>
                        <select id="f_mode" name="mode_reglement" class="{{ $inputClass }}" @disabled($disabled)>
                            <option value="">—</option>
                            @foreach ($mediosPago as $m)
                                <option value="{{ $m->id }}" @selected((int) $tercero->mode_reglement === (int) $m->id)>{{ $m->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="f_cond">Término de pago</label>
                        <select id="f_cond" name="cond_reglement" class="{{ $inputClass }}" @disabled($disabled)>
                            <option value="">—</option>
                            @foreach ($terminosPago as $t)
                                <option value="{{ $t->id }}" @selected((int) $tercero->cond_reglement === (int) $t->id)>{{ $t->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="f_outstanding">Límite de crédito (Dolibarr)</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted-foreground">$</span>
                            <input id="f_outstanding" type="number" step="0.01" min="0" name="outstanding_limit" value="{{ $tercero->outstanding_limit !== null ? (float) $tercero->outstanding_limit : '' }}"
                                   class="{{ $inputClass }} pl-7 text-right tabular-nums" @disabled($disabled)>
                        </div>
                        <p data-error-for="outstanding_limit" class="{{ $errorClass }}"></p>
                    </div>
                </div>

                @if ($efCredito->isNotEmpty())
                    <div class="mt-6 border-t border-border pt-6">
                        <p class="mb-3 text-sm font-medium text-foreground"><i class="fa-solid fa-credit-card mr-1.5 text-muted-foreground"></i>Cupo de crédito POS</p>
                        @include('panel.partials.ficha._extrafields', ['defs' => $efCredito])
                    </div>
                @endif

                @include('panel.partials.ficha._paso-footer', ['clave' => 'comercial'])
            </form>

            {{-- ---------- 4. Tributario ---------- --}}
            <form data-step="tributario" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Tributario', 'descripcion' => 'Define cómo se calculan IVA y retenciones en sus facturas.'])

                <label class="mb-5 flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-background p-3.5 transition hover:border-primary/40 has-[:checked]:border-primary/50 has-[:checked]:bg-primary/5 {{ $disabled ? 'pointer-events-none opacity-60' : '' }}">
                    <input type="hidden" name="tva_assuj" value="0">
                    <input type="checkbox" name="tva_assuj" value="1" class="peer sr-only" @checked((int) $tercero->tva_assuj === 1) @disabled($disabled)>
                    <span class="relative mt-0.5 inline-flex h-5 w-9 flex-shrink-0 rounded-full bg-muted-foreground/30 transition peer-checked:bg-primary after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-4"></span>
                    <span>
                        <span class="block text-sm font-medium text-foreground">Sujeto a IVA</span>
                        <span class="block text-xs text-muted-foreground">Desmárcalo si el tercero no es responsable de IVA.</span>
                    </span>
                </label>

                @include('panel.partials.ficha._extrafields', ['defs' => $efTributario])

                @include('panel.partials.ficha._paso-footer', ['clave' => 'tributario'])
            </form>

            {{-- ---------- Categorías ---------- --}}
            <form data-step="categorias" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Categorías', 'descripcion' => 'Etiquetas para agrupar al tercero en los listados, filtros e informes.'])

                <section class="mb-7">
                    <h4 class="mb-1 flex items-center gap-2 text-sm font-semibold text-foreground"><i class="fa-solid fa-users text-muted-foreground"></i> Como cliente</h4>
                    @unless ($esCliente)
                        <p class="mb-2 text-xs text-amber-700 dark:text-amber-300">Este tercero no está marcado como cliente o cliente potencial (paso Identificación).</p>
                    @endunless
                    @include('panel.partials.ficha._categorias', [
                        'categorias' => $categoriasCliente,
                        'seleccionadas' => $categoriasClienteDe,
                        'name' => 'categorias_cliente[]',
                        'titulo' => 'Categorías de cliente asignadas',
                        'vacio' => 'No hay categorías de cliente creadas.',
                    ])
                </section>

                <section>
                    <h4 class="mb-1 flex items-center gap-2 text-sm font-semibold text-foreground"><i class="fa-solid fa-truck text-muted-foreground"></i> Como proveedor</h4>
                    @unless ($esProveedor)
                        <p class="mb-2 text-xs text-amber-700 dark:text-amber-300">Este tercero no está marcado como proveedor (paso Identificación).</p>
                    @endunless
                    @include('panel.partials.ficha._categorias', [
                        'categorias' => $categoriasProveedor,
                        'seleccionadas' => $categoriasProveedorDe,
                        'name' => 'categorias_proveedor[]',
                        'titulo' => 'Categorías de proveedor asignadas',
                        'vacio' => 'No hay categorías de proveedor creadas.',
                    ])
                </section>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'categorias'])
            </form>

            {{-- ---------- 5. Campos adicionales ---------- --}}
            @if ($efOtros->isNotEmpty())
                <form data-step="adicionales" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                    @include('panel.partials.ficha._paso-header', ['titulo' => 'Campos adicionales', 'descripcion' => 'Campos extra configurados en Dolibarr para los terceros.'])
                    @include('panel.partials.ficha._extrafields', ['defs' => $efOtros])
                    @include('panel.partials.ficha._paso-footer', ['clave' => 'adicionales'])
                </form>
            @endif

            {{-- ---------- 6. Notas ---------- --}}
            <form data-step="notas" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Notas', 'descripcion' => 'La nota pública puede salir en documentos; la privada solo la ve tu equipo.'])

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="f_note_public"><i class="fa-solid fa-eye mr-1"></i> Nota pública</label>
                        <textarea id="f_note_public" name="note_public" rows="8" class="{{ $inputClass }} h-auto py-2" @disabled($disabled)>{{ $tercero->note_public }}</textarea>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="f_note_private"><i class="fa-solid fa-lock mr-1"></i> Nota privada</label>
                        <textarea id="f_note_private" name="note_private" rows="8" class="{{ $inputClass }} h-auto py-2" @disabled($disabled)>{{ $tercero->note_private }}</textarea>
                    </div>
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'notas'])
            </form>
        </div>
    </div>

    {{-- Catálogos para los combos con búsqueda (Syncfusion DropDownList) --}}
    <script type="application/json" data-ficha-catalogos>{!! json_encode($catalogos, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
</div>
