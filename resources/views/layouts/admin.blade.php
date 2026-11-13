<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Administración') · Administración</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="site-shell admin-shell">
    <a class="skip-link" href="#contenido">Saltar al contenido</a>
    <header class="site-header admin-header">
        <a class="site-brand" href="/admin" aria-label="Administración, inicio">
            <span class="site-brand-mark" aria-hidden="true"></span>
            <span>Administración</span>
        </a>
        <nav class="admin-nav" aria-label="Secciones de administración">
            <a href="/admin" @if (($active ?? '') === 'index') class="is-active" aria-current="page" @endif>Resumen</a>
            <a href="/admin/accounts" @if (($active ?? '') === 'accounts') class="is-active" aria-current="page" @endif>Cuentas</a>
            <a href="/admin/audit" @if (($active ?? '') === 'audit') class="is-active" aria-current="page" @endif>Auditoría</a>
            @if (auth()->user()?->canManageCommunications())
                <a href="/admin/campaigns" @if (($active ?? '') === 'campaigns') class="is-active" aria-current="page" @endif>Campañas</a>
            @endif
        </nav>
        <form method="POST" action="/logout">
            @csrf
            <button class="button button-secondary" type="submit">Cerrar sesión</button>
        </form>
    </header>

    <main id="contenido" class="site-main admin-main">
        @yield('content')
    </main>
</body>
</html>
