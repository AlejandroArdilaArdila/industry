{{--
    Panel lateral (media pantalla) del programador de actividades. Sigue el contrato de FichaModal
    (resources/js/panel/ficha-modal.js): un [data-ficha] con form[data-step] y [data-ficha-scroll].
    Sin $doc: búsqueda del documento al que se le programa la actividad.
    Con $doc: contexto del documento, formulario de seguimiento/recordatorio e historial.
--}}
@php
    $labelClass = 'mb-1.5 block text-xs font-medium text-muted-foreground';
    $inputClass = 'w-full h-10 rounded-lg border border-input bg-background px-3 text-sm text-foreground shadow-xs transition placeholder:text-muted-foreground/60 focus:border-primary focus:outline-none focus:ring-3 focus:ring-primary/15 disabled:cursor-not-allowed disabled:opacity-60';
    $errorClass = 'mt-1 hidden text-xs text-destructive';
    $color = $doc ? ($colores[$doc['card']]['fondo'] ?? '#2563eb') : null;
    $dinero = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
    $fmt = fn ($f, $formato = 'd/m/Y H:i') => \Illuminate\Support\Carbon::parse($f)->format($formato);
@endphp

<div data-ficha data-puede-editar="1" class="flex h-full min-h-0 flex-col" data-store-url="{{ route('panel.actividades.store') }}"
     data-estado-url="{{ route('panel.actividades.estado', ['evento' => '__ID__']) }}"
     data-docs-url="{{ route('panel.actividades.documentos') }}" data-meses-url="{{ route('panel.actividades.cliente-meses') }}" data-panel-url="{{ route('panel.actividades.panel') }}"
     data-colores="{{ json_encode($colores) }}">
    <script type="application/json" data-ficha-catalogos>{}</script>

    {{-- ============ Cabecera ============ --}}
    <header class="relative flex-shrink-0 overflow-hidden border-b border-border px-5 pt-5 pb-4 sm:px-7"
            @if ($doc) style="--c: {{ $color }}; background-image: linear-gradient(135deg, color-mix(in oklab, {{ $color }} 16%, transparent), transparent 70%)" @endif>
        <button type="button" data-ficha-close title="Cerrar (Esc)"
                class="absolute top-4 right-4 flex h-9 w-9 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
        <div class="flex items-center gap-4 pr-10">
            <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl text-xl text-white shadow-md"
                 style="background: {{ $color ?? 'var(--primary)' }}">
                <i class="fa-solid {{ $doc['icono'] ?? 'fa-calendar-plus' }}"></i>
            </div>
            <div class="min-w-0">
                <h2 data-ficha-titulo class="truncate text-lg font-semibold text-foreground sm:text-xl">
                    {{ $doc ? $doc['ref'].' · '.$doc['empresa'] : 'Programar una actividad' }}
                </h2>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm text-muted-foreground">
                    @if ($doc)
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium text-white" style="background: {{ $color }}">{{ $doc['moduloNombre'] }}</span>
                        <span class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium">{{ $doc['estado'] }}</span>
                        @if ($doc['valor'])<span class="text-xs font-semibold text-foreground">{{ $dinero($doc['valor']) }}</span>@endif
                        @unless ($fijo)
                            <button type="button" data-cambiar-doc class="text-xs font-semibold text-primary hover:underline"><i class="fa-solid fa-right-left"></i> Cambiar documento</button>
                        @endunless
                    @else
                        <span>Elige la solicitud, cotización u orden y registra el seguimiento.</span>
                    @endif
                </div>
            </div>
        </div>
    </header>

    <div data-ficha-scroll class="min-h-0 flex-1 overflow-y-auto">

    @if (! $doc)
        {{-- ============ Búsqueda de documento ============ --}}
        <form data-step="buscar" class="flex min-h-full flex-col p-5 sm:p-7" novalidate onsubmit="return false">
            <div class="mb-4 flex flex-wrap gap-2" data-buscar-tipos>
                @foreach ([null => 'Todos', 'solicitud' => 'Solicitudes', 'cotizacion' => 'Cotizaciones', 'orden' => 'Órdenes de compra'] as $clave => $texto)
                    <button type="button" data-tipo="{{ $clave }}" class="act-pill {{ $clave === null ? 'on' : '' }}">{{ $texto }}</button>
                @endforeach
            </div>
            <div class="relative mb-4">
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"></i>
                <input type="search" data-buscar-q autocomplete="off" placeholder="Busca por referencia o por cliente…" class="{{ $inputClass }} pl-9">
            </div>
            <p class="mb-2 text-xs text-muted-foreground" data-buscar-info>Tus documentos activos más recientes.</p>
            <div data-buscar-resultados class="grid grid-cols-1 gap-2 lg:grid-cols-2"></div>
        </form>
    @else
        {{-- ============ Seguimiento ============ --}}
        <form data-step="seguimiento" class="flex min-h-full flex-col p-5 sm:p-7" novalidate
              data-modulo="{{ $doc['modulo'] }}" data-id="{{ $doc['id'] }}" data-ref="{{ $doc['ref'] }}">
            <div class="mb-5 grid grid-cols-2 gap-4 rounded-2xl border border-border bg-muted/30 p-4 text-sm">
                <div class="grid min-w-0 grid-cols-1 content-start gap-3 sm:grid-cols-2">
                    <div class="min-w-0"><p class="text-[11px] uppercase tracking-wide text-muted-foreground">Cliente</p><p class="truncate font-medium" title="{{ $doc['empresa'] }}">{{ $doc['empresa'] }}</p></div>
                    <div><p class="text-[11px] uppercase tracking-wide text-muted-foreground">Creado</p><p class="font-medium">{{ $doc['fecha'] ? $fmt($doc['fecha'], 'd/m/Y') : '—' }}</p></div>
                    <div><p class="text-[11px] uppercase tracking-wide text-muted-foreground">Valor</p><p class="font-medium">{{ $doc['valor'] ? $dinero($doc['valor']) : '—' }}</p></div>
                    <div class="min-w-0"><p class="text-[11px] uppercase tracking-wide text-muted-foreground">Asunto</p><p class="truncate font-medium" title="{{ $doc['titulo'] }}">{{ $doc['titulo'] ?: '—' }}</p></div>
                </div>
                @if (! empty($cliente))
                    @php
                        $barras = [['Solicitudes', $cliente['solicitudes'], '#3b82f6'], ['Cotizaciones', $cliente['cotizaciones'], '#f59e0b'], ['Órdenes', $cliente['ordenes'], '#10b981']];
                        $max = max(1, ...array_column($barras, 1));
                    @endphp
                    {{-- Peso del cliente: cuántos documentos tiene y por cuánto --}}
                    <div class="min-w-0 cursor-pointer rounded-lg border-l border-border pl-4 transition hover:bg-muted/60" data-cliente-meses role="button" tabindex="0" title="Ver el resumen por meses">
                        <p class="mb-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">Importancia del cliente <span class="normal-case">(histórico)</span> <i class="fa-solid fa-chart-column float-right text-primary"></i></p>
                        <div class="space-y-1">
                            @foreach ($barras as [$nombre, $n, $color])
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="w-[78px] shrink-0 text-muted-foreground">{{ $nombre }}</span>
                                    <span class="h-2 flex-1 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full" style="width: {{ max($n ? 6 : 0, round($n / $max * 100)) }}%; background: {{ $color }}"></span></span>
                                    <b class="w-8 shrink-0 text-right">{{ number_format($n, 0, ',', '.') }}</b>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                            <div><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Cotizado</p><p class="font-semibold">{{ $dinero($cliente['montoCotizado']) }}</p></div>
                            <div><p class="text-[10px] uppercase tracking-wide text-muted-foreground">En órdenes</p><p class="font-semibold text-emerald-600">{{ $dinero($cliente['montoOrdenes']) }}</p></div>
                        </div>
                    </div>
                @endif
            </div>

            @if (! empty($detalle))
                {{-- Pestañas: datos del documento (sistema anterior) y seguimiento --}}
                <div data-tabs class="relative z-30 mb-5 flex flex-wrap items-center gap-1 border-b border-border">
                    <button type="button" data-tab="documento" class="act-tab"><i class="fa-solid fa-file-lines"></i> {{ $doc['moduloNombre'] }}</button>
                    <button type="button" data-tab="seguimiento" class="act-tab"><i class="fa-solid fa-pen-to-square"></i> Seguimiento <span class="rounded-full bg-muted px-1.5 text-[10px] font-medium text-muted-foreground">{{ count($historial) }}</span></button>
                    <span data-nav-slot class="ml-auto pb-1"></span>
                </div>
                <div data-tab-panel="documento" hidden>
                    <div class="space-y-4 text-sm">
                        <div class="grid grid-cols-2 gap-x-3 gap-y-3 sm:grid-cols-4">
                            @foreach ($detalle['campos'] as [$k, $v])
                                <div class="min-w-0"><p class="text-[11px] uppercase tracking-wide text-muted-foreground">{{ $k }}</p><p class="break-words font-medium">{{ $v ?: '—' }}</p></div>
                            @endforeach
                        </div>
                        @foreach ($detalle['textos'] as [$k, $v])
                            @if ($v !== '')
                                <div><p class="mb-1 text-[11px] uppercase tracking-wide text-muted-foreground">{{ $k }}</p>
                                    <p class="max-h-48 overflow-y-auto whitespace-pre-line rounded-lg bg-muted/40 p-3">{{ $v }}</p></div>
                            @endif
                        @endforeach
                        @if (count($detalle['tabla']['filas']))
                            <div><p class="mb-1 text-[11px] uppercase tracking-wide text-muted-foreground">{{ $detalle['tabla']['titulo'] }} ({{ count($detalle['tabla']['filas']) }})</p>
                                <div class="max-h-56 overflow-y-auto rounded-lg border border-border">
                                    <table class="w-full text-xs">
                                        <thead class="bg-muted/50 text-left text-muted-foreground"><tr>@foreach ($detalle['tabla']['cols'] as $i => $c)<th class="px-2 py-1.5 {{ in_array($i, $detalle['tabla']['der']) ? 'text-right' : '' }}">{{ $c }}</th>@endforeach</tr></thead>
                                        <tbody>@foreach ($detalle['tabla']['filas'] as $fila)
                                            <tr class="border-t border-border">@foreach ($fila as $i => $celda)<td class="px-2 py-1.5 {{ $i === 0 ? 'font-semibold' : '' }} {{ in_array($i, $detalle['tabla']['der']) ? 'text-right' : '' }}">{{ $celda }}</td>@endforeach</tr>
                                        @endforeach</tbody>
                                    </table>
                                </div></div>
                        @endif
                    </div>
                </div>
            @endif

            <div @if (! empty($detalle)) data-tab-panel="seguimiento" hidden @endif>

            <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold"><i class="fa-solid fa-pen-to-square text-primary"></i> Nuevo seguimiento</h3>
            <div>
                <label class="{{ $labelClass }}" for="act_observacion">Observación <span class="text-destructive">*</span></label>
                <textarea id="act_observacion" name="observacion" rows="4" maxlength="5000" required data-required
                          placeholder="¿Qué pasó o qué observaste? Queda en el historial del documento." class="{{ $inputClass }} h-auto py-2"></textarea>
                <p data-error-for="observacion" class="{{ $errorClass }}"></p>
            </div>

            <label class="mt-5 flex cursor-pointer items-center gap-3 rounded-xl border border-border bg-background px-4 py-3 text-sm transition has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                <input type="checkbox" name="programar" value="1" checked data-programar class="h-4 w-4 accent-[var(--primary)]">
                <span class="font-medium">Programar el próximo contacto (recordatorio)</span>
                <i class="fa-solid fa-bell ml-auto text-primary"></i>
            </label>

            <div data-recordatorio class="mt-4 space-y-4">
                <div class="flex flex-wrap gap-2" data-atajos>
                    @foreach ([['mañana', 1], ['en 3 días', 3], ['en 1 semana', 7], ['en 2 semanas', 14], ['en 1 mes', 30]] as [$texto, $dias])
                        <button type="button" data-dias="{{ $dias }}" class="act-pill">{{ ucfirst($texto) }}</button>
                    @endforeach
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="act_fecha">Fecha de próximo contacto <span class="text-destructive">*</span></label>
                        <input type="date" id="act_fecha" name="fecha" min="{{ now('America/Bogota')->toDateString() }}" class="{{ $inputClass }}" data-required-prog>
                        <p data-error-for="fecha" class="{{ $errorClass }}"></p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="act_hora">Hora <span class="text-destructive">*</span></label>
                        <input type="time" id="act_hora" name="hora" value="08:00" class="{{ $inputClass }}">
                        <p data-error-for="hora" class="{{ $errorClass }}"></p>
                    </div>
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="act_titulo">Título del recordatorio <span class="text-destructive">*</span></label>
                    <input type="text" id="act_titulo" name="titulo" maxlength="255" value="Dar seguimiento a {{ $doc['ref'] }}" class="{{ $inputClass }}" data-required-prog>
                    <p data-error-for="titulo" class="{{ $errorClass }}"></p>
                </div>
            </div>

            {{-- ---------- Historial ---------- --}}
            <h3 class="mt-8 mb-3 flex items-center gap-2 text-sm font-semibold"><i class="fa-solid fa-clock-rotate-left text-primary"></i> Historial del documento
                <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">{{ count($historial) }}</span></h3>
            @if (! count($historial))
                <p class="rounded-xl border border-dashed border-border p-5 text-center text-sm text-muted-foreground">Todavía no hay seguimientos en este documento.</p>
            @else
                <ol class="act-timeline">
                    @foreach ($historial as $i => $h)
                        <li style="--k: {{ min($i, 12) }}" class="{{ $h['tipo'] === 'recordatorio' ? ($h['realizado'] ? 'done' : 'pend') : 'obs' }}">
                            <span class="act-dot"><i class="fa-solid {{ $h['tipo'] === 'recordatorio' ? ($h['realizado'] ? 'fa-check' : 'fa-bell') : 'fa-comment' }}"></i></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                    <b class="text-foreground">{{ $h['autor'] ?: 'Sistema' }}</b>
                                    <span>{{ $fmt($h['fecha']) }}</span>
                                    @if ($h['tipo'] === 'recordatorio')
                                        <span class="rounded-full px-2 py-0.5 font-semibold {{ $h['realizado'] ? 'bg-emerald-500/15 text-emerald-600' : 'bg-amber-500/15 text-amber-600' }}">
                                            {{ $h['realizado'] ? 'Realizado' : 'Pendiente · '.$fmt($h['cuando']) }}
                                        </span>
                                    @endif
                                </div>
                                @if ($h['tipo'] === 'recordatorio')<p class="mt-1 text-sm font-semibold">{{ $h['titulo'] }}</p>@endif
                                @if ($h['texto'])<p class="mt-0.5 whitespace-pre-wrap break-words text-sm text-foreground/90">{{ $h['texto'] }}</p>@endif
                                @if ($h['tipo'] === 'recordatorio' && $h['realizado'] && $h['actividad'])
                                    <p class="mt-1.5 rounded-lg bg-emerald-500/10 px-3 py-2 text-sm"><span class="text-xs font-semibold text-emerald-600">Actividad realizada:</span> {{ $h['actividad'] }}</p>
                                @endif
                                @if ($h['tipo'] === 'recordatorio' && ! $h['realizado'])
                                    <div class="mt-2" data-hecho-caja>
                                        <button type="button" data-hecho-abrir class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border px-3 text-xs font-semibold transition hover:border-emerald-500 hover:text-emerald-600">
                                            <i class="fa-solid fa-check"></i> Marcar como realizado
                                        </button>
                                        <div data-hecho-form class="mt-2 hidden space-y-2">
                                            <textarea rows="2" maxlength="2000" placeholder="¿Qué actividad se realizó?" class="{{ $inputClass }} h-auto py-2" data-hecho-texto></textarea>
                                            <div class="flex gap-2">
                                                <button type="button" data-hecho="{{ $h['eventoId'] }}" class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white transition hover:opacity-90">Confirmar</button>
                                                <button type="button" data-hecho-cancelar class="inline-flex h-8 items-center rounded-lg px-3 text-xs font-medium text-muted-foreground hover:bg-muted">Cancelar</button>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif

            </div>

            <div class="h-8 flex-shrink-0"></div>
            <div class="sticky bottom-0 -mx-5 mt-auto -mb-5 flex items-center gap-3 border-t border-border bg-card/95 px-5 py-3 backdrop-blur sm:-mx-7 sm:-mb-7 sm:px-7">
                <span data-step-status class="mr-auto ml-1 text-xs text-muted-foreground">Ctrl+S para guardar</span>
                <button type="button" data-ficha-close class="inline-flex h-9 items-center rounded-lg px-3 text-sm font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground">Cerrar</button>
                <button type="submit" data-step-save data-ultimo="1" class="inline-flex h-9 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow-sm transition hover:opacity-90 disabled:cursor-wait disabled:opacity-60">
                    <span data-spinner class="hidden"><i class="fa-solid fa-circle-notch fa-spin"></i></span>
                    <span data-label>Guardar seguimiento</span>
                </button>
            </div>
        </form>
    @endif
    </div>
</div>
