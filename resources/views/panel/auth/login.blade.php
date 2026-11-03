@extends('panel.layouts.app')

@section('title', 'Iniciar sesión')

@section('content')
    <div
        class="relative flex min-h-screen items-center justify-center overflow-hidden bg-gray-900 px-4 lg:justify-end lg:px-16">
        <div class="absolute inset-0 bg-cover bg-center"
            style="background-image: url('{{ asset('images/background/cacao1.jpg') }}')"></div>
        <div class="absolute inset-0 bg-black/30"></div>

        <div class="relative w-full max-w-sm rounded-2xl bg-white p-8 shadow-2xl">
            <div class="mb-6 flex justify-center">
                <img src="{{ 'logo.png' }}" alt="{{ config('app.name') }}" class="h-20 w-auto object-contain">
            </div>

            <h1 class="mb-6 text-center text-lg font-bold tracking-wide text-cyan-600">
                INICIAR SESIÓN
            </h1>

            @if ($errors->any())
                <div class="mb-4 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.submit') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="login" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Usuario
                    </label>
                    <div
                        class="flex items-center gap-2 rounded border border-gray-300 px-3 py-2 focus-within:border-cyan-500">
                        <i class="fa-solid fa-user text-gray-400"></i>
                        <input type="text" name="login" id="login" value="{{ old('login') }}" required autofocus
                            class="w-full border-0 p-0 text-sm text-gray-900 focus:outline-none focus:ring-0">
                    </div>
                </div>

                <div>
                    <label for="password" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Contraseña
                    </label>
                    <div
                        class="flex items-center gap-2 rounded border border-gray-300 px-3 py-2 focus-within:border-cyan-500">
                        <i class="fa-solid fa-lock text-gray-400"></i>
                        <input type="password" name="password" id="password" required
                            class="w-full border-0 p-0 text-sm text-gray-900 focus:outline-none focus:ring-0">
                    </div>
                </div>

                {{-- Solo visual por ahora: la tabla `user` de Dolibarr no tiene columna
                 remember_token, y agregarla implicaría alterar su esquema. --}}
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="remember"
                        class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500">
                    Recuérdame
                </label>

                <button type="submit"
                    class="flex w-full items-center justify-center gap-2 rounded bg-cyan-600 px-3 py-2.5 text-sm font-semibold text-white hover:bg-cyan-700">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    Ingresar
                </button>
            </form>
        </div>
    </div>
@endsection
