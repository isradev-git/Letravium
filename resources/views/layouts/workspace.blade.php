@php($workspaceNav = app(\App\Services\WorkspaceNavigation::class)->context())
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Espacio de escritura') · Espacio de escritura</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="site-shell workspace-shell{{ trim($__env->yieldContent('body_class')) !== '' ? ' '.trim($__env->yieldContent('body_class')) : '' }}" data-workspace-shell>
    <a class="skip-link" href="#contenido">Saltar al contenido</a>

    <div class="workspace-shell-frame">
        <header class="workspace-mobilebar">
            <a class="workspace-mobilebar-brand" href="/workspace">
                <span class="workspace-brand-mark" aria-hidden="true"></span>
                <span>Espacio</span>
            </a>
            <button
                type="button"
                class="workspace-mobilebar-toggle"
                data-workspace-nav-toggle
                aria-controls="workspace-nav"
                aria-expanded="false"
            >Menú</button>
        </header>

        @include('workspace.sidebar', [
            'navNovel' => $workspaceNav['novel'],
            'navUniverse' => $workspaceNav['universe'],
            'navOwner' => $workspaceNav['owner'],
            'navCanRead' => $workspaceNav['canRead'],
            'navActive' => $workspaceNav['active'],
        ])

        <div class="workspace-sidebar-backdrop" data-workspace-nav-backdrop hidden></div>

        <div class="workspace-stage">
            <main id="contenido" class="@yield('main_class', 'site-main workspace-main')">
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
