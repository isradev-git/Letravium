@extends('layouts.workspace')

@section('title', 'Revisar importación')
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Importación</p>
            <h1>Revisar antes de importar</h1>
            <p class="muted">{{ $preview['source']['name'] }} · {{ strtoupper($preview['source']['format']) }} · {{ $preview['source']['encoding'] }}</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/imports">Elegir otro archivo</a>
        </div>
    </div>

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <section class="admin-card">
        <h2>Resumen</h2>
        <p class="muted">
            Capítulos: {{ count($preview['chapters']) }} ·
            Encabezados: {{ count($preview['inventory']['headings']) }} ·
            Tablas: {{ $preview['inventory']['tables'] }} ·
            Imágenes: {{ $preview['inventory']['images'] }} ·
            Notas: {{ $preview['inventory']['footnotes'] + $preview['inventory']['endnotes'] }}
        </p>

        @if (! empty($preview['unsupported']))
            <h3 class="preflight-title">Contenido omitido</h3>
            <ul class="preflight-list">
                @foreach ($preview['unsupported'] as $item)
                    <li>{{ $item['type'] }}: {{ $item['count'] }}</li>
                @endforeach
            </ul>
        @endif

        @if (! empty($preview['losses']))
            <h3 class="preflight-title">Pérdidas declaradas</h3>
            <ul class="preflight-list preflight-warnings">
                @foreach ($preview['losses'] as $loss)
                    <li>{{ $loss }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    <form method="POST" action="/workspace/imports/commit?format=html" class="admin-card">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        @php($mode = $destination['mode'] ?? 'new_novel')
        <input type="hidden" name="mode" value="{{ $mode }}">

        @if ($mode === 'append')
            <input type="hidden" name="novel_id" value="{{ $destination['novel_id'] ?? '' }}">
            <p class="muted">Los capítulos se añadirán al final de la novela elegida.</p>
        @else
            <input type="hidden" name="universe_id" value="{{ $destination['universe_id'] ?? '' }}">
            <div class="format-field">
                <label for="import-title">Título de la novela</label>
                <input id="import-title" name="title" type="text" required maxlength="200" value="{{ old('title', $suggestedTitle) }}">
            </div>
        @endif

        <h2>Capítulos propuestos</h2>
        <p class="muted">Puedes corregir títulos y texto antes de confirmar.</p>

        @foreach ($preview['chapters'] as $index => $chapter)
            <fieldset class="admin-card">
                <legend>Capítulo {{ $index + 1 }}</legend>
                <div class="format-field">
                    <label for="chapter-title-{{ $index }}">Título</label>
                    <input id="chapter-title-{{ $index }}" name="chapters[{{ $index }}][title]" type="text" maxlength="200" value="{{ old("chapters.$index.title", $chapter['title'] ?? '') }}">
                </div>
                <div class="format-field">
                    <label for="chapter-text-{{ $index }}">Texto</label>
                    <textarea id="chapter-text-{{ $index }}" name="chapters[{{ $index }}][text]" rows="10" required>{{ old("chapters.$index.text", $chapter['text']) }}</textarea>
                </div>
            </fieldset>
        @endforeach

        <button class="button button-primary" type="submit">Importar capítulos revisados</button>
    </form>
@endsection
