@extends('layouts.workspace')

@section('title', 'Lectores beta de '.$snapshot->name)
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Revisión</p>
            <h1>Lectores beta</h1>
            <p class="muted">{{ $novel->title }} · {{ $snapshot->name }}. La versión queda fija; el lector solo verá este snapshot.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/snapshots">Volver al historial</a>
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <section class="admin-card">
        <h2>Invitar a un lector</h2>
        <p class="muted">Indica un correo o el identificador de una cuenta. La invitación caduca y puede permitir comentarios.</p>
        <form method="POST" action="/workspace/snapshots/{{ $snapshot->id }}/beta-readers" class="inline-form">
            @csrf
            <div class="field-row">
                <div class="format-field">
                    <label for="beta-email">Correo</label>
                    <input id="beta-email" name="email" type="email" maxlength="200" placeholder="lectora@example.com">
                </div>
                <div class="format-field">
                    <label for="beta-user">ID de cuenta</label>
                    <input id="beta-user" name="user_id" type="number" min="1" step="1" placeholder="Opcional">
                </div>
                <div class="format-field">
                    <label for="beta-expires">Caduca el</label>
                    <input id="beta-expires" name="expires_at" type="date">
                </div>
            </div>
            <input type="hidden" name="can_comment" value="0">
            <label><input type="checkbox" name="can_comment" value="1" checked> Puede comentar</label>

            <input type="hidden" name="all_chapters" value="0">
            <label><input type="checkbox" name="all_chapters" value="1" checked> Todos los capítulos</label>
            <fieldset>
                <legend>O elige capítulos concretos</legend>
                @foreach ($chapters as $chapter)
                    <label>
                        <input type="checkbox" name="scope[chapters][]" value="{{ $chapter->id }}">
                        {{ $chapter->title ?: 'Capítulo sin título' }}
                    </label>
                @endforeach
            </fieldset>

            <button class="button button-primary" type="submit">Invitar lector</button>
        </form>
    </section>

    @if ($readers->isEmpty())
        <section class="empty-state">
            <h2>Todavía no hay lectores</h2>
            <p>Invita a una persona para compartir este snapshot.</p>
        </section>
    @else
        <ul class="universe-list">
            @foreach ($readers as $reader)
                <li class="universe-card">
                    <div class="universe-heading">
                        <h3>{{ $reader->user->name ?? $reader->email ?? 'Cuenta '.$reader->user_id }}</h3>
                        <span class="muted">{{ $reader->can_comment ? 'Puede comentar' : 'Solo lectura' }}</span>
                    </div>
                    <p class="muted">
                        Caduca: {{ $reader->expires_at?->format('Y-m-d H:i') }}
                        @if (! $reader->isActive()) · Caducado @endif
                    </p>
                    <p class="muted">
                        Alcance:
                        @if ($reader->scope === null)
                            todos los capítulos
                        @else
                            @foreach (($reader->scope['chapters'] ?? []) as $chapterId)
                                {{ $chapters->firstWhere('id', (int) $chapterId)?->title ?? 'Capítulo '.$chapterId }}{{ ! $loop->last ? ', ' : '' }}
                            @endforeach
                        @endif
                    </p>
                    <p>
                        Enlace de lectura:
                        <a class="share-link" href="/workspace/beta/{{ $reader->token }}">/workspace/beta/{{ $reader->token }}</a>
                    </p>

                    <form method="POST" action="/workspace/beta-readers/{{ $reader->id }}" class="inline-form">
                        @csrf
                        @method('PATCH')
                        <div class="field-row">
                            <div class="format-field">
                                <label for="reader-expires-{{ $reader->id }}">Caduca el</label>
                                <input id="reader-expires-{{ $reader->id }}" name="expires_at" type="date" value="{{ $reader->expires_at?->format('Y-m-d') }}">
                            </div>
                            <div class="format-field">
                                <span class="quota-label">Permiso</span>
                                <input type="hidden" name="can_comment" value="0">
                                <label class="checkbox-field">
                                    <input id="reader-comments-{{ $reader->id }}" type="checkbox" name="can_comment" value="1" @checked($reader->can_comment)>
                                    Puede comentar
                                </label>
                            </div>
                        </div>
                        <input type="hidden" name="all_chapters" value="0">
                        <label><input type="checkbox" name="all_chapters" value="1" @checked($reader->scope === null)> Todos los capítulos</label>
                        <fieldset>
                            <legend>Capítulos concretos</legend>
                            @foreach ($chapters as $chapter)
                                <label>
                                    <input type="checkbox" name="scope[chapters][]" value="{{ $chapter->id }}" @checked($reader->scope === null || in_array((int) $chapter->id, array_map('intval', $reader->scope['chapters'] ?? []), true))>
                                    {{ $chapter->title ?: 'Capítulo sin título' }}
                                </label>
                            @endforeach
                        </fieldset>
                        <button class="button button-secondary" type="submit">Guardar acceso</button>
                    </form>

                    <form method="POST" action="/workspace/beta-readers/{{ $reader->id }}" class="inline-form">
                        @csrf
                        @method('DELETE')
                        <button class="button button-secondary" type="submit">Revocar acceso</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
