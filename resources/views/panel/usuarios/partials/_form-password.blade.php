@php
    $labelClass = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-muted-foreground';
    $inputClass = 'w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';
    $passwordAction = $formularioParaOtro ? route('panel.usuarios.password', $usuario) : route('panel.perfil.password');
@endphp

<form method="POST" action="{{ $passwordAction }}" class="max-w-md space-y-4">
    @csrf
    @method('PUT')

    @if (!$formularioParaOtro)
        <div>
            <label class="{{ $labelClass }}">Contraseña actual</label>
            <input type="password" name="current_password" required class="{{ $inputClass }}">
            @error('current_password') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
        </div>
    @endif

    <div>
        <label class="{{ $labelClass }}">Contraseña nueva</label>
        <input type="password" name="password" required minlength="8" class="{{ $inputClass }}">
        @error('password') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="{{ $labelClass }}">Confirmar contraseña nueva</label>
        <input type="password" name="password_confirmation" required minlength="8" class="{{ $inputClass }}">
    </div>

    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90">
        {{ $formularioParaOtro ? 'Restablecer contraseña' : 'Cambiar contraseña' }}
    </button>
</form>
