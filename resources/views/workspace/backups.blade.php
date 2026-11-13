@extends('layouts.workspace')

@section('title', 'Copias de seguridad')
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Biblioteca</p>
            <h1>Copias de seguridad</h1>
            <p class="muted">Descarga un universo completo en un paquete portable o restaura una copia como un universo privado nuevo.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace">Volver a la biblioteca</a>
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <section class="admin-card">
        <h2>Exportar un universo</h2>
        @if ($universes->isEmpty())
            <p class="muted">Todavía no hay universos para exportar.</p>
        @else
            <ul class="universe-list">
                @foreach ($universes as $universe)
                    <li class="universe-card">
                        <div class="universe-heading">
                            <h3>{{ $universe->name }}</h3>
                            <span class="muted">{{ $universe->novels_count }} {{ $universe->novels_count === 1 ? 'novela' : 'novelas' }}</span>
                        </div>
                        <form method="POST" action="/workspace/backups/export" class="inline-form">
                            @csrf
                            <input type="hidden" name="universe_id" value="{{ $universe->id }}">
                            <button class="button button-secondary" type="submit">Descargar copia</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="admin-card">
        <h2>Restaurar una copia</h2>
        <p class="muted">La restauración crea un universo nuevo con identidades nuevas; no modifica el original.</p>
        <form method="POST" action="/workspace/backups/restore?format=html" enctype="multipart/form-data" class="inline-form">
            @csrf
            <div class="format-field">
                <label for="backup-file">Paquete ZIP</label>
                <input id="backup-file" name="file" type="file" accept=".zip,application/zip" required>
            </div>
            <div class="format-field">
                <label for="backup-name">Nombre del universo restaurado (opcional)</label>
                <input id="backup-name" name="name" type="text" maxlength="160">
            </div>
            <button class="button button-primary" type="submit">Restaurar copia</button>
        </form>
    </section>
@endsection
