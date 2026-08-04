<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- Token CSRF en meta tag: axios lo lee automáticamente para todas las
         requests. Sin esto, las llamadas POST/PUT/DELETE devuelven 419. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Reino Aromas') }}</title>
    <link rel="icon" href="/favicon.ico">

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
