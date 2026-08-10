<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión — {{ config('app.name', 'Reino Aromas') }}</title>
    {{-- Favicon generado desde el logo de marca (public/assets/img/logo.png).
         El .ico lleva 16/32/48 embebidos, así que un solo enlace basta. --}}
    <link rel="icon" href="/favicon.ico" sizes="any">

    {{-- Tailwind compilado por el Vite raíz (resources/css/app.css).
         El login es una vista Blade pura — no necesita cargar todo el bundle Vue. --}}
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen flex items-center justify-center bg-[#FFFCED] font-sans">

    <div class="w-full max-w-md p-8 bg-white/70 border border-[#6D123F]/10 rounded-2xl shadow-xl">

        <div class="mb-8 text-center">
            <img src="/assets/img/logo.png" alt="Reino Aromas" class="h-14 mx-auto mb-4">
            <h1 class="text-2xl font-bold text-[#6D123F]">Bienvenido</h1>
            <p class="text-xs text-[#6D123F]/60 uppercase tracking-widest mt-1">Ingresa tus credenciales</p>
        </div>

        {{-- Errores de validación --}}
        @if ($errors->any())
            <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-600">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-5">
            @csrf

            <div>
                <label for="email" class="block text-xs font-bold text-[#6D123F]/60 uppercase tracking-widest mb-1">
                    Correo electrónico
                </label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    value="{{ old('email') }}"
                    placeholder="admin@reinoaromas.com"
                    class="w-full px-4 py-3 border border-[#6D123F]/15 rounded-xl text-sm focus:outline-none focus:border-[#FF8B95] focus:ring-2 focus:ring-[#FCC0C5]/30 transition"
                >
            </div>

            <div>
                <label for="password" class="block text-xs font-bold text-[#6D123F]/60 uppercase tracking-widest mb-1">
                    Contraseña
                </label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    required
                    autocomplete="current-password"
                    placeholder="••••••••"
                    class="w-full px-4 py-3 border border-[#6D123F]/15 rounded-xl text-sm focus:outline-none focus:border-[#FF8B95] focus:ring-2 focus:ring-[#FCC0C5]/30 transition"
                >
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 cursor-pointer text-xs text-[#6D123F]/60">
                    <input type="checkbox" name="remember" class="rounded border-[#6D123F]/20">
                    Recordarme
                </label>
            </div>

            <button
                type="submit"
                class="w-full py-3 text-sm font-bold uppercase tracking-widest text-[#BD612A]
                       bg-gradient-to-r from-[#FCC0C5] to-[#FF8B95]
                       rounded-xl shadow-lg shadow-[#FF8B95]/20
                       hover:brightness-105 hover:-translate-y-0.5 transition-all"
            >
                Iniciar sesión
            </button>
        </form>

        {{-- Enlaces legales. Meta espera poder llegar a la política de
             privacidad desde la propia interfaz, no solo por URL directa. --}}
        <div class="mt-8 pt-6 border-t border-[#6D123F]/10 text-center">
            <div class="flex items-center justify-center gap-4 text-[10px] uppercase tracking-widest text-[#6D123F]/40">
                <a href="{{ route('legal.privacidad') }}" class="hover:text-[#FF8B95] transition">Privacidad</a>
                <span class="text-[#6D123F]/20">·</span>
                <a href="{{ route('legal.terminos') }}" class="hover:text-[#FF8B95] transition">Términos</a>
                <span class="text-[#6D123F]/20">·</span>
                <a href="{{ route('legal.eliminacion-datos') }}" class="hover:text-[#FF8B95] transition">Tus datos</a>
            </div>
            <p class="mt-4 text-[10px] text-[#6D123F]/40 uppercase tracking-[0.2em]">
                Reino Aromas v2.0
            </p>
        </div>
    </div>

</body>
</html>
