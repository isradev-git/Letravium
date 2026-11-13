<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Espacio de escritura') · Espacio de escritura</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="site-shell{{ trim($__env->yieldContent('body_class')) !== '' ? ' '.trim($__env->yieldContent('body_class')) : '' }}">
    <a class="skip-link" href="#contenido">Saltar al contenido</a>
    <header class="site-header">
        <a class="site-brand" href="/" aria-label="Espacio de escritura, inicio">
            <span class="site-brand-mark" aria-hidden="true"></span>
            <span>Espacio de escritura</span>
        </a>
        <span class="site-header-note">Un lugar para cada historia</span>
    </header>

    <main id="contenido" class="@yield('main_class', 'site-main')">
        @yield('content')
    </main>
</body>
</html>
