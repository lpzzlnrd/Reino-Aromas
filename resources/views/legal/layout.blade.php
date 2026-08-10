{{--
    Layout de las páginas legales públicas (privacidad, términos, eliminación
    de datos).

    Meta abre estas URLs automáticamente durante App Review y rechaza la app si
    devuelven 404 o exigen login — por eso viven fuera del middleware 'auth'.

    Es Blade puro como el login: carga solo resources/css/app.css y no el bundle
    Vue de 1.4MB, que aquí no aporta nada.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') — {{ config('app.name', 'Reino Aromas') }}</title>
    {{-- Favicon generado desde el logo de marca (public/assets/img/logo.png).
         El .ico lleva 16/32/48 embebidos, así que un solo enlace basta. --}}
    <link rel="icon" href="/favicon.ico" sizes="any">
    <meta name="description" content="@yield('meta_description', 'Información legal de Reino Aromas')">

    {{-- Los revisores de Meta necesitan poder leer e indexar estas páginas --}}
    <meta name="robots" content="index, follow">

    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[#FFFCED] font-sans text-[#6D123F] antialiased">

    {{-- Cabecera --}}
    <header class="border-b border-[#6D123F]/10 bg-white/60 backdrop-blur-sm sticky top-0 z-10">
        <div class="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between gap-4">
            <a href="/" class="flex items-center gap-3 shrink-0">
                <img src="/assets/img/logo.png" alt="Reino Aromas" class="h-9">
            </a>
            <nav class="flex items-center gap-4 sm:gap-6 text-[10px] sm:text-xs font-bold uppercase tracking-widest">
                <a href="{{ route('legal.privacidad') }}"
                   class="transition hover:text-[#FF8B95] {{ request()->routeIs('legal.privacidad') ? 'text-[#FF8B95]' : 'text-[#6D123F]/50' }}">
                    Privacidad
                </a>
                <a href="{{ route('legal.terminos') }}"
                   class="transition hover:text-[#FF8B95] {{ request()->routeIs('legal.terminos') ? 'text-[#FF8B95]' : 'text-[#6D123F]/50' }}">
                    Términos
                </a>
                <a href="{{ route('legal.eliminacion-datos') }}"
                   class="transition hover:text-[#FF8B95] {{ request()->routeIs('legal.eliminacion-datos') ? 'text-[#FF8B95]' : 'text-[#6D123F]/50' }}">
                    Tus datos
                </a>
            </nav>
        </div>
    </header>

    {{-- Contenido --}}
    <main class="max-w-3xl mx-auto px-6 py-12 sm:py-16">

        <div class="mb-10 pb-8 border-b border-[#6D123F]/10">
            <h1 class="font-[family-name:--font-primary] text-3xl sm:text-4xl font-bold leading-tight">
                @yield('title')
            </h1>
            <p class="mt-3 text-xs text-[#6D123F]/50 uppercase tracking-widest">
                Última actualización: {{ $ultimaActualizacion }}
            </p>
        </div>

        {{--
            Las utilidades de tipografía se aplican con selectores descendientes
            porque el contenido son etiquetas HTML planas de cada vista hija.
            Evita repetir clases en cada <p> y <h2>.
        --}}
        <div class="legal-prose space-y-6 text-sm sm:text-[15px] leading-relaxed text-[#6D123F]/80">
            @yield('content')
        </div>

    </main>

    {{-- Pie --}}
    <footer class="border-t border-[#6D123F]/10 mt-8">
        <div class="max-w-3xl mx-auto px-6 py-8 text-center">
            <p class="text-xs text-[#6D123F]/50">
                ¿Dudas sobre tus datos? Escríbenos a
                <a href="mailto:{{ $correoContacto }}" class="font-bold text-[#6D123F]/70 underline decoration-[#FF8B95] underline-offset-2 hover:text-[#FF8B95] transition">
                    {{ $correoContacto }}
                </a>
            </p>
            <p class="mt-4 text-[10px] text-[#6D123F]/40 uppercase tracking-[0.2em]">
                © {{ date('Y') }} Reino Aromas
            </p>
        </div>
    </footer>

    <style>
        /* Estilos del contenido legal. Van aquí y no en app.css porque solo
           aplican a estas tres páginas. */
        .legal-prose h2 {
            font-family: 'Roxborough CF', serif;
            font-size: 1.375rem;
            font-weight: 700;
            color: #6D123F;
            margin-top: 2.5rem;
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }
        .legal-prose h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #6D123F;
            margin-top: 1.75rem;
            margin-bottom: 0.5rem;
        }
        .legal-prose ul {
            list-style: none;
            padding-left: 0;
            margin: 1rem 0;
        }
        .legal-prose ul li {
            position: relative;
            padding-left: 1.5rem;
            margin-bottom: 0.625rem;
        }
        /* El marcador va con ::before para poder darle el color de marca */
        .legal-prose ul li::before {
            content: '';
            position: absolute;
            left: 0.25rem;
            top: 0.6rem;
            width: 0.375rem;
            height: 0.375rem;
            border-radius: 9999px;
            background-color: #FF8B95;
        }
        .legal-prose ol {
            list-style: decimal;
            padding-left: 1.5rem;
            margin: 1rem 0;
        }
        .legal-prose ol li {
            margin-bottom: 0.625rem;
            padding-left: 0.25rem;
        }
        .legal-prose strong {
            color: #6D123F;
            font-weight: 700;
        }
        .legal-prose a {
            color: #6D123F;
            font-weight: 700;
            text-decoration: underline;
            text-decoration-color: #FF8B95;
            text-underline-offset: 2px;
            transition: color 0.15s;
        }
        .legal-prose a:hover {
            color: #FF8B95;
        }
        /* Bloque destacado para avisos importantes */
        .legal-note {
            background-color: rgba(252, 192, 197, 0.18);
            border-left: 3px solid #FF8B95;
            border-radius: 0.5rem;
            padding: 1rem 1.25rem;
            margin: 1.5rem 0;
        }
        .legal-note p:last-child {
            margin-bottom: 0;
        }
    </style>

</body>
</html>
