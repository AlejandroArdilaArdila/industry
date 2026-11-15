{{--
    Nueva tarea del programador (panel lateral). Sigue el contrato de FichaModal: [data-ficha] + form[data-step] + [data-ficha-scroll].
    Se puede crear libre o relacionada con cualquier elemento del sistema, dictando por voz (la IA llena el formulario) o escribiendo.
--}}
@php
    $labelClass = 'mb-1.5 block text-xs font-medium text-muted-foreground';
    $inputClass = 'w-full h-10 rounded-lg border border-input bg-background px-3 text-sm text-foreground shadow-xs transition placeholder:text-muted-foreground/60 focus:border-primary focus:outline-none focus:ring-3 focus:ring-primary/15 disabled:opacity-50';
    $errorClass = 'mt-1 hidden text-xs text-destructive';
    $mediano = collect($cat['prioridades'])->first(fn ($p) => mb_strtolower($p['label']) === 'medio') ?? ($cat['prioridades'][0] ?? null);
    $catalogosJson = json_encode(['usuarios' => $cat['usuarios'], 'previo' => $previo], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
    $manana = \Illuminate\Support\Carbon::parse($hoy)->addDay()->toDateString();
@endphp

<div data-ficha data-puede-editar="1" data-tarea-form class="flex h-full min-h-0 flex-col"
     data-store-url="{{ route('panel.tareas.store') }}" data-elementos-url="{{ route('panel.tareas.elementos') }}" data-interpretar-url="{{ route('panel.tareas.interpretar') }}"
     data-hoy="{{ $hoy }}" data-yo="{{ $yo }}">
    <script type="application/json" data-ficha-catalogos>{!! $catalogosJson !!}</script>

    <header class="relative flex-shrink-0 overflow-hidden border-b border-border px-5 pt-5 pb-4 sm:px-7" style="background-image: linear-gradient(135deg, color-mix(in oklab, var(--primary) 14%, transparent), transparent 70%)">
        <button type="button" data-ficha-close title="Cerrar (Esc)" class="absolute top-4 right-4 flex h-9 w-9 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground"><i class="fa-solid fa-xmark text-lg"></i></button>
        <div class="flex items-center gap-4 pr-10">
            <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl bg-primary text-xl text-primary-foreground shadow-md"><i class="fa-solid fa-list-check"></i></div>
            <div class="min-w-0">
                <h2 data-ficha-titulo class="truncate text-lg font-semibold text-foreground sm:text-xl">Nueva tarea</h2>
                <p class="mt-1 text-sm text-muted-foreground">Dicta lo que necesitas o complétalo tú. Puede ser libre o ligada a cualquier elemento.</p>
            </div>
        </div>
    </header>

    <div data-ficha-scroll class="min-h-0 flex-1 overflow-y-auto">
        <form data-step="tarea" class="flex min-h-full flex-col gap-6 p-5 sm:p-7" novalidate>

            {{-- 1. Dictado + IA --}}
            <section class="tf-ia">
                <div class="flex items-center gap-3">
                    <button type="button" data-ia-mic class="tf-mic" title="Dictar la tarea"><i class="fa-solid fa-microphone"></i></button>
                    <div class="min-w-0 flex-1">
                        <b class="block text-sm">Dicta tu tarea</b>
                        <span class="block truncate text-xs text-muted-foreground" data-ia-ayuda>«Llamar a Imporinco mañana a las 9 por la cotización C2610-38378, es urgente»</span>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <input type="text" data-ia-texto maxlength="2000" autocomplete="off" placeholder="…o escríbelo aquí y la IA llena el formulario" class="{{ $inputClass }}">
                    <button type="button" data-ia-enviar class="inline-flex h-10 flex-shrink-0 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground transition hover:opacity-90 disabled:opacity-60"><i class="fa-solid fa-wand-magic-sparkles"></i> <span>Llenar</span></button>
                </div>
                <p data-ia-resultado class="mt-2 hidden text-xs"></p>
            </section>

            {{-- 2. Asunto --}}
            <section>
                <label class="{{ $labelClass }}" for="tf_titulo">Asunto de la tarea <span class="text-destructive">*</span></label>
                <div class="flex gap-2">
                    <input type="text" id="tf_titulo" name="titulo" maxlength="250" data-required autocomplete="off" placeholder="¿Qué hay que hacer?" class="{{ $inputClass }}">
                    <button type="button" data-dictar="#tf_titulo" class="tf-mini" title="Dictar"><i class="fa-solid fa-microphone"></i></button>
                </div>
                <p data-error-for="titulo" class="{{ $errorClass }}"></p>
            </section>

            {{-- 3. Relacionar con --}}
            <section>
                <span class="{{ $labelClass }}">Relacionar con <span class="font-normal">(opcional: puede ser una tarea libre)</span></span>
                <div class="flex flex-wrap gap-2" data-tipos>
                    <button type="button" data-tipo="" class="act-pill on"><i class="fa-solid fa-feather-pointed"></i> Libre</button>
                    @foreach ($tipos as $k => $t)
                        <button type="button" data-tipo="{{ $k }}" class="act-pill"><i class="fa-solid {{ $t['icono'] }}"></i> {{ $t['nombre'] }}</button>
                    @endforeach
                </div>
                <input type="hidden" name="tipo" value=""><input type="hidden" name="ref" value="">
                <div data-rel-buscar class="mt-3 hidden">
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"></i>
                        <input type="search" data-rel-q autocomplete="off" placeholder="Busca por referencia o por cliente…" class="{{ $inputClass }} pl-9">
                    </div>
                    <div data-rel-lista class="mt-2 grid grid-cols-1 gap-2"></div>
                </div>
                <div data-rel-elegido class="mt-3 hidden"></div>
            </section>

            {{-- 4. Cuándo --}}
            <section>
                <span class="{{ $labelClass }}">Cuándo</span>
                <div class="flex flex-wrap gap-2" data-atajos>
                    @foreach ([['Hoy', 0], ['Mañana', 1], ['En 3 días', 3], ['1 semana', 7], ['2 semanas', 14], ['1 mes', 30]] as [$t, $d])
                        <button type="button" data-dias="{{ $d }}" class="act-pill">{{ $t }}</button>
                    @endforeach
                </div>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $labelClass }}" for="tf_fecha">Fecha de atención</label>
                        <input type="date" id="tf_fecha" name="fecha" min="{{ $hoy }}" value="{{ $fechaInicial }}" class="{{ $inputClass }}">
                        <p data-error-for="fecha" class="{{ $errorClass }}"></p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="tf_hora">Hora de atención</label>
                        <input type="time" id="tf_hora" name="hora" value="{{ $horaInicial }}" class="{{ $inputClass }}">
                        <p data-error-for="hora" class="{{ $errorClass }}"></p>
                    </div>
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5" data-horas>
                    @foreach (['08:00', '10:00', '12:00', '14:00', '16:00'] as $h)<button type="button" data-hora="{{ $h }}" class="act-pill !px-2.5 !py-0.5 !text-[11px]">{{ $h }}</button>@endforeach
                </div>
            </section>

            {{-- 5. Prioridad y recurrencia --}}
            <section class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <span class="{{ $labelClass }}">Prioridad</span>
                    <div class="flex flex-wrap gap-2" data-prioridades>
                        @foreach ($cat['prioridades'] as $p)
                            <button type="button" data-prio="{{ $p['id'] }}" class="act-pill {{ $mediano && $p['id'] === $mediano['id'] ? 'on' : '' }}" style="--pc: {{ $p['color'] }}; --pf: {{ $p['fontColor'] }}"><i class="fa-solid fa-flag"></i> {{ $p['label'] }}</button>
                        @endforeach
                    </div>
                    <input type="hidden" name="prioridad" value="{{ $mediano['id'] ?? '' }}">
                </div>
                <div>
                    <span class="{{ $labelClass }}">Recurrencia</span>
                    <div class="flex flex-wrap gap-2" data-recurrencias>
                        @foreach ($cat['recurrencias'] as $k => $t)
                            <button type="button" data-rec="{{ $k }}" class="act-pill {{ $k === 1 ? 'on' : '' }}">{{ $t }}</button>
                        @endforeach
                    </div>
                    <input type="hidden" name="recurrencia" value="1">
                </div>
            </section>

            {{-- 6. Descripción --}}
            <section>
                <label class="{{ $labelClass }}" for="tf_desc">Descripción</label>
                <div class="relative">
                    <textarea id="tf_desc" name="descripcion" rows="4" maxlength="5000" placeholder="Detalles, contexto, qué hay que decir… (puedes dictarlo)" class="{{ $inputClass }} h-auto py-2 pr-11"></textarea>
                    <button type="button" data-dictar="#tf_desc" class="tf-mini absolute top-2 right-2" title="Dictar"><i class="fa-solid fa-microphone"></i></button>
                </div>
            </section>

            {{-- 7. Integrantes --}}
            <section>
                <span class="{{ $labelClass }}">Integrantes <span class="font-normal">(tú siempre participas)</span></span>
                <div class="flex flex-wrap gap-2" data-int-chips></div>
                <div class="relative mt-2">
                    <i class="fa-solid fa-user-plus pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"></i>
                    <input type="search" data-int-q autocomplete="off" placeholder="Agregar un compañero…" class="{{ $inputClass }} pl-9">
                    <div data-int-lista class="absolute right-0 left-0 z-20 mt-1 hidden max-h-56 overflow-y-auto rounded-xl border border-border bg-card p-1 shadow-xl"></div>
                </div>
            </section>

            {{-- 8. Opciones --}}
            <section class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                @if (count($cat['categorias']))
                    <div>
                        <label class="{{ $labelClass }}" for="tf_cat">Categoría</label>
                        <select id="tf_cat" name="categoria" class="{{ $inputClass }}"><option value=""></option>@foreach ($cat['categorias'] as $c)<option value="{{ $c['id'] }}">{{ $c['label'] }}</option>@endforeach</select>
                    </div>
                @endif
                <div>
                    <span class="{{ $labelClass }}">Pueden cambiar de estado</span>
                    <div class="flex flex-wrap gap-2" data-estado-cambio>
                        <button type="button" data-sc="0" class="act-pill on"><i class="fa-solid fa-user-shield"></i> Solo yo</button>
                        <button type="button" data-sc="1" class="act-pill"><i class="fa-solid fa-users"></i> Todos los integrantes</button>
                    </div>
                    <input type="hidden" name="state_change" value="0">
                </div>
            </section>

            {{-- 9. Office 365 --}}
            @if (($ms['conectado'] ?? false) && ($ms['calendario'] ?? false))
                <section class="rounded-xl border border-border bg-background p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold"><i class="fa-brands fa-microsoft text-[#0078d4]"></i> Office 365 <span class="text-xs font-normal text-muted-foreground">· se agenda en tu Outlook{{ ($ms['tareas'] ?? false) ? ' y en To Do' : '' }}</span></div>
                    <input type="hidden" name="teams" value="{{ ($ms['teams'] ?? false) ? 1 : 0 }}">
                    <label class="mt-3 flex cursor-pointer items-center gap-3 rounded-lg border border-border px-3 py-2.5 text-sm transition has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                        <input type="checkbox" data-teams {{ ($ms['teams'] ?? false) ? 'checked' : '' }} class="h-4 w-4 accent-[var(--primary)]">
                        <span class="font-medium">Crear reunión de Microsoft Teams</span><i class="fa-solid fa-video ml-auto text-[#5b5fc7]"></i>
                    </label>
                </section>
            @endif

            <div class="h-4 flex-shrink-0"></div>
            <div class="sticky bottom-0 -mx-5 mt-auto -mb-5 flex items-center gap-3 border-t border-border bg-card/95 px-5 py-3 backdrop-blur sm:-mx-7 sm:-mb-7 sm:px-7">
                <span data-step-status class="mr-auto ml-1 text-xs text-muted-foreground">Ctrl+S para guardar</span>
                <button type="button" data-ficha-close class="inline-flex h-9 items-center rounded-lg px-3 text-sm font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground">Cancelar</button>
                <button type="submit" data-step-save class="inline-flex h-9 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow-sm transition hover:opacity-90 disabled:cursor-wait disabled:opacity-60">
                    <span data-spinner class="hidden"><i class="fa-solid fa-circle-notch fa-spin"></i></span>
                    <span data-label>Crear tarea</span>
                </button>
            </div>
        </form>
    </div>
</div>
