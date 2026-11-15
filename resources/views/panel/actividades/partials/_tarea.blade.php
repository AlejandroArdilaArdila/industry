{{-- Detalle de una tarea (panel lateral): qué es, a qué está ligada, quién participa y el siguiente paso (completar o posponer). --}}
@php
    $c = $colores[$t['card']]['fondo'] ?? '#2563eb';
    $fmt = fn ($f, $formato = 'd/m/Y H:i') => \Illuminate\Support\Carbon::parse($f)->format($formato);
    $e = $t['elemento'];
    $manana = now('America/Bogota')->addDay()->toDateString();
@endphp
<div data-ficha data-puede-editar="1" data-tarea-detalle="{{ $t['id'] }}" class="flex h-full min-h-0 flex-col"
     data-estado-url="{{ route('panel.tareas.estado', ['tarea' => $t['id']]) }}" data-posponer-url="{{ route('panel.tareas.posponer', ['tarea' => $t['id']]) }}"
     data-rechazar-url="{{ route('panel.tareas.rechazar', ['tarea' => $t['id']]) }}" data-eliminar-url="{{ route('panel.tareas.destroy', ['tarea' => $t['id']]) }}" data-programada="{{ $t['programada'] }}">
    <script type="application/json" data-ficha-catalogos>{}</script>
    <header class="relative flex-shrink-0 overflow-hidden border-b border-border px-5 pt-5 pb-4 sm:px-7" style="--c: {{ $c }}; background-image: linear-gradient(135deg, color-mix(in oklab, {{ $c }} 16%, transparent), transparent 70%)">
        <button type="button" data-ficha-close title="Cerrar (Esc)" class="absolute top-4 right-4 flex h-9 w-9 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground"><i class="fa-solid fa-xmark text-lg"></i></button>
        <div class="flex items-center gap-4 pr-10">
            <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl text-xl text-white shadow-md" style="background: {{ $c }}"><i class="fa-solid {{ $t['icono'] }}"></i></div>
            <div class="min-w-0">
                <h2 data-ficha-titulo class="text-lg leading-snug font-semibold text-foreground sm:text-xl">{{ $t['titulo'] }}</h2>
                <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-full px-2 py-0.5 font-semibold {{ $t['cerrada'] ? 'bg-emerald-500/15 text-emerald-600' : 'bg-amber-500/15 text-amber-600' }}">{{ $t['cerrada'] ? 'Completada' : 'Pendiente' }}</span>
                    @if ($t['prioridad'])<span class="rounded-full px-2 py-0.5 font-semibold" style="background: {{ $t['prioridad']['color'] }}; color: {{ ($t['prioridad']['label'] ?? '') === 'Medio' ? '#2e2a2a' : '#fff' }}"><i class="fa-solid fa-flag"></i> {{ $t['prioridad']['label'] }}</span>@endif
                    @if ($t['recurrencia'])<span class="rounded-full bg-muted px-2 py-0.5 font-medium"><i class="fa-solid fa-repeat"></i> {{ $t['recurrencia'] }}</span>@endif
                </div>
            </div>
        </div>
    </header>

    <div data-ficha-scroll class="min-h-0 flex-1 overflow-y-auto">
        <div class="flex min-h-full flex-col gap-5 p-5 sm:p-7">
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-xl border border-border p-3"><p class="text-[11px] tracking-wide text-muted-foreground uppercase">{{ $t['cerrada'] ? 'Completada' : 'Le toca' }}</p>
                    <p class="font-semibold">{{ $t['cerrada'] && $t['cerradaEn'] ? $fmt($t['cerradaEn']) : $fmt($t['cuando']) }}</p></div>
                <div class="rounded-xl border border-border p-3"><p class="text-[11px] tracking-wide text-muted-foreground uppercase">Creada por</p><p class="truncate font-semibold">{{ $t['creador'] ?: '—' }}</p></div>
            </div>

            @if ($e)
                <div class="act-doc" style="--c: {{ $c }}; cursor: {{ $e['modulo'] ? 'pointer' : 'default' }}" @if ($e['modulo']) data-abrir-doc="{{ $e['modulo'] }}:{{ $e['id'] }}" @endif>
                    <span class="act-doc-ico"><i class="fa-solid {{ $e['icono'] }}"></i></span>
                    <span class="min-w-0 flex-1 text-left"><b class="block truncate text-sm">{{ $e['ref'] }}</b><span class="block truncate text-xs text-muted-foreground">{{ $e['tipoNombre'] }}{{ $e['empresa'] && $e['empresa'] !== $e['ref'] ? ' · '.$e['empresa'] : '' }}</span></span>
                    @if ($e['modulo'])<span class="text-xs font-semibold text-primary">Abrir <i class="fa-solid fa-arrow-right"></i></span>@endif
                </div>
            @endif

            @if ($t['descripcion'])
                <div><p class="mb-1.5 text-xs font-medium text-muted-foreground">Descripción</p><p class="text-sm leading-relaxed whitespace-pre-line">{{ $t['descripcion'] }}</p></div>
            @endif

            <div>
                <p class="mb-1.5 text-xs font-medium text-muted-foreground">Integrantes ({{ count($t['integrantesLista']) }})</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($t['integrantesLista'] as $i)
                        <span class="inline-flex items-center gap-2 rounded-full bg-muted py-1 pr-3 pl-1 text-xs font-medium"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-primary-foreground">{{ mb_strtoupper(mb_substr($i['nombre'], 0, 1)) }}</span>{{ $i['nombre'] }}</span>
                    @endforeach
                </div>
            </div>

            @if ($t['teams'] || $t['enOutlook'])
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    @if ($t['enOutlook'])<span class="inline-flex items-center gap-1.5 rounded-full bg-[#0078d4]/10 px-2.5 py-1 font-semibold text-[#0078d4]"><i class="fa-brands fa-microsoft"></i> En tu Outlook</span>@endif
                    @if ($t['teams'])<a href="{{ $t['teams'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-full bg-[#5b5fc7]/10 px-2.5 py-1 font-semibold text-[#5b5fc7] hover:bg-[#5b5fc7]/20"><i class="fa-solid fa-video"></i> Unirse a Teams</a>@endif
                </div>
            @endif

            <div class="h-4 flex-shrink-0"></div>
            <div class="sticky bottom-0 -mx-5 mt-auto -mb-5 border-t border-border bg-card/95 px-5 py-3 backdrop-blur sm:-mx-7 sm:-mb-7 sm:px-7">
                @if ($t['puedeCambiar'])
                    <div class="flex flex-wrap items-center gap-2">
                        @if (! $t['cerrada'])
                            <button type="button" data-tarea-hecho class="inline-flex h-9 items-center gap-2 rounded-lg bg-emerald-600 px-4 text-sm font-medium text-white shadow-sm transition hover:opacity-90"><i class="fa-solid fa-check"></i> Completar</button>
                            <span class="text-xs text-muted-foreground">Posponer:</span>
                            @foreach ([['Mañana', 1], ['3 días', 3], ['1 semana', 7]] as [$tx, $d])
                                <button type="button" data-posponer="{{ $d }}" class="act-pill">{{ $tx }}</button>
                            @endforeach
                            <input type="date" data-posponer-fecha min="{{ now('America/Bogota')->toDateString() }}" class="h-8 rounded-lg border border-input bg-background px-2 text-xs" title="Elegir otra fecha">
                        @else
                            <button type="button" data-tarea-reabrir class="inline-flex h-9 items-center gap-2 rounded-lg border border-border px-4 text-sm font-medium transition hover:bg-muted"><i class="fa-solid fa-rotate-left"></i> Reabrir</button>
                        @endif
                        @if ($t['puedeSalir'])<button type="button" data-tarea-rechazar class="ml-auto inline-flex h-9 items-center gap-2 rounded-lg px-3 text-sm font-medium text-[#9333ea] transition hover:bg-[#a855f7]/10" title="Dejar de ser integrante"><i class="fa-solid fa-ban"></i> Rechazar</button>@endif
                        @if ($t['puedeEliminar'])<button type="button" data-tarea-eliminar class="ml-auto inline-flex h-9 items-center gap-2 rounded-lg px-3 text-sm font-medium text-destructive transition hover:bg-destructive/10" title="Eliminar la tarea"><i class="fa-solid fa-trash-can"></i> Eliminar</button>@endif
                        <button type="button" data-ficha-close class="{{ $t['puedeEliminar'] || $t['puedeSalir'] ? '' : 'ml-auto ' }}inline-flex h-9 items-center rounded-lg px-3 text-sm font-medium text-muted-foreground transition hover:bg-muted">Cerrar</button>
                    </div>
                @else
                    <div class="flex items-center gap-3 text-xs text-muted-foreground"><i class="fa-solid fa-lock"></i> Solo {{ $t['creador'] ?: 'quien la creó' }} puede cambiar el estado de esta tarea.
                        @if ($t['puedeSalir'])<button type="button" data-tarea-rechazar class="ml-auto inline-flex h-9 items-center gap-2 rounded-lg px-3 text-sm font-medium text-[#9333ea] transition hover:bg-[#a855f7]/10"><i class="fa-solid fa-ban"></i> Rechazar</button>@endif
                        <button type="button" data-ficha-close class="ml-auto inline-flex h-9 items-center rounded-lg px-3 text-sm font-medium transition hover:bg-muted">Cerrar</button></div>
                @endif
            </div>
        </div>
    </div>
</div>
