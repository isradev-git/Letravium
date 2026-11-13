@extends('layouts.workspace')

@section('title', 'Previsualizar sustitución en '.$novel->title)
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Sustitución</p>
            <h1>Previsualizar sustitución</h1>
            <p class="muted">«{{ $data['search'] }}» → «{{ $data['replacement'] }}» en {{ count($preview['chapter_ids']) }} capítulos.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/search?q={{ urlencode($data['search']) }}">Volver a la búsqueda</a>
        </div>
    </div>

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <section class="admin-card">
        <div class="admin-card-heading">
            <h2>Coincidencias</h2>
            <span class="badge">{{ $preview['count'] }}</span>
        </div>

        @if ($preview['truncated'])
            <p class="muted" role="alert">Hay demasiadas coincidencias. Reduce los capítulos o afina la búsqueda; no se aplicará una sustitución parcial.</p>
        @elseif ($preview['count'] === 0)
            <p class="muted">No hay coincidencias que sustituir.</p>
        @else
            <ul class="universe-list search-results">
                @foreach ($preview['matches'] as $match)
                    <li class="universe-card">
                        <div class="universe-heading">
                            <h3>{{ $chapters[$match['chapter_id']]->title ?? 'Capítulo sin título' }}</h3>
                            <span class="muted">{{ $scenes[$match['scene_id']]->title ?? 'Escena' }}</span>
                        </div>
                        <p>{{ $match['excerpt'] }}</p>
                    </li>
                @endforeach
            </ul>

            <form method="POST" action="/workspace/novels/{{ $novel->id }}/replace?format=html" class="inline-form">
                @csrf
                <input type="hidden" name="search" value="{{ $data['search'] }}">
                <input type="hidden" name="replacement" value="{{ $data['replacement'] }}">
                <input type="hidden" name="whole_word" value="{{ (int) ($data['whole_word'] ?? true) }}">
                <input type="hidden" name="case_sensitive" value="{{ (int) ($data['case_sensitive'] ?? false) }}">
                <input type="hidden" name="preview_hash" value="{{ $preview['preview_hash'] }}">
                @foreach ($preview['chapter_ids'] as $chapterId)
                    <input type="hidden" name="chapter_ids[]" value="{{ $chapterId }}">
                @endforeach
                <button class="button button-primary" type="submit">Aplicar sustitución</button>
            </form>
        @endif
    </section>
@endsection
