{{--
    Ficha de la solicitud en panel lateral, como asistente por pasos (ver solicitud-ficha.js y ficha-modal.js).
    Con $esNuevo el primer paso crea la solicitud (genera la referencia RQ…) y desbloquea los demás.
--}}
@php
    $labelClass = 'mb-1.5 block text-xs font-medium text-muted-foreground';
    $inputClass = 'w-full h-10 rounded-lg border border-input bg-background px-3 text-sm text-foreground shadow-xs transition placeholder:text-muted-foreground/60 focus:border-primary focus:outline-none focus:ring-3 focus:ring-primary/15 disabled:cursor-not-allowed disabled:opacity-60';
    $areaClass = 'w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground shadow-xs transition placeholder:text-muted-foreground/60 focus:border-primary focus:outline-none focus:ring-3 focus:ring-primary/15 disabled:cursor-not-allowed disabled:opacity-60';
    $errorClass = 'mt-1 hidden text-xs text-destructive';
    $switchClass = 'relative inline-flex h-5 w-9 flex-shrink-0 rounded-full bg-muted-foreground/30 transition after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow after:transition peer-checked:bg-primary peer-checked:after:translate-x-4';

    $pasos = [
        'general' => ['Solicitud', 'Cliente, contacto y fechas', 'fa-inbox'],
        'productos' => ['Productos', count($lineas).' línea'.(count($lineas) === 1 ? '' : 's'), 'fa-boxes-stacked'],
        'detalle' => ['Detalle y correo', 'Mensaje al cliente', 'fa-envelope'],
    ];
    if (! $esNuevo) {
        $pasos['estado'] = ['Estado', $estados[(string) $sol->status] ?? 'Estado '.$sol->status, 'fa-clock-rotate-left'];
    }
    // Cada paso lleva su color: el de la solicitud en el primero y uno propio en los demás.
    $coloresPasos = ['general' => $color, 'productos' => '#f59e0b', 'detalle' => '#0ea5e9', 'estado' => '#8b5cf6'];
    $clavesPasos = array_keys($pasos);
    $disabled = ! $puedeEditar;
    $cerrada = ! $esNuevo && (int) $sol->status === 9;
    $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('Y-m-d') : '';
    $num = fn ($v) => $v !== null && $v !== '' ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : '';
    $plantillaDefecto = collect($catalogos['plantillas'])->first(fn ($t) => mb_strtolower($t['label']) === 'respuesta solicitud cliente');
    $detalleInicial = $esNuevo ? '' : (string) $sol->detalle;
@endphp

<div data-ficha data-puede-editar="{{ $puedeEditar ? '1' : '0' }}" class="flex h-full flex-col"
     data-contactos-url="{{ route('panel.solicitudes.contactos') }}" data-productos-url="{{ route('panel.solicitudes.productos') }}" data-cliente-resumen-url="{{ route('panel.solicitudes.cliente-resumen') }}" data-cliente-meses-url="{{ route('panel.solicitudes.cliente-meses') }}" data-inteligencia-url="{{ route('panel.solicitudes.inteligencia') }}"
     @if ($esNuevo) data-nuevo="1" data-store-url="{{ route('panel.solicitudes.store') }}"
     @else data-update-url="{{ route('panel.solicitudes.update', $sol->rowid) }}" data-cerrar-url="{{ route('panel.solicitudes.cerrar', $sol->rowid) }}" data-reabrir-url="{{ route('panel.solicitudes.reabrir', $sol->rowid) }}" @endif>

    {{-- ============ Cabecera ============ --}}
    <header class="relative flex-shrink-0 overflow-hidden border-b border-border px-5 pt-5 pb-4 sm:px-7"
            style="background: linear-gradient(135deg, color-mix(in srgb, {{ $color }} 16%, var(--card, #fff)), var(--card, #fff) 70%)">
        <button type="button" data-ficha-close title="Cerrar (Esc)"
                class="absolute top-4 right-4 flex h-9 w-9 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
        <div class="flex min-w-0 items-center gap-4 pr-10">
            <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl text-xl text-white shadow-md" style="background: {{ $color }}">
                <i class="fa-solid {{ $esNuevo ? 'fa-plus' : 'fa-inbox' }}"></i>
            </div>
            <div class="min-w-0">
                <h2 data-ficha-titulo class="truncate text-lg font-semibold text-foreground sm:text-xl">{{ $esNuevo ? 'Nueva solicitud' : $sol->ref }}</h2>
                @if ($esNuevo)
                    <p class="mt-1 text-sm text-muted-foreground">Elige el cliente y el contacto para crearla; la referencia se genera sola. Luego agrega los productos y el mensaje.</p>
                @else
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm text-muted-foreground">
                        <span class="truncate text-foreground/80">{{ $sol->cliente }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $cerrada ? 'bg-rose-500/15 text-rose-700 dark:text-rose-300' : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' }}">{{ $estados[(string) $sol->status] ?? 'Estado '.$sol->status }}</span>
                    </div>
                @endif
            </div>
        </div>
    </header>

    {{-- ============ Cuerpo: pasos + formulario ============ --}}
    <div class="flex min-h-0 flex-1 flex-col md:flex-row">
        <nav class="flex-shrink-0 border-b border-border bg-muted/30 md:w-64 md:border-r md:border-b-0">
            <ol class="flex gap-1 overflow-x-auto p-2 md:flex-col md:p-3">
                @foreach ($pasos as $clave => [$titulo, $subtitulo, $iconoPaso])
                    <li class="flex-shrink-0">
                        <button type="button" data-step-target="{{ $clave }}" style="--pc: {{ $coloresPasos[$clave] }}"
                                class="paso-color group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-background data-[active]:bg-background data-[active]:shadow-sm">
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
            @unless ($esNuevo)
                <div class="space-y-1 px-5 pt-3 pb-5 text-xs text-muted-foreground max-md:hidden">
                    <p><i class="fa-regular fa-calendar-plus mr-1.5 w-3"></i>Creada {{ \Illuminate\Support\Carbon::parse($sol->date_creation)->format('d/m/Y') }}@if ($creador) · {{ $creador }}@endif</p>
                    <p><i class="fa-solid fa-hashtag mr-1.5 w-3"></i>ID {{ $sol->rowid }}</p>
                </div>
            @endunless
        </nav>

        <div class="relative min-h-0 flex-1 overflow-y-auto" data-ficha-scroll>
            @if (! $puedeEditar)
                <div class="mx-5 mt-5 flex items-center gap-2 rounded-xl border border-border bg-muted/50 px-4 py-3 text-sm text-muted-foreground sm:mx-7">
                    <i class="fa-solid fa-lock"></i>
                    {{ $cerrada ? 'La solicitud está cerrada: reábrela desde el paso Estado para modificarla.' : 'Solo lectura: tu usuario no tiene el permiso de Dolibarr para modificar solicitudes.' }}
                </div>
            @endif

            {{-- ---------- 1. Solicitud ---------- --}}
            <form data-step="general" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Datos de la solicitud', 'descripcion' => 'Quién la solicita, a qué contacto se responde y cuándo.'])

                <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(15rem,1fr)]">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}">Cliente <span class="text-destructive">*</span></label>
                        <input type="hidden" name="fk_soc" value="{{ $esNuevo ? '' : $sol->fk_soc }}" data-required>
                        <div data-combo="fk_soc" data-placeholder="Busca el cliente…" @if ($disabled) data-disabled @endif></div>
                        <p data-error-for="fk_soc" class="{{ $errorClass }}"></p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}">Contacto principal <span class="text-destructive">*</span></label>
                        <input type="hidden" name="contacto_id" value="{{ $esNuevo ? '' : $sol->contacto_id }}" data-required>
                        <div data-combo="contacto_id" data-placeholder="Primero elige el cliente" @if ($disabled) data-disabled @endif></div>
                        <p data-error-for="contacto_id" class="{{ $errorClass }}"></p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}">Otros contactos de la solicitud</label>
                        <div data-relacionados data-seleccion='@json($relacionados)' @if ($disabled) data-disabled @endif
                             class="max-h-44 space-y-1 overflow-y-auto rounded-lg border border-input bg-background p-2 text-sm">
                            <p class="px-1 py-1 text-xs text-muted-foreground">Elige el cliente para ver sus contactos.</p>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">Reciben también el correo y quedan ligados a la solicitud. El contacto principal siempre se incluye.</p>
                    </div>

                    <div>
                        <label class="{{ $labelClass }}" for="s_fecha">Fecha de la solicitud <span class="text-destructive">*</span></label>
                        <input id="s_fecha" type="date" name="fecha_solicitud" value="{{ $esNuevo ? now()->format('Y-m-d') : $fecha($sol->fecha_solicitud) }}" data-required class="{{ $inputClass }}" @disabled($disabled)>
                        <p data-error-for="fecha_solicitud" class="{{ $errorClass }}"></p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="s_proximo">Próximo contacto</label>
                        <input id="s_proximo" type="date" name="fecha_proximo_contacto" value="{{ $esNuevo ? '' : $fecha($sol->fecha_proximo_contacto) }}" class="{{ $inputClass }}" @disabled($disabled)>
                        <p class="mt-1 text-xs text-muted-foreground">Si la indicas, queda un recordatorio en el programador de actividades.</p>
                        <p data-error-for="fecha_proximo_contacto" class="{{ $errorClass }}"></p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="s_asunto">Asunto (bitácora) <span class="text-destructive">*</span></label>
                        <textarea id="s_asunto" name="asunto" rows="3" maxlength="150" data-required autocomplete="off"
                               placeholder="Campo requerido para la bitácora. 150 caracteres máximo." class="{{ $areaClass }}" @disabled($disabled)>{{ $esNuevo ? '' : strip_tags($sol->asunto) }}</textarea>
                        <p data-error-for="asunto" class="{{ $errorClass }}"></p>
                    </div>
                </div>

                {{-- Importancia del cliente: se llena al elegir el cliente; clic = resumen por meses --}}
                <aside data-cliente-importancia class="rounded-2xl border border-border bg-muted/30 p-4 text-sm lg:sticky lg:top-0">
                    <p class="text-xs text-muted-foreground">Elige el cliente para ver su importancia histórica.</p>
                </aside>
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'general'])
            </form>

            {{-- ---------- 2. Productos ---------- --}}
            <form data-step="productos" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Productos solicitados', 'descripcion' => 'Lo que pide el cliente: descripción, modelo y cantidad. El producto del catálogo es opcional.'])

                @if ($puedeEditar)
                    <div class="relative mb-4">
                        <label class="{{ $labelClass }}">Agregar producto</label>
                        <div class="relative">
                            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-xs text-muted-foreground"></i>
                            <input type="text" data-producto-buscar autocomplete="off" placeholder="Busca por referencia o nombre y presiona Enter para agregar sin catálogo…" class="{{ $inputClass }} pl-9">
                        </div>
                    </div>
                @endif

                <div class="overflow-x-auto rounded-xl border border-border bg-card shadow-xs">
                    <table data-tabla-productos class="w-full min-w-[34rem] text-left">
                        <thead class="bg-muted/50 text-xs font-medium text-muted-foreground">
                            <tr>
                                <th data-col-producto class="px-5 py-2">Producto</th>
                                <th data-col-ia class="hidden border-l border-border px-3 py-2">Precio promedio</th>
                                <th data-col-ia class="hidden border-l border-border px-3 py-2"><i class="fa-solid fa-wand-magic-sparkles mr-1 text-primary"></i>Análisis IA</th>
                                <th class="w-12 px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody data-lineas>
                            @foreach ($lineas as $i => $l)
                                @include('panel.solicitudes.partials._linea', ['i' => $i, 'l' => $l, 'disabled' => $disabled, 'inputClass' => $inputClass, 'areaClass' => $areaClass, 'labelClass' => $labelClass])
                            @endforeach
                        </tbody>
                    </table>
                    <p data-sin-lineas class="{{ count($lineas) ? 'hidden' : '' }} px-4 py-8 text-center text-sm text-muted-foreground">Aún no hay productos en esta solicitud.</p>
                </div>
                <p data-error-for="lineas" class="{{ $errorClass }}"></p>

                @if ($puedeEditar)
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button type="button" data-analizar-ia class="inline-flex h-9 items-center gap-2 rounded-lg bg-primary px-3 text-sm font-medium text-primary-foreground transition hover:opacity-90 disabled:opacity-50">
                        <i class="fa-solid fa-wand-magic-sparkles text-xs"></i> Analizar con IA
                    </button>
                    <span class="text-xs text-muted-foreground">Precios históricos (USD y COP), cotizaciones, órdenes y dónde conseguirlo.</span>
                </div>
                @endif
                

                <template data-plantilla-linea>
                    <table><tbody>@include('panel.solicitudes.partials._linea', ['i' => '__I__', 'l' => null, 'disabled' => false, 'inputClass' => $inputClass, 'areaClass' => $areaClass, 'labelClass' => $labelClass])</tbody></table>
                </template>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'productos'])
            </form>

            {{-- ---------- 3. Detalle y correo ---------- --}}
            <form data-step="detalle" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Detalle y correo', 'descripcion' => 'El mensaje de la solicitud; puedes enviarlo por correo a los contactos.'])

                <div class="grid grid-cols-1 gap-4">
                    @if ($puedeEditar && count($catalogos['plantillas']))
                        <div>
                            <label class="{{ $labelClass }}" for="s_plantilla">Plantilla de correo</label>
                            <select id="s_plantilla" data-plantilla class="{{ $inputClass }}">
                                <option value="">— Sin plantilla —</option>
                                @foreach ($catalogos['plantillas'] as $t)
                                    <option value="{{ $t['id'] }}" @selected($esNuevo && $plantillaDefecto && $plantillaDefecto['id'] === $t['id'])>{{ $t['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @if ($puedeEditar)
                        <div class="rounded-xl border border-border bg-muted/30 p-4">
                            <div class="mb-1 flex items-center gap-2 text-sm font-medium text-foreground"><i class="fa-solid fa-wand-magic-sparkles text-primary"></i> Redactar con IA</div>
                            <p class="mb-3 text-xs text-muted-foreground">Escribe el correo a partir de lo que pidió el cliente (asunto y productos de la solicitud). Si eliges una plantilla o ya hay texto abajo, la IA lo toma como base y lo adapta. Puedes pedirle algo concreto.</p>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <input type="text" data-ia-instrucciones maxlength="1000" autocomplete="off" placeholder="Opcional. Ej.: pide la ficha técnica, indica que cotizamos esta semana…" class="{{ $inputClass }}">
                                <button type="button" data-redactar-ia class="inline-flex h-10 flex-shrink-0 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground transition hover:opacity-90 disabled:opacity-50">
                                    <i class="fa-solid fa-wand-magic-sparkles text-xs"></i> Redactar con IA
                                </button>
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="{{ $labelClass }}">Detalle</label>
                        <input type="hidden" name="detalle" value="{{ $detalleInicial }}">
                        <div class="overflow-hidden rounded-lg border border-input bg-background focus-within:border-primary focus-within:ring-3 focus-within:ring-primary/15">
                            @unless ($disabled)
                                <div class="flex flex-wrap gap-1 border-b border-border bg-muted/40 p-1.5">
                                    @foreach ([['bold', 'fa-bold', 'Negrita'], ['italic', 'fa-italic', 'Cursiva'], ['underline', 'fa-underline', 'Subrayado'], ['insertUnorderedList', 'fa-list-ul', 'Lista'], ['insertOrderedList', 'fa-list-ol', 'Lista numerada'], ['createLink', 'fa-link', 'Enlace'], ['removeFormat', 'fa-eraser', 'Quitar formato']] as [$cmd, $ico, $tit])
                                        <button type="button" data-editor-cmd="{{ $cmd }}" title="{{ $tit }}" class="flex h-8 w-8 items-center justify-center rounded-md text-sm text-muted-foreground transition hover:bg-background hover:text-foreground"><i class="fa-solid {{ $ico }}"></i></button>
                                    @endforeach
                                </div>
                            @endunless
                            <div data-editor contenteditable="{{ $disabled ? 'false' : 'true' }}" class="prose-sm min-h-56 max-h-[28rem] overflow-y-auto px-3 py-2 text-sm text-foreground outline-none [&_a]:text-primary [&_a]:underline [&_ol]:ml-5 [&_ol]:list-decimal [&_ul]:ml-5 [&_ul]:list-disc"></div>
                        </div>
                        <p data-error-for="detalle" class="{{ $errorClass }}"></p>
                    </div>

                    @if ($puedeEditar)
                        <div class="rounded-xl border border-border bg-muted/30 p-4">
                            <label class="flex cursor-pointer items-center gap-3">
                                <input type="hidden" name="enviar_correo" value="0">
                                <span class="relative inline-flex">
                                    <input type="checkbox" name="enviar_correo" value="1" data-enviar-correo class="peer sr-only">
                                    <span class="{{ $switchClass }}"></span>
                                </span>
                                <span class="text-sm font-medium text-foreground">Enviar el detalle por correo al guardar</span>
                            </label>
                            <div data-correo class="mt-3 hidden">
                                <label class="{{ $labelClass }}" for="s_asunto_correo">Asunto del correo</label>
                                <input id="s_asunto_correo" name="asunto_correo" value="SOLICITUD CREADA __REF__" maxlength="250" class="{{ $inputClass }}">
                                <p class="mt-1 text-xs text-muted-foreground">__REF__ se reemplaza por la referencia. Se envía al contacto principal y a los demás contactos con correo.</p>
                                <p data-error-for="asunto_correo" class="{{ $errorClass }}"></p>
                            </div>
                            @if (! $esNuevo && $sol->envio_email)
                                <p class="mt-2 text-xs text-muted-foreground"><i class="fa-solid fa-circle-check mr-1 text-emerald-600"></i>Esta solicitud ya tiene un correo enviado.</p>
                            @endif
                        </div>
                    @endif
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'detalle'])
            </form>

            {{-- ---------- 4. Estado ---------- --}}
            @unless ($esNuevo)
                <form data-step="estado" class="hidden min-h-full flex-col p-5 sm:p-7" onsubmit="return false">
                    @include('panel.partials.ficha._paso-header', ['titulo' => 'Estado', 'descripcion' => 'Cierra o reabre la solicitud y consulta su historial.'])

                    <div class="rounded-xl border border-border bg-muted/30 p-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $cerrada ? 'bg-rose-500/15 text-rose-700 dark:text-rose-300' : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' }}">{{ $estados[(string) $sol->status] ?? 'Estado '.$sol->status }}</span>
                            @if ($cerrada && $motivo)
                                <span class="text-sm text-muted-foreground">Motivo de cierre: <strong class="text-foreground">{{ $motivo }}</strong></span>
                            @endif
                            @if ($sol->fecha_reapertura)
                                <span class="text-xs text-muted-foreground">Reabierta {{ \Illuminate\Support\Carbon::parse($sol->fecha_reapertura)->format('d/m/Y H:i') }}</span>
                            @endif
                        </div>

                        @if ($puedeCerrar && (int) $sol->status === 1)
                            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                                <div>
                                    <label class="{{ $labelClass }}">Motivo de cierre</label>
                                    <input type="hidden" name="motivo" value="">
                                    <div data-combo="motivo" data-placeholder="Elige el motivo…"></div>
                                </div>
                                <button type="button" data-cerrar class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-destructive px-4 text-sm font-medium text-white shadow-sm transition hover:opacity-90">
                                    <i class="fa-solid fa-circle-xmark"></i> Cerrar solicitud
                                </button>
                            </div>
                        @elseif ($puedeCerrar && $cerrada)
                            <button type="button" data-reabrir class="mt-4 inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow-sm transition hover:opacity-90">
                                <i class="fa-solid fa-rotate-left"></i> Reabrir solicitud
                            </button>
                        @endif
                    </div>

                    <h4 class="mt-6 mb-2 text-sm font-semibold text-foreground">Historial de estados</h4>
                    <ol class="space-y-2">
                        @forelse ($historial as $h)
                            <li class="flex items-center gap-3 rounded-lg border border-border px-3 py-2 text-sm">
                                <i class="fa-solid fa-circle-dot text-primary"></i>
                                <span class="font-medium text-foreground">{{ $h->estado }}</span>
                                <span class="ml-auto text-xs text-muted-foreground">{{ \Illuminate\Support\Carbon::parse($h->fecha)->format('d/m/Y H:i') }}@if ($h->usuario) · {{ $h->usuario }}@endif</span>
                            </li>
                        @empty
                            <li class="text-sm text-muted-foreground">Sin movimientos registrados.</li>
                        @endforelse
                    </ol>

                    <div class="h-8 flex-shrink-0"></div>
                    <div class="sticky bottom-0 -mx-5 mt-auto -mb-5 flex items-center gap-3 border-t border-border bg-card/95 px-5 py-3 backdrop-blur sm:-mx-7 sm:-mb-7 sm:px-7">
                        <button type="button" data-step-prev class="inline-flex h-9 items-center gap-2 rounded-lg px-3 text-sm font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground">
                            <i class="fa-solid fa-arrow-left text-xs"></i> Anterior
                        </button>
                        <button type="button" data-ficha-close class="ml-auto inline-flex h-9 items-center rounded-lg border border-border px-4 text-sm font-medium text-foreground transition hover:bg-muted">Cerrar</button>
                    </div>
                </form>
            @endunless
        </div>
    </div>

    {{-- Catálogos para los combos con búsqueda (Syncfusion DropDownList) --}}
    <script type="application/json" data-ficha-catalogos>{!! json_encode(['fk_soc' => $catalogos['clientes'], 'contacto_id' => $contactos, 'motivo' => $catalogos['motivos'], 'plantillas' => $catalogos['plantillas']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
</div>
