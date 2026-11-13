@extends('layouts.workspace')

@section('title', 'Secciones de '.$novel->title)
@section('main_class', 'site-main book-main')

@section('content')
    @php($labels = [
        'title_page' => 'Portadilla', 'credits' => 'Créditos', 'dedication' => 'Dedicatoria',
        'prologue' => 'Prólogo', 'introduction' => 'Introducción', 'epilogue' => 'Epílogo',
        'acknowledgments' => 'Agradecimientos', 'bibliography' => 'Bibliografía',
        'glossary' => 'Glosario', 'about_author' => 'Sobre la autoría',
    ])

    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Libro</p>
            <h1>Secciones del libro</h1>
            <p class="muted">{{ $novel->title }}. Apertura y cierre editables; puedes excluir una sección de la exportación.</p>
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

    <section class="admin-card">
        <h2>Nueva sección</h2>
        <form method="POST" action="/workspace/novels/{{ $novel->id }}/book/sections" class="inline-form">
            @csrf
            <div class="field-row">
                <div class="format-field">
                    <label for="section-area">Área</label>
                    <select id="section-area" name="area">
                        <option value="front">Apertura</option>
                        <option value="back">Cierre</option>
                    </select>
                </div>
                <div class="format-field">
                    <label for="section-type">Tipo</label>
                    <select id="section-type" name="type" required>
                        <optgroup label="Apertura">
                            @foreach ($catalog['front'] as $type)
                                <option value="{{ $type }}">{{ $labels[$type] ?? $type }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="Cierre">
                            @foreach ($catalog['back'] as $type)
                                <option value="{{ $type }}">{{ $labels[$type] ?? $type }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                </div>
            </div>
            <div class="format-field">
                <label for="section-title">Título</label>
                <input id="section-title" name="title" type="text" maxlength="255">
            </div>
            <div class="format-field">
                <label for="section-content">Contenido</label>
                <textarea id="section-content" name="content" rows="4"></textarea>
            </div>
            <input type="hidden" name="include_in_export" value="0">
            <label><input type="checkbox" name="include_in_export" value="1" checked> Incluir en la exportación</label>
            <button class="button button-primary" type="submit">Crear sección</button>
        </form>
    </section>

    @if ($sections->isEmpty())
        <section class="empty-state">
            <h2>Todavía no hay secciones</h2>
            <p>Crea una dedicatoria, un prólogo o un epílogo para completar el libro.</p>
        </section>
    @else
        <ul class="universe-list">
            @foreach ($sections as $section)
                <li class="universe-card">
                    <div class="universe-heading">
                        <h3>{{ $section->title ?: ($labels[$section->type] ?? $section->type) }}</h3>
                        <span class="muted">{{ $section->area === 'front' ? 'Apertura' : 'Cierre' }} · {{ $labels[$section->type] ?? $section->type }}</span>
                    </div>

                    <form method="POST" action="/workspace/book/sections/{{ $section->id }}" class="inline-form">
                        @csrf
                        @method('PATCH')
                        <div class="format-field">
                            <label for="section-title-{{ $section->id }}">Título</label>
                            <input id="section-title-{{ $section->id }}" name="title" type="text" maxlength="255" value="{{ $section->title }}">
                        </div>
                        <div class="format-field">
                            <label for="section-content-{{ $section->id }}">Contenido</label>
                            <textarea id="section-content-{{ $section->id }}" name="content" rows="4">{{ $section->content }}</textarea>
                        </div>
                        <input type="hidden" name="include_in_export" value="0">
                        <label><input type="checkbox" name="include_in_export" value="1" @checked($section->include_in_export)> Incluir en la exportación</label>
                        <button class="button button-secondary" type="submit">Guardar sección</button>
                    </form>

                    <form method="POST" action="/workspace/book/sections/{{ $section->id }}" class="inline-form">
                        @csrf
                        @method('DELETE')
                        <button class="button button-secondary" type="submit">Eliminar sección</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
