@extends('layouts.workspace')

@section('title', 'Libro y exportación')
@section('main_class', 'site-main book-main')

@section('content')
    <header class="library-header">
        <div class="library-intro">
            <a href="/workspace/novels/{{ $novel->id }}" class="back-link">← Volver a escribir</a>
            <p class="kicker">Libro</p>
            <h1>Libro y exportación</h1>
            <p class="muted">{{ $novel->title }}. Comprueba el manuscrito y prepara una versión fija para exportar.</p>
        </div>
    </header>

    <section class="admin-card">
        <div class="admin-card-heading">
            <h2>Comprobación previa</h2>
            @if ($preflight['can_export'])
                <span class="badge">Listo para generar</span>
            @else
                <span class="badge badge-danger">Corrige los fallos</span>
            @endif
        </div>

        @if (empty($preflight['blocking']) && empty($preflight['warnings']))
            <p class="muted">Sin avisos.</p>
        @else
            @if (! empty($preflight['blocking']))
                <h3 class="preflight-title">Fallos que impiden exportar</h3>
                <ul class="preflight-list">
                    @foreach ($preflight['blocking'] as $issue)
                        <li>{{ $issue['message'] }}</li>
                    @endforeach
                </ul>
            @endif

            @if (! empty($preflight['warnings']))
                <h3 class="preflight-title">Avisos</h3>
                <ul class="preflight-list preflight-warnings">
                    @foreach ($preflight['warnings'] as $issue)
                        <li>{{ $issue['message'] }}</li>
                    @endforeach
                </ul>
            @endif
        @endif
    </section>

    <section
        class="admin-card"
        data-book-export
        data-endpoint="/workspace/novels/{{ $novel->id }}/export-manifests"
    >
        <h2>Preparar exportación</h2>
        <p class="muted">Se fija una copia del manuscrito en el momento de prepararla. Los cambios posteriores no se mezclan.</p>

        <form id="book-export-form" class="book-export-form">
            @if ($profiles->isNotEmpty())
                <div class="format-field">
                    <label for="book-profile">Perfil editorial</label>
                    <select id="book-profile" name="profile_id">
                        <option value="">Medidas por defecto</option>
                        @foreach ($profiles as $profile)
                            <option value="{{ $profile->id }}">{{ $profile->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <p>
                <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/book/profiles">Gestionar perfiles editoriales</a>
                <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/book/metadata">Editar metadatos</a>
                <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/book/sections">Secciones</a>
                <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/book/navigation">Índices y navegación</a>
            </p>

            <fieldset class="book-formats">
                <legend>Formatos</legend>
                <label><input type="checkbox" name="formats" value="txt" checked> TXT</label>
                <label><input type="checkbox" name="formats" value="docx" checked> DOCX</label>
                <label><input type="checkbox" name="formats" value="epub" checked> EPUB</label>
                <label><input type="checkbox" name="formats" value="pdf" checked> PDF</label>
            </fieldset>

            <button
                type="button"
                id="book-export-prepare"
                class="button button-primary"
                @disabled(! $preflight['can_export'])
            >Preparar exportación</button>
        </form>

        <p id="book-export-status" class="editor-status" role="status" aria-live="polite"></p>

        <div id="book-export-links" hidden>
            <h3>Archivos preparados</h3>
            <ul id="book-export-list" class="export-links"></ul>
        </div>
    </section>
@endsection
