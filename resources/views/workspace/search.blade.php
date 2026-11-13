@extends('layouts.workspace')

@section('title', 'Buscar en '.$novel->title)
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Buscar</p>
            <h1>Buscar en {{ $novel->title }}</h1>
            <p class="muted">Busca en capítulos, escenas y fichas autorizadas de esta novela.</p>
        </div>

        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}">Volver a la novela</a>
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <form method="GET" action="/workspace/novels/{{ $novel->id }}/search" class="inline-form search-form" role="search">
        <label for="search-query">Texto a buscar</label>
        <div class="field-row">
            <input id="search-query" name="q" type="search" value="{{ $query }}" maxlength="200" required placeholder="Una palabra o frase">
            <select name="type" aria-label="Tipo de resultado">
                <option value="">Todo</option>
                <option value="chapter" @selected($type === 'chapter')>Capítulos</option>
                <option value="scene" @selected($type === 'scene')>Escenas</option>
                <option value="card" @selected($type === 'card')>Fichas</option>
            </select>
            <button class="button button-primary" type="submit">Buscar</button>
        </div>
    </form>

    @if ($results === null)
        <section class="empty-state" aria-live="polite">
            <h2>Todavía no hay búsqueda</h2>
            <p>Escribe una palabra o frase para revisar el contenido de la novela.</p>
        </section>
    @elseif ($results['count'] === 0)
        <section class="empty-state" aria-live="polite">
            <h2>Sin resultados</h2>
            <p>No encontramos «{{ $query }}» en el contenido autorizado.</p>
        </section>
    @else
        <section aria-labelledby="search-results-title">
            <div class="library-section-heading">
                <h2 id="search-results-title">Resultados</h2>
                <span class="muted">{{ $results['count'] }} encontrados</span>
            </div>

            @if ($results['truncated'])
                <p class="muted" role="status">Mostrando los primeros resultados. Afina la búsqueda para ver más.</p>
            @endif

            <ul class="universe-list search-results">
                @foreach ($results['results'] as $result)
                    <li class="universe-card">
                        <div class="universe-heading">
                            <h3>
                                @if ($result['type'] === 'scene')
                                    <a href="/workspace/novels/{{ $novel->id }}#scene-{{ $result['scene_id'] }}">{{ $result['title'] ?: 'Escena' }}</a>
                                @elseif ($result['type'] === 'chapter')
                                    <a href="/workspace/novels/{{ $novel->id }}#chapter-{{ $result['id'] }}">{{ $result['title'] ?: 'Capítulo sin título' }}</a>
                                @else
                                    {{ $result['title'] }}
                                @endif
                            </h3>
                            <span class="muted">{{ $result['type'] === 'card' ? 'Ficha' : ($result['type'] === 'chapter' ? 'Capítulo' : 'Escena') }}</span>
                        </div>
                        <p>{{ $result['excerpt'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="admin-card">
            <h2>Sustituir</h2>
            <p class="muted">Previsualiza las coincidencias antes de escribir. Solo se sustituyen las palabras completas de los capítulos elegidos.</p>
            <form method="POST" action="/workspace/novels/{{ $novel->id }}/replace/preview?format=html" class="inline-form">
                @csrf
                <input type="hidden" name="search" value="{{ $query }}">
                <div class="format-field">
                    <label for="replacement-text">Texto de sustitución</label>
                    <input id="replacement-text" name="replacement" type="text" required maxlength="200">
                </div>
                <fieldset>
                    <legend>Capítulos</legend>
                    @foreach ($chapters as $chapter)
                        <label>
                            <input type="checkbox" name="chapter_ids[]" value="{{ $chapter->id }}" checked>
                            {{ $chapter->title ?: 'Capítulo sin título' }}
                        </label>
                    @endforeach
                </fieldset>
                <input type="hidden" name="whole_word" value="0">
                <label><input type="checkbox" name="whole_word" value="1" checked> Palabra completa</label>
                <input type="hidden" name="case_sensitive" value="0">
                <label><input type="checkbox" name="case_sensitive" value="1"> Distinguir mayúsculas</label>
                <button class="button button-primary" type="submit">Previsualizar sustitución</button>
            </form>
        </section>
    @endif
@endsection
