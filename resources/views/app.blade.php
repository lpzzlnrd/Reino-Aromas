<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- Token CSRF en meta tag: axios lo lee automáticamente para todas las
         requests. Sin esto, las llamadas POST/PUT/DELETE devuelven 419. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Reino Aromas') }}</title>
    {{-- Favicon generado desde el logo de marca (public/assets/img/logo.png).
         El .ico lleva 16/32/48 embebidos, así que un solo enlace basta. --}}
    <link rel="icon" href="/favicon.ico" sizes="any">

    {{-- Tema antes de pintar. Este script es bloqueante A PROPOSITO: si el
         tema lo aplicara solo Vue al montar, el navegador alcanzaria a pintar
         un frame con el fondo claro y quien tenga modo oscuro veria un
         fogonazo blanco en cada carga. Duplica la logica de useTheme.ts, que
         sigue siendo la fuente de verdad una vez arranca la SPA. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('reino-aromas:tema') || 'system';
                var oscuro = t === 'dark' || (t === 'system' &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches);
                var v = oscuro ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', v);
                document.documentElement.style.colorScheme = v;
            } catch (e) {
                /* Sin localStorage se queda en claro, que es el default. */
            }
        })();
    </script>

    {{-- Vite inyecta aquí el CSS y el módulo JS del Vue.
         En dev: apunta al dev server (:5173) con HMR.
         En prod: apunta a los archivos hasheados en public/build/. --}}
    @vite(['resources/views/src/main.ts'])
</head>
<body>
    {{-- Punto de montaje de Vue. Todo el UI del CRM vive aquí. --}}
    <div id="app"></div>
</body>
</html>
