@extends('layouts.workspace')

@section('title', 'Comparar '.$snapshot->name)
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Versiones</p>
            <h1>Comparar versión</h1>
            <p class="muted">{{ $snapshot->name }} frente al manuscrito actual de {{ $novel->title }}. Solo se muestran los recursos con cambios.</p>
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

    @if ($changes === [])
        <section class="empty-state">
            <h2>Sin cambios</h2>
            <p>El manuscrito actual coincide con esta versión.</p>
        </section>
    @else
        <section class="admin-card">
            <div class="admin-card-heading">
                <h2>{{ count($changes) }} cambios</h2>
                <span class="muted">
                    Añadidos: {{ $counts['added'] ?? 0 }} · Modificados: {{ $counts['modified'] ?? 0 }} · Eliminados: {{ $counts['removed'] ?? 0 }}
                </span>
            </div>

            <ul class="universe-list">
                @foreach ($changes as $change)
                    <li class="universe-card">
                        <details>
                            <summary>
                                <span class="novel-title">{{ $change['title'] }}</span>
                                <span class="muted">{{ $change['resource_label'] }} #{{ $change['resource_id'] }} · {{ $change['status'] === 'added' ? 'Añadida' : ($change['status'] === 'removed' ? 'Eliminada' : 'Modificada') }}</span>
                            </summary>

                            @if (! empty($change['fields']))
                                <p class="muted">Campos: {{ implode(', ', $change['fields']) }}</p>
                            @endif

                            <div class="comparison-grid">
                                <section>
                                    <h3>Antes</h3>
                                    <pre>{{ $change['before'] }}</pre>
                                </section>
                                <section>
                                    <h3>Ahora</h3>
                                    <pre>{{ $change['after'] }}</pre>
                                </section>
                            </div>

                            @if ($change['status'] === 'modified' && in_array($change['resource_type'], ['part', 'work_state', 'chapter', 'chapter_alternative', 'scene', 'style'], true))
                                <form method="POST" action="/workspace/snapshots/{{ $snapshot->id }}/restore-fragment?format=html" class="inline-form">
                                    @csrf
                                    <input type="hidden" name="resource_type" value="{{ $change['resource_type'] }}">
                                    <input type="hidden" name="resource_id" value="{{ $change['resource_id'] }}">
                                    <input type="hidden" name="expected_revision" value="{{ $change['current_revision'] ?? 0 }}">
                                    <label>
                                        <input type="checkbox" name="confirm" value="1" required>
                                        Confirmo restaurar este fragmento desde la versión
                                    </label>
                                    <button class="button button-secondary" type="submit">Restaurar este fragmento</button>
                                </form>
                            @endif
                        </details>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
