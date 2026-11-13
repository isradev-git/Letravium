@php($navNovel = $navNovel ?? null)
@php($navUniverse = $navUniverse ?? null)
@php($navOwner = $navOwner ?? false)
@php($navCanRead = $navCanRead ?? $navOwner)
@php($navActive = $navActive ?? '')
@php($universeId = $navNovel?->universe_id ?? $navUniverse?->id)
<nav id="workspace-nav" class="workspace-sidebar" aria-label="Navegación principal">
    <div class="workspace-sidebar-head">
        <a class="workspace-brand" href="/workspace" aria-label="Espacio de escritura, biblioteca">
            <span class="workspace-brand-mark" aria-hidden="true"></span>
            <span>Espacio</span>
        </a>
        <button type="button" class="workspace-sidebar-close" data-workspace-nav-close aria-label="Cerrar menú">×</button>
    </div>

    <div class="workspace-sidebar-scroll">
        <section class="workspace-sidebar-group" data-nav-group="library" aria-labelledby="nav-library">
            <h2 id="nav-library" class="workspace-sidebar-label">Biblioteca</h2>
            <ul>
                <li><a href="/workspace" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'library']) @if ($navActive === 'library') aria-current="page" @endif data-icon="B">Biblioteca</a></li>
                <li data-nav-group="universe">
                    @if ($navOwner)
                        <a href="{{ $universeId ? '/workspace/universes/'.$universeId.'/cards' : '/workspace#universos' }}" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'universe']) @if ($navActive === 'universe') aria-current="page" @endif data-icon="U">Universos</a>
                    @else
                        <span class="workspace-sidebar-link is-disabled" aria-disabled="true" data-icon="U">Universos</span>
                    @endif
                </li>
                <li><a href="/workspace#colecciones" class="workspace-sidebar-link" data-icon="C">Colecciones</a></li>
            </ul>
        </section>

        <section class="workspace-sidebar-group" data-nav-group="novel" aria-labelledby="nav-novel">
            <h2 id="nav-novel" class="workspace-sidebar-label">{{ $navNovel?->title ?: 'Novela activa' }}</h2>

            @if ($navNovel)
                <ul>
                    @if ($navOwner)
                        <li><a href="/workspace/universes/{{ $universeId }}/cards" class="workspace-sidebar-link" data-icon="U">Universo</a></li>
                        <li><a href="/workspace/novels/{{ $navNovel->id }}/planning" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'planning']) @if ($navActive === 'planning') aria-current="page" @endif data-icon="P">Planificar</a></li>
                    @endif
                    <li>
                        @if ($navCanRead)
                            <a href="/workspace/novels/{{ $navNovel->id }}" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'writing']) @if ($navActive === 'writing') aria-current="page" @endif data-icon="E">Escribir</a>
                        @else
                            <span class="workspace-sidebar-link is-disabled" aria-disabled="true" data-icon="E">Escribir</span>
                        @endif
                    </li>
                    @if ($navOwner)
                        <li><a href="/workspace/novels/{{ $navNovel->id }}/comments" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'review']) @if ($navActive === 'review') aria-current="page" @endif data-icon="R">Revisar</a></li>
                        <li><a href="/workspace/novels/{{ $navNovel->id }}/snapshots" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'versions']) @if ($navActive === 'versions') aria-current="page" @endif data-icon="V">Versiones</a></li>
                        <li><a href="/workspace/novels/{{ $navNovel->id }}/book" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'book']) @if ($navActive === 'book') aria-current="page" @endif data-icon="L">Libro</a></li>
                    @endif
                </ul>
            @else
                <p class="workspace-sidebar-hint">Abre una novela para planificar, revisar o exportar.</p>
                <ul aria-disabled="true">
                    <li><span class="workspace-sidebar-link is-disabled" aria-disabled="true" data-icon="U">Universo</span></li>
                    <li><span class="workspace-sidebar-link is-disabled" aria-disabled="true" data-icon="P">Planificar</span></li>
                    <li><span class="workspace-sidebar-link is-disabled" aria-disabled="true" data-icon="E">Escribir</span></li>
                    <li><span class="workspace-sidebar-link is-disabled" aria-disabled="true" data-icon="R">Revisar</span></li>
                    <li><span class="workspace-sidebar-link is-disabled" aria-disabled="true" data-icon="V">Versiones</span></li>
                    <li><span class="workspace-sidebar-link is-disabled" aria-disabled="true" data-icon="L">Libro</span></li>
                </ul>
            @endif
        </section>

        <section class="workspace-sidebar-group" data-nav-group="archive" aria-labelledby="nav-archive">
            <h2 id="nav-archive" class="workspace-sidebar-label">Archivo</h2>
            <ul>
                <li><a href="/workspace/imports" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'imports']) @if ($navActive === 'imports') aria-current="page" @endif data-icon="I">Importar</a></li>
                <li><a href="/workspace/backups" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'backups']) @if ($navActive === 'backups') aria-current="page" @endif data-icon="C">Copias</a></li>
                <li><a href="/workspace/fonts" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'fonts']) @if ($navActive === 'fonts') aria-current="page" @endif data-icon="F">Fuentes</a></li>
                <li><a href="/workspace/trash" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'trash']) @if ($navActive === 'trash') aria-current="page" @endif data-icon="P">Papelera</a></li>
            </ul>
        </section>

        <section class="workspace-sidebar-group workspace-sidebar-account" data-nav-group="account" aria-labelledby="nav-account">
            <h2 id="nav-account" class="workspace-sidebar-label">Cuenta</h2>
            <ul>
                <li><a href="/account/sessions" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'account-sessions']) @if ($navActive === 'account-sessions') aria-current="page" @endif data-icon="S">Sesiones</a></li>
                <li><a href="/account/marketing" @class(['workspace-sidebar-link', 'is-active' => $navActive === 'account-marketing']) @if ($navActive === 'account-marketing') aria-current="page" @endif data-icon="C">Comunicaciones</a></li>
            </ul>
            <form method="POST" action="/logout" class="workspace-sidebar-logout-form">
                @csrf
                <button type="submit" class="workspace-sidebar-link workspace-sidebar-logout" data-icon="X">Cerrar sesión</button>
            </form>
        </section>
    </div>
</nav>
