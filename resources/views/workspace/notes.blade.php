@extends('layouts.workspace')

@section('title', $mode === 'pendings' ? 'Pendientes de '.$novel->title : ($mode === 'universe' ? 'Notas de '.$universe->name : 'Notas personales'))
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            @if ($mode === 'pendings')
                <p class="kicker">Novela</p>
                <h1>Pendientes</h1>
                <p class="muted">{{ $novel->title }} · notas sin resolver vinculadas a esta novela.</p>
            @elseif ($mode === 'universe')
                <p class="kicker">Universo</p>
                <h1>Notas del universo</h1>
                <p class="muted">{{ $universe->name }} · solo tú ves estas notas.</p>
            @else
                <p class="kicker">Escritura</p>
                <h1>Notas</h1>
                <p class="muted">Tus notas personales. Solo tú puedes verlas.</p>
            @endif
        </div>
        <div class="library-actions">
            @if ($mode === 'universe')
                <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/cards">Volver a fichas</a>
                <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/bibliography">Bibliografía</a>
            @elseif ($mode === 'pendings')
                <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/planning">Planificación</a>
            @endif
            <a class="button button-secondary" href="/workspace">Volver a la biblioteca</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    @if ($mode === 'universe')
        <section class="universe-card" aria-labelledby="new-note-title">
            <h2 id="new-note-title">Nueva nota</h2>
            <form method="POST" action="/workspace/universes/{{ $universe->id }}/notes?format=html" class="inline-form">
                @csrf
                <div class="field">
                    <label for="note-title">Título</label>
                    <input id="note-title" name="title" type="text" maxlength="200" placeholder="Investigación del faro">
                </div>
                <div class="field">
                    <label for="note-body">Contenido</label>
                    <textarea id="note-body" name="body" rows="4" placeholder="Escribe la nota..."></textarea>
                </div>
                <div class="field-row">
                    <label class="checkbox-field">
                        <input type="hidden" name="private" value="0">
                        <input type="checkbox" name="private" value="1" checked> Nota privada
                    </label>
                    <label class="checkbox-field">
                        <input type="checkbox" name="pending" value="1"> Marcar como pendiente
                    </label>
                </div>
                <button class="button button-primary" type="submit">Añadir nota</button>
            </form>
        </section>
    @endif

    <section class="library-shelf" aria-labelledby="notes-title">
        <div class="library-shelf-heading">
            <h2 id="notes-title">{{ $mode === 'pendings' ? 'Notas pendientes' : 'Tus notas' }}</h2>
        </div>

        @if ($notes->isEmpty())
            <p class="muted">
                @if ($mode === 'pendings')
                    No hay pendientes abiertos para esta novela.
                @else
                    Todavía no hay notas. Añade la primera para conservar tus ideas.
                @endif
            </p>
        @else
            <div class="universe-list">
                @foreach ($notes as $note)
                    <article class="universe-card">
                        <div class="shelf-card-heading">
                            <div>
                                <h3>{{ $note->title ?: 'Sin título' }}</h3>
                                <p class="muted">
                                    {{ $note->private ? 'Privada' : 'Sin marcar como privada' }}
                                    · {{ $note->pending ? 'Pendiente' : 'Resuelta' }}
                                </p>
                            </div>
                        </div>

                        @if ($note->body)
                            <p>{!! nl2br(e($note->body)) !!}</p>
                        @endif

                        @if ($note->links->isNotEmpty())
                            <p class="muted">
                                Enlaces:
                                @foreach ($note->links as $link)
                                    {{ $link->linkable_type }} #{{ $link->linkable_id }} ({{ $link->anchor ?: 'sin ancla' }})
                                    @if (! $loop->last)
                                        ·
                                    @endif
                                @endforeach
                            </p>
                        @endif

                        <form method="POST" action="/workspace/notes/{{ $note->id }}?format=html" class="inline-form">
                            @csrf
                            @method('PATCH')
                            <div class="field">
                                <label for="note-edit-title-{{ $note->id }}">Título</label>
                                <input id="note-edit-title-{{ $note->id }}" name="title" type="text" maxlength="200" value="{{ $note->title }}">
                            </div>
                            <div class="field">
                                <label for="note-edit-body-{{ $note->id }}">Contenido</label>
                                <textarea id="note-edit-body-{{ $note->id }}" name="body" rows="3">{{ $note->body }}</textarea>
                            </div>
                            <label class="checkbox-field">
                                <input type="hidden" name="private" value="0">
                                <input type="checkbox" name="private" value="1" @checked($note->private)> Nota privada
                            </label>
                            <button class="button button-secondary" type="submit">Guardar cambios</button>
                        </form>

                        <form method="POST" action="/workspace/notes/{{ $note->id }}/pending?format=html" class="inline-form">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="pending" value="{{ $note->pending ? '0' : '1' }}">
                            <button class="button button-secondary" type="submit">
                                {{ $note->pending ? 'Quitar pendiente' : 'Marcar pendiente' }}
                            </button>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
