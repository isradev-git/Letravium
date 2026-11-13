@extends('layouts.workspace')

@section('title', 'Historial de '.($scene->title ?: 'la escena'))
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Escena</p>
            <h1>Historial de la escena</h1>
            <p class="muted">
                {{ $novel->title }} · {{ $chapter->title }} · {{ $scene->title ?: 'Escena sin título' }}
            </p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}?view=chapter&chapter={{ $chapter->id }}">Volver a escribir</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="universe-card" aria-labelledby="split-title">
        <h2 id="split-title">Dividir escena</h2>
        <p class="muted">La segunda parte se convierte en una escena nueva a continuación.</p>
        <form method="POST" action="/workspace/scenes/{{ $scene->id }}/split?format=html" class="inline-form">
            @csrf
            <input type="hidden" name="base_revision" value="{{ $scene->revision }}">
            <div class="field-row">
                <div class="field">
                    <label for="split-block-index">Bloque donde dividir</label>
                    <input id="split-block-index" name="block_index" type="number" min="1" required>
                </div>
                <div class="field">
                    <label for="split-scene-title">Título de la nueva escena</label>
                    <input id="split-scene-title" name="title" type="text" maxlength="200">
                </div>
            </div>
            <button class="button button-secondary" type="submit">Dividir escena</button>
        </form>
    </section>

    <section class="universe-card" aria-labelledby="merge-title">
        <h2 id="merge-title">Fusionar con la escena siguiente</h2>
        <p class="muted">El contenido de la escena siguiente se añade al final de esta escena.</p>
        <form method="POST" action="/workspace/scenes/{{ $scene->id }}/merge?format=html" class="inline-form">
            @csrf
            <input type="hidden" name="base_revision" value="{{ $scene->revision }}">
            <button class="button button-secondary" type="submit">Fusionar escena siguiente</button>
        </form>
    </section>

    <section class="library-shelf" aria-labelledby="revisions-title">
        <div class="library-shelf-heading">
            <h2 id="revisions-title">Revisiones guardadas</h2>
        </div>

        @if (empty($revisions))
            <p class="muted">Todavía no hay revisiones guardadas para esta escena.</p>
        @else
            <ul class="universe-list">
                @foreach ($revisions as $revision)
                    @php($blocks = $revision['content']['blocks'] ?? [])
                    <li class="universe-card">
                        <div class="universe-heading">
                            <h3>Revisión {{ $revision['revision'] }}</h3>
                            <span class="muted">{{ $revision['created_at'] }}</span>
                        </div>
                        <p class="muted">
                            {{ match ($revision['reason']) {
                                'automatic' => 'Guardado automático',
                                'split' => 'División',
                                'merge' => 'Fusión',
                                default => 'Guardado',
                            } }}
                        </p>
                        @if (! empty($blocks))
                            <ul>
                                @foreach ($blocks as $block)
                                    @if (! empty($block['text']))
                                        <li>{{ $block['text'] }}</li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($next_cursor)
            <nav class="editor-more" aria-label="Más revisiones">
                <a class="button button-secondary" href="/workspace/scenes/{{ $scene->id }}/revisions?cursor={{ urlencode($next_cursor) }}">
                    Cargar más revisiones
                </a>
            </nav>
        @endif
    </section>
@endsection
