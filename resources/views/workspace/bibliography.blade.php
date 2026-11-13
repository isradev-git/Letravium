@extends('layouts.workspace')

@section('title', 'Bibliografía de '.$universe->name)
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Investigación</p>
            <h1>Bibliografía</h1>
            <p class="muted">{{ $universe->name }} · fuentes, referencias y notas de investigación.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/notes">Notas</a>
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/cards">Volver a fichas</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="universe-card" aria-labelledby="new-entry-title">
        <h2 id="new-entry-title">Nueva entrada</h2>
        <form method="POST" action="/workspace/universes/{{ $universe->id }}/bibliography?format=html" class="inline-form">
            @csrf
            <div class="field">
                <label for="entry-novel">Novela vinculada</label>
                <select id="entry-novel" name="novel_id">
                    <option value="">Todo el universo</option>
                    @foreach ($novels as $novel)
                        <option value="{{ $novel->id }}">{{ $novel->title }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="entry-author">Autoría</label>
                    <input id="entry-author" name="author" type="text" maxlength="200" placeholder="Autora">
                </div>
                <div class="field">
                    <label for="entry-date">Fecha</label>
                    <input id="entry-date" name="date" type="text" maxlength="100" placeholder="2020">
                </div>
            </div>
            <div class="field">
                <label for="entry-title">Título</label>
                <input id="entry-title" name="title" type="text" maxlength="300" required placeholder="Historia de Arven">
            </div>
            <div class="field">
                <label for="entry-publisher">Editorial o publicación</label>
                <input id="entry-publisher" name="publisher" type="text" maxlength="200" placeholder="Editorial">
            </div>
            <div class="field">
                <label for="entry-url">Enlace</label>
                <input id="entry-url" name="url" type="url" placeholder="https://example.com/fuente">
            </div>
            <div class="field">
                <label for="entry-observations">Observaciones</label>
                <textarea id="entry-observations" name="observations" rows="3" placeholder="Notas sobre esta fuente"></textarea>
            </div>
            <button class="button button-primary" type="submit">Añadir entrada</button>
        </form>
    </section>

    <section class="library-shelf" aria-labelledby="entries-title">
        <div class="library-shelf-heading">
            <h2 id="entries-title">Entradas de la bibliografía</h2>
        </div>

        @if ($entries->isEmpty())
            <p class="muted">Todavía no hay fuentes. Añade la primera para conservar las referencias del universo.</p>
        @else
            <div class="universe-list">
                @foreach ($entries as $entry)
                    <article class="universe-card">
                        <div class="shelf-card-heading">
                            <div>
                                <h3>{{ $entry->title }}</h3>
                                <p class="muted">
                                    @if ($entry->author)
                                        {{ $entry->author }}
                                    @endif
                                    @if ($entry->date)
                                        · {{ $entry->date }}
                                    @endif
                                    @if ($entry->publisher)
                                        · {{ $entry->publisher }}
                                    @endif
                                    · {{ $entry->selected ? 'Incluida en la exportación' : 'No incluida en la exportación' }}
                                </p>
                            </div>
                        </div>

                        @if ($entry->url)
                            <p><a href="{{ $entry->url }}" rel="noopener noreferrer">{{ $entry->url }}</a></p>
                        @endif
                        @if ($entry->observations)
                            <p>{{ $entry->observations }}</p>
                        @endif

                        <form method="POST" action="/workspace/bibliography/{{ $entry->id }}/selected?format=html" class="inline-form">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="selected" value="{{ $entry->selected ? '0' : '1' }}">
                            <button class="button button-secondary" type="submit">
                                {{ $entry->selected ? 'Excluir de la exportación' : 'Incluir en la exportación' }}
                            </button>
                        </form>

                        @if ($notes->isNotEmpty())
                            <details class="document-preview-wrap">
                                <summary>Adjuntar una nota</summary>
                                <div class="novel-actions">
                                    @foreach ($notes as $note)
                                        <form method="POST" action="/workspace/bibliography/{{ $entry->id }}/notes/{{ $note->id }}?format=html" class="inline-form">
                                            @csrf
                                            <button class="button button-secondary" type="submit" aria-label="Adjuntar la nota {{ $note->title ?: 'sin título' }}">
                                                {{ $note->title ?: 'Nota sin título' }}
                                            </button>
                                        </form>
                                    @endforeach
                                </div>
                            </details>
                        @endif

                        @if ($entry->notes->isNotEmpty())
                            <p class="muted">
                                Notas adjuntas:
                                @foreach ($entry->notes as $attached)
                                    {{ $attached->title ?: 'Nota sin título' }}@if (! $loop->last), @endif
                                @endforeach
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
