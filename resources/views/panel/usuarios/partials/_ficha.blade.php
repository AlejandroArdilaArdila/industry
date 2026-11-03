{{--
    Ficha del usuario en modal (solo admin), como asistente por pasos. Cada
    paso es un <form data-step> que se guarda solo (ver usuario-ficha.js);
    contraseña, grupos y permisos guardan en su propia ruta (data-url).
--}}
@php
    $labelClass = 'mb-1.5 block text-xs font-medium text-muted-foreground';
    $inputClass = 'w-full h-10 rounded-lg border border-input bg-background px-3 text-sm text-foreground shadow-xs transition placeholder:text-muted-foreground/60 focus:border-primary focus:outline-none focus:ring-3 focus:ring-primary/15 disabled:cursor-not-allowed disabled:opacity-60';
    $errorClass = 'mt-1 hidden text-xs text-destructive';
    $switchClass = 'relative inline-flex h-5 w-9 flex-shrink-0 rounded-full bg-muted-foreground/30 transition peer-checked:bg-primary after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-4';

    $nombre = $usuario->full_name ?: $usuario->login;
    $iniciales = collect(preg_split('/\s+/', trim($nombre)))
        ->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');

    $pasos = array_filter([
        'datos' => ['Datos personales', 'Nombre y contacto', 'fa-id-badge'],
        'ubicacion' => ['Ubicación', 'Dirección y ciudad', 'fa-location-dot'],
        'acceso' => ['Acceso', 'Estado y rol', 'fa-user-shield'],
        'adicionales' => $extrafields->isNotEmpty() ? ['Campos adicionales', 'Extrafields de Dolibarr', 'fa-puzzle-piece'] : null,
        'password' => ['Contraseña', 'Restablecer acceso', 'fa-key'],
        'grupos' => ['Grupos', count($gruposDe).' asignado'.(count($gruposDe) === 1 ? '' : 's'), 'fa-users'],
        'permisos' => ['Permisos', count($permisosDe).' directos', 'fa-lock-open'],
    ]);
    $clavesPasos = array_keys($pasos);
    $catalogos = ['fk_country' => $paises, 'fk_state' => $departamentos];
@endphp

<div data-ficha data-puede-editar="1" data-update-url="{{ route('panel.usuarios.update', $usuario->rowid) }}" class="flex h-full flex-col">

    {{-- ============ Cabecera ============ --}}
    <header class="relative flex-shrink-0 overflow-hidden border-b border-border bg-gradient-to-br from-primary/10 via-card to-card px-5 pt-5 pb-4 sm:px-7">
        <button type="button" data-ficha-close title="Cerrar (Esc)"
                class="absolute top-4 right-4 flex h-9 w-9 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>

        <div class="flex flex-col gap-4 pr-10 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-full bg-primary text-lg font-semibold text-primary-foreground shadow-md">
                    {{ $iniciales ?: '?' }}
                </div>
                <div class="min-w-0">
                    <h2 data-ficha-titulo class="truncate text-lg font-semibold text-foreground sm:text-xl">{{ $nombre }}</h2>
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm text-muted-foreground">
                        <button type="button" data-copy="{{ $usuario->login }}" title="Copiar usuario"
                                class="inline-flex items-center gap-1.5 rounded-md font-mono text-foreground/80 transition hover:text-primary">
                            <i class="fa-regular fa-user text-xs"></i> {{ $usuario->login }}
                            <i class="fa-regular fa-copy text-xs"></i>
                        </button>
                        @if ($usuario->job)
                            <span class="truncate">· {{ $usuario->job }}</span>
                        @endif
                        <span class="flex items-center gap-1.5">
                            <span data-ficha-admin class="{{ (int) $usuario->admin === 1 ? '' : 'hidden' }} rounded-full bg-violet-500/15 px-2 py-0.5 text-xs font-medium text-violet-700 dark:text-violet-300">Administrador</span>
                            <span data-ficha-estado class="rounded-full px-2 py-0.5 text-xs font-medium {{ (int) $usuario->statut === 1 ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/15 text-amber-700 dark:text-amber-300' }}">
                                {{ (int) $usuario->statut === 1 ? 'Activo' : 'Inactivo' }}
                            </span>
                            @if ($esUnoMismo)
                                <span class="rounded-full bg-sky-500/15 px-2 py-0.5 text-xs font-medium text-sky-700 dark:text-sky-300">Tú</span>
                            @endif
                        </span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @if ($usuario->email)
                            <a href="mailto:{{ $usuario->email }}" class="inline-flex items-center gap-1.5 rounded-full border border-border bg-background/70 px-2.5 py-1 text-xs text-foreground/80 transition hover:border-primary/40 hover:text-primary">
                                <i class="fa-regular fa-envelope"></i> {{ $usuario->email }}
                            </a>
                        @endif
                        @if ($usuario->user_mobile)
                            <a href="https://wa.me/{{ preg_replace('/\D/', '', str_starts_with($usuario->user_mobile, '+') ? $usuario->user_mobile : '57'.$usuario->user_mobile) }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 rounded-full border border-border bg-background/70 px-2.5 py-1 text-xs text-foreground/80 transition hover:border-emerald-500/50 hover:text-emerald-600">
                                <i class="fa-brands fa-whatsapp"></i> {{ $usuario->user_mobile }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>

            <dl class="grid flex-shrink-0 grid-cols-3 gap-2 text-center sm:gap-3">
                <div class="rounded-xl border border-border bg-background/70 px-3 py-2">
                    <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Grupos</dt>
                    <dd data-kpi-grupos class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ count($gruposDe) }}</dd>
                </div>
                <div class="rounded-xl border border-border bg-background/70 px-3 py-2">
                    <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Permisos</dt>
                    <dd data-kpi-permisos class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ count(array_unique(array_merge($permisosDe, $permisosPorGrupo))) }}</dd>
                </div>
                <div class="rounded-xl border border-border bg-background/70 px-3 py-2">
                    <dt class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Último acceso</dt>
                    <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ $usuario->datelastlogin ? \Illuminate\Support\Carbon::parse($usuario->datelastlogin)->format('d/m/Y') : '—' }}</dd>
                </div>
            </dl>
        </div>
    </header>

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

            <div class="space-y-1 px-5 pt-3 pb-5 text-xs text-muted-foreground max-md:hidden">
                <p><i class="fa-regular fa-calendar-plus mr-1.5 w-3"></i>Creado {{ $usuario->datec ? \Illuminate\Support\Carbon::parse($usuario->datec)->format('d/m/Y') : '—' }}</p>
                <p><i class="fa-regular fa-pen-to-square mr-1.5 w-3"></i>Modificado {{ $usuario->tms ? \Illuminate\Support\Carbon::parse($usuario->tms)->format('d/m/Y H:i') : '—' }}</p>
                <p><i class="fa-solid fa-hashtag mr-1.5 w-3"></i>ID {{ $usuario->rowid }}</p>
            </div>
        </nav>

        <div class="relative min-h-0 flex-1 overflow-y-auto" data-ficha-scroll>

            {{-- ---------- 1. Datos personales ---------- --}}
            <form data-step="datos" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Datos personales', 'descripcion' => 'Cómo se identifica el usuario y cómo contactarlo.'])

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="u_civility">Tratamiento</label>
                        <select id="u_civility" name="civility" class="{{ $inputClass }}">
                            <option value="">—</option>
                            @foreach ($civilidades as $civ)
                                <option value="{{ $civ->code }}" @selected($usuario->civility === $civ->code)>{{ $civ->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="u_gender">Género</label>
                        <select id="u_gender" name="gender" class="{{ $inputClass }}">
                            <option value="">—</option>
                            <option value="man" @selected($usuario->gender === 'man')>Hombre</option>
                            <option value="woman" @selected($usuario->gender === 'woman')>Mujer</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="u_birth">Fecha de nacimiento</label>
                        <input id="u_birth" type="date" name="birth" value="{{ $usuario->birth ? \Illuminate\Support\Carbon::parse($usuario->birth)->format('Y-m-d') : '' }}" class="{{ $inputClass }}">
                        <p data-error-for="birth" class="{{ $errorClass }}"></p>
                    </div>

                    <div class="sm:col-span-3">
                        <label class="{{ $labelClass }}" for="u_firstname">Nombres <span class="text-destructive">*</span></label>
                        <input id="u_firstname" name="firstname" value="{{ $usuario->firstname }}" maxlength="50" data-required class="{{ $inputClass }}">
                        <p data-error-for="firstname" class="{{ $errorClass }}"></p>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="{{ $labelClass }}" for="u_lastname">Apellidos <span class="text-destructive">*</span></label>
                        <input id="u_lastname" name="lastname" value="{{ $usuario->lastname }}" maxlength="50" data-required class="{{ $inputClass }}">
                        <p data-error-for="lastname" class="{{ $errorClass }}"></p>
                    </div>
                    <div class="sm:col-span-6">
                        <label class="{{ $labelClass }}" for="u_job">Cargo</label>
                        <input id="u_job" name="job" value="{{ $usuario->job }}" maxlength="128" class="{{ $inputClass }}" placeholder="Cajero, vendedor, administrador…">
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-4 border-t border-border pt-6 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="u_email">Correo electrónico</label>
                        <div class="relative">
                            <i class="fa-regular fa-envelope pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"></i>
                            <input id="u_email" type="email" name="email" value="{{ $usuario->email }}" maxlength="255" data-recommended class="{{ $inputClass }} pl-9">
                        </div>
                        <p data-error-for="email" class="{{ $errorClass }}"></p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="u_office_phone">Teléfono oficina</label>
                        <div class="relative">
                            <i class="fa-solid fa-phone pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"></i>
                            <input id="u_office_phone" type="tel" name="office_phone" value="{{ $usuario->office_phone }}" maxlength="30" class="{{ $inputClass }} pl-9">
                        </div>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="u_mobile">Celular / WhatsApp</label>
                        <div class="relative">
                            <i class="fa-brands fa-whatsapp pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"></i>
                            <input id="u_mobile" type="tel" name="user_mobile" value="{{ $usuario->user_mobile }}" maxlength="30" class="{{ $inputClass }} pl-9">
                        </div>
                    </div>
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'datos'])
            </form>

            {{-- ---------- 2. Ubicación ---------- --}}
            <form data-step="ubicacion" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Ubicación', 'descripcion' => 'Dirección de residencia o de la sede donde trabaja.'])

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="u_address">Dirección</label>
                        <input id="u_address" name="address" value="{{ $usuario->address }}" maxlength="255" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="u_town">Ciudad</label>
                        <input id="u_town" name="town" value="{{ $usuario->town }}" maxlength="50" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="u_zip">Código postal</label>
                        <input id="u_zip" name="zip" value="{{ $usuario->zip }}" maxlength="25" class="{{ $inputClass }} font-mono">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Departamento</label>
                        <input type="hidden" name="fk_state" value="{{ $usuario->fk_state ?: '' }}">
                        <div data-combo="fk_state" data-placeholder="Departamento"></div>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">País</label>
                        <input type="hidden" name="fk_country" value="{{ $usuario->fk_country ?: '' }}">
                        <div data-combo="fk_country" data-placeholder="País"></div>
                    </div>
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'ubicacion'])
            </form>

            {{-- ---------- 3. Acceso ---------- --}}
            <form data-step="acceso" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Acceso', 'descripcion' => 'Si puede entrar al sistema y con qué rol.'])

                <div class="mb-5">
                    <label class="{{ $labelClass }}">Usuario (login)</label>
                    <input value="{{ $usuario->login }}" disabled class="{{ $inputClass }} font-mono">
                    <p class="mt-1 text-xs text-muted-foreground">El login no se puede cambiar desde el panel.</p>
                </div>

                <div class="grid grid-cols-1 gap-3">
                    @foreach ([
                        ['statut', 'Activo', 'Un usuario inactivo no puede iniciar sesión en el panel, Dolibarr ni el POS.', 'peer-checked:bg-emerald-500'],
                        ['admin', 'Administrador', 'Tiene todos los permisos y puede gestionar otros usuarios.', 'peer-checked:bg-violet-500'],
                        ['employee', 'Empleado', 'Aparece como empleado de la empresa en Dolibarr (RR. HH., agenda…).', 'peer-checked:bg-primary'],
                    ] as [$campo, $titulo, $ayuda, $color])
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-background p-3.5 transition hover:border-primary/40 has-[:checked]:border-primary/50 has-[:checked]:bg-primary/5">
                            <input type="hidden" name="{{ $campo }}" value="0">
                            <input type="checkbox" name="{{ $campo }}" value="1" class="peer sr-only" @checked((int) $usuario->{$campo} === 1)>
                            <span class="{{ str_replace('peer-checked:bg-primary', $color, $switchClass) }} mt-0.5"></span>
                            <span>
                                <span class="block text-sm font-medium text-foreground">{{ $titulo }}</span>
                                <span class="block text-xs text-muted-foreground">{{ $ayuda }}</span>
                            </span>
                        </label>
                    @endforeach
                    <p data-error-for="admin" class="{{ $errorClass }}"></p>
                </div>

                @if ($esUnoMismo)
                    <p class="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
                        <i class="fa-solid fa-circle-info"></i> Es tu propio usuario: no puedes desactivarte ni quitarte el rol de administrador.
                    </p>
                @endif

                @include('panel.partials.ficha._paso-footer', ['clave' => 'acceso'])
            </form>

            {{-- ---------- 4. Campos adicionales ---------- --}}
            @if ($extrafields->isNotEmpty())
                <form data-step="adicionales" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                    @include('panel.partials.ficha._paso-header', ['titulo' => 'Campos adicionales', 'descripcion' => 'Campos extra configurados en Dolibarr para los usuarios.'])
                    @include('panel.partials.ficha._extrafields', ['defs' => $extrafields])
                    @include('panel.partials.ficha._paso-footer', ['clave' => 'adicionales'])
                </form>
            @endif

            {{-- ---------- 5. Contraseña ---------- --}}
            <form data-step="password" data-url="{{ route('panel.usuarios.password', $usuario->rowid) }}" data-limpiar class="hidden min-h-full flex-col p-5 sm:p-7" novalidate autocomplete="off">
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Contraseña', 'descripcion' => 'Asigna una contraseña nueva. Déjalo vacío y continúa si no quieres cambiarla.'])

                <div class="grid max-w-md grid-cols-1 gap-4">
                    <div>
                        <label class="{{ $labelClass }}" for="u_password">Contraseña nueva</label>
                        <div class="relative">
                            <input id="u_password" type="password" name="password" minlength="8" autocomplete="new-password" class="{{ $inputClass }} pr-10">
                            <button type="button" data-ver-password class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-muted-foreground hover:text-foreground" title="Mostrar">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"><div data-fuerza class="h-full w-0 rounded-full transition-all"></div></div>
                        <p data-fuerza-texto class="mt-1 text-xs text-muted-foreground">Mínimo 8 caracteres.</p>
                        <p data-error-for="password" class="{{ $errorClass }}"></p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="u_password2">Confirmar contraseña</label>
                        <input id="u_password2" type="password" name="password_confirmation" minlength="8" autocomplete="new-password" class="{{ $inputClass }}">
                    </div>
                    <button type="button" data-generar-password class="inline-flex h-9 w-fit items-center gap-2 rounded-lg border border-border px-3 text-sm font-medium text-foreground transition hover:bg-muted">
                        <i class="fa-solid fa-wand-magic-sparkles text-xs"></i> Generar contraseña segura
                    </button>
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'password'])
            </form>

            {{-- ---------- 6. Grupos ---------- --}}
            <form data-step="grupos" data-url="{{ route('panel.usuarios.grupos', $usuario->rowid) }}" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Grupos', 'descripcion' => 'El usuario hereda todos los permisos de los grupos a los que pertenece.'])

                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @forelse ($grupos as $grupo)
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-border bg-background px-3.5 py-3 text-sm transition hover:border-primary/40 has-[:checked]:border-primary/50 has-[:checked]:bg-primary/5">
                            <input type="checkbox" name="grupos[]" value="{{ $grupo->rowid }}" @checked(in_array((int) $grupo->rowid, $gruposDe, true))
                                   class="h-4 w-4 rounded border-border accent-[var(--primary)]">
                            <i class="fa-solid fa-users text-muted-foreground"></i>
                            <span class="font-medium text-foreground">{{ $grupo->nom }}</span>
                        </label>
                    @empty
                        <p class="text-sm text-muted-foreground">No hay grupos de usuario creados en Dolibarr.</p>
                    @endforelse
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'grupos'])
            </form>

            {{-- ---------- 7. Permisos ---------- --}}
            <form data-step="permisos" data-url="{{ route('panel.usuarios.permisos', $usuario->rowid) }}" class="hidden min-h-full flex-col p-5 sm:p-7" novalidate>
                @include('panel.partials.ficha._paso-header', ['titulo' => 'Permisos', 'descripcion' => 'Permisos directos del usuario. Los marcados "por grupo" ya los tiene por un grupo aunque aquí estén apagados.'])

                @if ((int) $usuario->admin === 1)
                    <div class="mb-4 flex items-center gap-2 rounded-xl border border-violet-500/30 bg-violet-500/10 px-4 py-3 text-sm text-violet-800 dark:text-violet-200">
                        <i class="fa-solid fa-crown"></i> Es administrador: tiene todos los permisos sin importar lo que se marque aquí.
                    </div>
                @endif

                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div class="relative flex-1">
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"></i>
                        <input type="search" data-filtro-permisos placeholder="Buscar permiso o módulo…" class="{{ $inputClass }} pl-9">
                    </div>
                    <div class="flex gap-2">
                        <button type="button" data-permisos-todos="1" class="inline-flex h-10 items-center gap-2 rounded-lg border border-border px-3 text-sm font-medium whitespace-nowrap text-foreground transition hover:bg-muted">
                            <i class="fa-regular fa-square-check text-primary"></i> <span data-texto>Marcar todos</span>
                        </button>
                        <button type="button" data-permisos-todos="0" class="inline-flex h-10 items-center gap-2 rounded-lg border border-border px-3 text-sm font-medium whitespace-nowrap text-foreground transition hover:bg-muted">
                            <i class="fa-regular fa-square text-muted-foreground"></i> <span data-texto>Desmarcar todos</span>
                        </button>
                    </div>
                </div>
                <p data-permisos-resumen class="mb-3 text-xs text-muted-foreground"></p>

                <div class="space-y-2">
                    @foreach ($permisosAgrupados as $modulo => $permisos)
                        <details data-modulo class="group/mod rounded-xl border border-border bg-background">
                            <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3 select-none">
                                <i class="fa-solid fa-chevron-right text-xs text-muted-foreground transition group-open/mod:rotate-90"></i>
                                <button type="button" data-modulo-toggle title="Marcar / desmarcar todo el módulo"
                                        class="-my-1 flex h-7 w-7 items-center justify-center rounded-md text-base transition hover:bg-muted">
                                    <i class="fa-regular fa-square text-muted-foreground"></i>
                                </button>
                                <span data-modulo-nombre class="flex-1 text-sm font-medium capitalize text-foreground">{{ $modulo ?: 'Sin módulo' }}</span>
                                <span data-modulo-conteo class="rounded-full bg-muted px-2 py-0.5 text-xs tabular-nums text-muted-foreground"></span>
                            </summary>
                            <div class="grid grid-cols-1 gap-x-4 gap-y-2.5 border-t border-border p-4 sm:grid-cols-2">
                                @foreach ($permisos as $permiso)
                                    <label data-permiso class="flex cursor-pointer items-start gap-2.5 text-sm">
                                        <input type="checkbox" name="permisos[]" value="{{ $permiso->id }}" class="peer sr-only" @checked(in_array((int) $permiso->id, $permisosDe, true))>
                                        <span class="{{ $switchClass }} mt-0.5"></span>
                                        <span class="min-w-0">
                                            <span data-permiso-texto class="text-foreground">{{ $permiso->libelle }}</span>
                                            @if (in_array((int) $permiso->id, $permisosPorGrupo, true))
                                                <span class="ml-1 rounded bg-sky-500/15 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:text-sky-300">por grupo</span>
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </div>

                @include('panel.partials.ficha._paso-footer', ['clave' => 'permisos'])
            </form>
        </div>
    </div>

    <script type="application/json" data-ficha-catalogos>{!! json_encode($catalogos, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
</div>
