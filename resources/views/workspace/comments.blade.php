@extends('layouts.workspace')

@section('title', 'Revisión de '.$novel->title)
@section('main_class', 'site-main library-main')

@section('content')
    @php($statusLabels = ['open' => 'Abierto', 'needs_review' => 'Revisar anclaje', 'resolved' => 'Resuelto'])
    @php($anchorLabels = ['anchored' => 'Anclado', 'needs_review' => 'Anclaje dudoso', 'unanchored' => 'Sin anclaje'])

    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Revisar</p>
            <h1>Comentarios</h1>
            <p class="muted">{{ $novel->title }}. Conversaciones ancladas al texto autorizado.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}">Volver a escribir</a>
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/snapshots">Versiones</a>
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    @if ($comments === [])
        <section class="empty-state">
            <h2>Sin comentarios</h2>
            <p>Las conversaciones de esta novela aparecerán aquí.</p>
        </section>
    @else
        <ul class="universe-list">
            @foreach ($comments as $comment)
                <li class="universe-card">
                    <div class="universe-heading">
                        <h3>{{ $comment['author']['name'] ?? 'Comentario' }}</h3>
                        <span class="muted">
                            {{ $statusLabels[$comment['status']] ?? $comment['status'] }} ·
                            {{ $anchorLabels[$comment['anchor_status']] ?? $comment['anchor_status'] }}
                        </span>
                    </div>

                    @if (! empty($comment['quoted_text']))
                        <blockquote class="comment-quote">{{ $comment['quoted_text'] }}</blockquote>
                    @endif

                    <ul class="novel-list comment-messages">
                        @foreach ($comment['messages'] as $message)
                            <li>
                                <p><strong>{{ $message['author']['name'] ?? 'Cuenta' }}</strong>: {{ $message['body'] }}</p>
                            </li>
                        @endforeach
                    </ul>

                    <form method="POST" action="/workspace/comments/{{ $comment['id'] }}/messages" class="inline-form">
                        @csrf
                        <label for="reply-{{ $comment['id'] }}">Responder</label>
                        <div class="field-row">
                            <input id="reply-{{ $comment['id'] }}" name="body" type="text" required maxlength="5000" placeholder="Escribe una respuesta">
                            <button class="button button-secondary" type="submit">Responder</button>
                        </div>
                    </form>

                    @if (! empty($comment['corrections']))
                        <h4>Correcciones</h4>
                        <ul class="novel-list">
                            @foreach ($comment['corrections'] as $correction)
                                <li>
                                    <p class="muted">
                                        {{ $correction['status'] === 'pending' ? 'Pendiente' : ($correction['status'] === 'accepted' ? 'Aceptada' : ($correction['status'] === 'rejected' ? 'Rechazada' : 'Obsoleta')) }}
                                    </p>
                                    <div class="comparison-grid">
                                        <section>
                                            <h3>Original</h3>
                                            <pre>{{ $correction['original'] }}</pre>
                                        </section>
                                        <section>
                                            <h3>Propuesta</h3>
                                            <pre>{{ $correction['proposed'] }}</pre>
                                        </section>
                                    </div>

                                    @if ($correction['status'] === 'pending')
                                        <form method="POST" action="/workspace/corrections/{{ $correction['id'] }}/accept?format=html" class="inline-form" data-accept-correction data-chapter-id="{{ $comment['chapter_id'] }}">
                                            @csrf
                                            <p class="muted" data-correction-error role="alert"></p>
                                            <button class="button button-secondary" type="submit">Aceptar corrección</button>
                                        </form>
                                        <form method="POST" action="/workspace/corrections/{{ $correction['id'] }}/reject?format=html" class="inline-form">
                                            @csrf
                                            <button class="button button-secondary" type="submit">Rechazar corrección</button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($isOwner)
                        <form method="POST" action="/workspace/comments/{{ $comment['id'] }}" class="inline-form">
                            @csrf
                            @method('PATCH')
                            <label for="comment-status-{{ $comment['id'] }}">Estado</label>
                            <div class="field-row">
                                <select id="comment-status-{{ $comment['id'] }}" name="status">
                                    @foreach ($statusLabels as $value => $label)
                                        <option value="{{ $value }}" @selected($comment['status'] === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button class="button button-secondary" type="submit">Guardar estado</button>
                            </div>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($nextCursor)
            <nav class="editor-more" aria-label="Más comentarios">
                <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/comments?cursor={{ urlencode($nextCursor) }}">Cargar más comentarios</a>
            </nav>
        @endif
    @endif
@endsection
