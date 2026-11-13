@extends('layouts.workspace')

@section('title', 'Papelera')
@section('main_class', 'site-main library-main')

@section('content')
    <header class="library-hero">
        <div class="library-intro">
            <p class="kicker">Biblioteca</p>
            <h1>Papelera</h1>
            <p class="muted">Restaura lo que necesites. El borrado definitivo exige escribir el nombre exacto y no se puede deshacer.</p>
        </div>
        <div class="library-hero-actions">
            <a class="button button-secondary" href="/workspace">Volver a la biblioteca</a>
        </div>
    </header>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    @if ($entries === [])
        <section class="empty-state">
            <h2>La papelera está vacía</h2>
            <p>No hay elementos pendientes de restaurar o eliminar.</p>
        </section>
    @else
        <ul class="universe-list">
            @foreach ($entries as $entry)
                <li class="universe-card">
                    <div class="universe-heading">
                        <h2>{{ $entry['resource_name'] ?: 'Elemento '.$entry['resource_id'] }}</h2>
                        <span class="muted">{{ ucfirst($entry['resource_type']) }}</span>
                    </div>
                    <p class="muted">
                        Eliminado: {{ $entry['deleted_at'] }} · Límite: {{ $entry['restore_deadline'] }}
                        @if ($entry['expired']) · Caducado @endif
                    </p>

                    @if ($entry['resource_exists'])
                        <form method="POST" action="/workspace/trash/{{ $entry['id'] }}/restore" class="inline-form">
                            @csrf
                            <button class="button button-secondary" type="submit">Restaurar</button>
                        </form>

                        <button type="button" class="button button-small button-danger-quiet" data-dialog-open="dialog-delete-trash-{{ $loop->index }}">Eliminar definitivamente</button>
                    @else
                        <p class="muted">El recurso ya no existe; puedes retirar la entrada.</p>
                        <form method="POST" action="/workspace/trash/{{ $entry['id'] }}" class="inline-form">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="confirmation" value="{{ $entry['resource_name'] ?: $entry['resource_id'] }}">
                            <button class="button button-secondary" type="submit">Retirar entrada</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>

        @foreach ($entries as $entry)
            @if ($entry['resource_exists'])
                <x-dialog id="dialog-delete-trash-{{ $loop->index }}" title="Eliminar definitivamente" description="Esta acción no se puede deshacer." danger>
                    <form method="POST" action="/workspace/trash/{{ $entry['id'] }}">
                        @csrf
                        @method('DELETE')
                        <div class="field">
                            <label for="confirmation-{{ $entry['id'] }}">Escribe «{{ $entry['resource_name'] ?: $entry['resource_id'] }}» para confirmar</label>
                            <input id="confirmation-{{ $entry['id'] }}" name="confirmation" type="text" required @if (old('confirmation')) value="{{ old('confirmation') }}" @endif>
                        </div>
                        <div class="app-dialog-actions">
                            <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                            <button type="submit" class="button button-danger">Eliminar definitivamente</button>
                        </div>
                    </form>
                </x-dialog>
            @endif
        @endforeach
    @endif
@endsection
