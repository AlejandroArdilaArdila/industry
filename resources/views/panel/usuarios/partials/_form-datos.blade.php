@php
    $labelClass = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-muted-foreground';
    $inputClass = 'w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary disabled:opacity-60';
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="{{ $labelClass }}">Usuario (login)</label>
        <input type="text" value="{{ $usuario->login }}" disabled class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Civilidad</label>
        <select name="civility" class="{{ $inputClass }}">
            <option value="">—</option>
            @foreach ($civilidades as $civ)
                <option value="{{ $civ->code }}" @selected(old('civility', $usuario->civility) === $civ->code)>{{ $civ->label }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="{{ $labelClass }}">Nombres</label>
        <input type="text" name="firstname" value="{{ old('firstname', $usuario->firstname) }}" required class="{{ $inputClass }}">
        @error('firstname') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="{{ $labelClass }}">Apellidos</label>
        <input type="text" name="lastname" value="{{ old('lastname', $usuario->lastname) }}" required class="{{ $inputClass }}">
        @error('lastname') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="{{ $labelClass }}">Correo</label>
        <input type="email" name="email" value="{{ old('email', $usuario->email) }}" class="{{ $inputClass }}">
        @error('email') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="{{ $labelClass }}">Cargo</label>
        <input type="text" name="job" value="{{ old('job', $usuario->job) }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Teléfono oficina</label>
        <input type="text" name="office_phone" value="{{ old('office_phone', $usuario->office_phone) }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Celular</label>
        <input type="text" name="user_mobile" value="{{ old('user_mobile', $usuario->user_mobile) }}" class="{{ $inputClass }}">
    </div>

    <div class="sm:col-span-2">
        <label class="{{ $labelClass }}">Dirección</label>
        <input type="text" name="address" value="{{ old('address', $usuario->address) }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Ciudad</label>
        <input type="text" name="town" value="{{ old('town', $usuario->town) }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Código postal</label>
        <input type="text" name="zip" value="{{ old('zip', $usuario->zip) }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Departamento</label>
        <select name="fk_state" class="{{ $inputClass }}">
            <option value="">—</option>
            @foreach ($departamentos as $dep)
                <option value="{{ $dep->rowid }}" @selected((string) old('fk_state', $usuario->fk_state) === (string) $dep->rowid)>{{ $dep->nom }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="{{ $labelClass }}">País</label>
        <select name="fk_country" class="{{ $inputClass }}">
            <option value="">—</option>
            @foreach ($paises as $pais)
                <option value="{{ $pais->rowid }}" @selected((string) old('fk_country', $usuario->fk_country) === (string) $pais->rowid)>{{ $pais->label }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="{{ $labelClass }}">Fecha de nacimiento</label>
        <input type="date" name="birth" value="{{ old('birth', $usuario->birth ? \Illuminate\Support\Carbon::parse($usuario->birth)->format('Y-m-d') : '') }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Género</label>
        <select name="gender" class="{{ $inputClass }}">
            <option value="">—</option>
            <option value="man" @selected(old('gender', $usuario->gender) === 'man')>Hombre</option>
            <option value="woman" @selected(old('gender', $usuario->gender) === 'woman')>Mujer</option>
        </select>
    </div>
</div>

@if ($esAdmin)
    <div class="mt-6 grid grid-cols-1 gap-4 border-t border-border pt-4 sm:grid-cols-3">
        <div>
            <label class="{{ $labelClass }}">Estado</label>
            <select name="statut" class="{{ $inputClass }}">
                <option value="1" @selected((string) old('statut', $usuario->statut) === '1')>Activo</option>
                <option value="0" @selected((string) old('statut', $usuario->statut) === '0')>Inactivo</option>
            </select>
        </div>
        <div>
            <label class="{{ $labelClass }}">Administrador</label>
            <select name="admin" class="{{ $inputClass }}">
                <option value="0" @selected((string) old('admin', $usuario->admin) === '0')>No</option>
                <option value="1" @selected((string) old('admin', $usuario->admin) === '1')>Sí</option>
            </select>
        </div>
        <div>
            <label class="{{ $labelClass }}">Empleado</label>
            <select name="employee" class="{{ $inputClass }}">
                <option value="1" @selected((string) old('employee', $usuario->employee) === '1')>Sí</option>
                <option value="0" @selected((string) old('employee', $usuario->employee) === '0')>No</option>
            </select>
        </div>
    </div>
@endif
