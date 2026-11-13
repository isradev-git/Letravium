@extends('layouts.workspace')

@section('title', 'Metadatos de '.$novel->title)
@section('main_class', 'site-main book-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Libro</p>
            <h1>Metadatos del libro</h1>
            <p class="muted">{{ $novel->title }}. Estos datos acompañan a la exportación y a la previsualización editorial.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/book">Volver al libro</a>
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="/workspace/novels/{{ $novel->id }}/book/metadata" class="admin-card">
        @csrf
        @method('PUT')

        <div class="field-row">
            <div class="format-field">
                <label for="book-title">Título</label>
                <input id="book-title" name="title" type="text" maxlength="255" value="{{ old('title', $metadata->title ?? $novel->title) }}">
            </div>
            <div class="format-field">
                <label for="book-subtitle">Subtítulo</label>
                <input id="book-subtitle" name="subtitle" type="text" maxlength="255" value="{{ old('subtitle', $metadata->subtitle) }}">
            </div>
        </div>

        <div class="field-row">
            <div class="format-field">
                <label for="book-author">Autoría</label>
                <input id="book-author" name="author" type="text" maxlength="255" value="{{ old('author', $metadata->author) }}">
            </div>
            <div class="format-field">
                <label for="book-language">Idioma</label>
                <input id="book-language" name="language" type="text" maxlength="16" value="{{ old('language', $metadata->language ?? 'es') }}">
            </div>
        </div>

        <div class="format-field">
            <label for="book-description">Descripción</label>
            <textarea id="book-description" name="description" rows="4">{{ old('description', $metadata->description) }}</textarea>
        </div>

        <div class="field-row">
            <div class="format-field">
                <label for="book-edition">Edición</label>
                <input id="book-edition" name="edition" type="text" maxlength="120" value="{{ old('edition', $metadata->edition) }}">
            </div>
            <div class="format-field">
                <label for="book-publisher">Editorial</label>
                <input id="book-publisher" name="publisher" type="text" maxlength="200" value="{{ old('publisher', $metadata->publisher) }}">
            </div>
            <div class="format-field">
                <label for="book-isbn">ISBN</label>
                <input id="book-isbn" name="isbn" type="text" maxlength="32" value="{{ old('isbn', $metadata->isbn) }}">
            </div>
        </div>

        <div class="format-field">
            <label for="book-rights">Derechos</label>
            <textarea id="book-rights" name="rights" rows="2" maxlength="500">{{ old('rights', $metadata->rights) }}</textarea>
        </div>

        <button class="button button-primary" type="submit">Guardar metadatos</button>
    </form>
@endsection
