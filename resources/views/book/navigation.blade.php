@extends('layouts.workspace')

@section('title', 'Índices y navegación de '.$novel->title)
@section('main_class', 'site-main book-main')

@section('content')
    @php($markLabels = ['figure' => 'Figuras', 'table' => 'Tablas', 'index' => 'Índice', 'glossary' => 'Glosario'])

    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Libro</p>
            <h1>Índices y navegación</h1>
            <p class="muted">{{ $novel->title }}. Revisa la tabla de contenidos y las entradas que se resolverán al exportar.</p>
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
        <h2>Navegación</h2>
        @if (empty($navigation['toc']))
            <p class="muted">Todavía no hay capítulos que indexar.</p>
        @else
            <ol class="novel-list">
                @foreach ($navigation['toc'] as $entry)
                    <li>
                        <span class="novel-title">{{ $entry['title'] }}</span>
                        @if (! empty($entry['children']))
                            <ul class="chapter-list">
                                @foreach ($entry['children'] as $heading)
                                    <li><a href="#">{{ $heading['title'] }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    <section class="admin-card">
        <h2>Nueva marca</h2>
        <form method="POST" action="/workspace/novels/{{ $novel->id }}/book/marks" class="inline-form">
            @csrf
            <div class="field-row">
                <div class="format-field">
                    <label for="mark-type">Tipo</label>
                    <select id="mark-type" name="type" required>
                        @foreach ($markLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="format-field">
                    <label for="mark-label">Etiqueta</label>
                    <input id="mark-label" name="label" type="text" maxlength="255">
                </div>
            </div>
            <div class="format-field">
                <label for="mark-caption">Pie o descripción</label>
                <textarea id="mark-caption" name="caption" rows="2"></textarea>
            </div>
            <div class="field-row">
                <div class="format-field">
                    <label for="mark-target-type">Tipo de destino</label>
                    <input id="mark-target-type" name="target_type" type="text" maxlength="32" placeholder="chapter / scene">
                </div>
                <div class="format-field">
                    <label for="mark-target-id">ID de destino</label>
                    <input id="mark-target-id" name="target_id" type="number" min="1" step="1">
                </div>
            </div>
            <button class="button button-primary" type="submit">Crear marca</button>
        </form>
    </section>

    @foreach (['figure' => 'figures', 'table' => 'tables', 'index' => 'index', 'glossary' => 'glossary'] as $type => $key)
        <section class="admin-card">
            <div class="admin-card-heading">
                <h2>{{ $markLabels[$type] }}</h2>
                <span class="muted">{{ count($navigation[$key]) }}</span>
            </div>

            @if (empty($navigation[$key]))
                <p class="muted">Sin entradas.</p>
            @else
                <ul class="universe-list">
                    @foreach ($navigation[$key] as $entry)
                        @php($mark = $marks->firstWhere('id', $entry['id']))
                        <li class="universe-card">
                            <div class="universe-heading">
                                <h3>{{ $entry['label'] ?: 'Sin etiqueta' }}</h3>
                                <span class="muted">{{ $entry['target_type'] ?: 'Sin destino' }}{{ $entry['target_id'] ? ' · '.$entry['target_id'] : '' }}</span>
                            </div>
                            @if ($entry['caption'])
                                <p class="muted">{{ $entry['caption'] }}</p>
                            @endif

                            @if ($mark)
                                <form method="POST" action="/workspace/book/marks/{{ $mark->id }}" class="inline-form">
                                    @csrf
                                    @method('PATCH')
                                    <div class="format-field">
                                        <label for="mark-label-{{ $mark->id }}">Etiqueta</label>
                                        <input id="mark-label-{{ $mark->id }}" name="label" type="text" maxlength="255" value="{{ $mark->label }}">
                                    </div>
                                    <div class="format-field">
                                        <label for="mark-caption-{{ $mark->id }}">Pie o descripción</label>
                                        <textarea id="mark-caption-{{ $mark->id }}" name="caption" rows="2">{{ $mark->caption }}</textarea>
                                    </div>
                                    <button class="button button-secondary" type="submit">Guardar marca</button>
                                </form>

                                <form method="POST" action="/workspace/book/marks/{{ $mark->id }}" class="inline-form">
                                    @csrf
                                    @method('DELETE')
                                    <button class="button button-secondary" type="submit">Eliminar marca</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
@endsection
