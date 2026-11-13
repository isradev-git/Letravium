@extends('layouts.workspace')

@section('title', 'Campos personalizados de '.$universe->name)
@section('main_class', 'site-main library-main')

@section('content')
    @php
        $types = [
            'text' => 'Texto', 'long_text' => 'Texto largo', 'number' => 'Número',
            'date' => 'Fecha', 'boolean' => 'Sí/No', 'select' => 'Selección',
            'multiselect' => 'Selección múltiple', 'url' => 'URL',
        ];
    @endphp

    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Universo</p>
            <h1>Campos personalizados</h1>
            <p class="muted">{{ $universe->name }} · definiciones que se añaden a las fichas de todas las áreas.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/cards">Volver a fichas</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="universe-card" aria-labelledby="new-field-title">
        <h2 id="new-field-title">Nuevo campo</h2>
        <form method="POST" action="/workspace/universes/{{ $universe->id }}/card-fields?format=html" class="inline-form">
            @csrf
            <div class="field-row">
                <div class="field">
                    <label for="field-key">Clave</label>
                    <input id="field-key" name="key" type="text" required maxlength="100" pattern="[A-Za-z0-9_]+" placeholder="language">
                </div>
                <div class="field">
                    <label for="field-type">Tipo</label>
                    <select id="field-type" name="type">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="field-label">Etiqueta visible</label>
                <input id="field-label" name="label" type="text" required maxlength="200" placeholder="Idioma">
            </div>
            <div class="field">
                <label for="field-options">Opciones para selección (separadas por comas)</label>
                <input id="field-options" name="options_text" type="text" maxlength="1000" placeholder="Común, Antiguo, Ritual">
            </div>
            <label class="checkbox-field">
                <input type="checkbox" name="temporal" value="1">
                Es un campo temporal
            </label>
            <button class="button button-primary" type="submit">Crear campo</button>
        </form>
    </section>

    <section class="library-shelf" aria-labelledby="fields-title">
        <div class="library-shelf-heading">
            <h2 id="fields-title">Campos definidos</h2>
        </div>

        @if ($fields === [])
            <p class="muted">Todavía no hay campos personalizados. Crea uno para añadirlo a todas las fichas del universo.</p>
        @else
            <div class="universe-list">
                @foreach ($fields as $field)
                    <article class="universe-card">
                        <div class="shelf-card-heading">
                            <div>
                                <h3>{{ $field['label'] }}</h3>
                                <p class="shelf-meta">{{ $field['key'] }} · {{ $types[$field['type']] ?? $field['type'] }}@if ($field['temporal']) · temporal @endif</p>
                            </div>
                        </div>

                        @if (! empty($field['options']))
                            <p class="muted">Opciones: {{ implode(', ', $field['options']) }}</p>
                        @endif

                        <form method="POST" action="/workspace/card-fields/{{ $field['id'] }}?format=html" class="inline-form">
                            @csrf
                            @method('DELETE')
                            <label class="checkbox-field">
                                <input type="checkbox" name="confirm" value="1" required>
                                Confirmo que los valores e historial se conservarán
                            </label>
                            <button class="button button-danger" type="submit">Eliminar campo</button>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
