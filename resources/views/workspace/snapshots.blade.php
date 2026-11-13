@extends('layouts.workspace')

@section('title', 'Historial de '.$novel->title)
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Historial</p>
            <h1>Historial de versiones</h1>
            <p class="muted">{{ $novel->title }}. Las versiones guardadas son inmutables: puedes renombrarlas, pero no cambiar su contenido.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}">Volver a escribir</a>
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <section class="admin-card">
        <h2>Guardar una versión</h2>
        <p class="muted">Fija el estado actual del manuscrito completo con un nombre reconocible.</p>
        <form method="POST" action="/workspace/novels/{{ $novel->id }}/snapshots" class="inline-form">
            @csrf
            <label for="snapshot-name">Nombre de la versión</label>
            <div class="field-row">
                <input id="snapshot-name" name="name" type="text" required maxlength="200" placeholder="Por ejemplo, Antes de la revisión">
                <button class="button button-primary" type="submit">Guardar versión</button>
            </div>
        </form>
    </section>

    @if ($snapshots->isEmpty())
        <section class="empty-state">
            <h2>Todavía no hay versiones</h2>
            <p>Guarda una versión para conservar un estado completo del manuscrito.</p>
        </section>
    @else
        <ul class="universe-list">
            @foreach ($snapshots as $snapshot)
                <li class="universe-card">
                    <div class="universe-heading">
                        <h3>{{ $snapshot->name }}</h3>
                        <span class="muted">{{ $snapshot->created_at?->format('Y-m-d H:i') }}</span>
                    </div>

                    <p>
                        <a class="button button-secondary" href="/workspace/snapshots/{{ $snapshot->id }}/compare">Comparar</a>
                        <a class="button button-secondary" href="/workspace/snapshots/{{ $snapshot->id }}/beta-readers">Lectores beta</a>
                    </p>

                    <form method="POST" action="/workspace/snapshots/{{ $snapshot->id }}" class="inline-form">
                        @csrf
                        @method('PATCH')
                        <label for="snapshot-name-{{ $snapshot->id }}">Renombrar versión</label>
                        <div class="field-row">
                            <input id="snapshot-name-{{ $snapshot->id }}" name="name" type="text" required maxlength="200" value="{{ $snapshot->name }}">
                            <button class="button button-secondary" type="submit">Renombrar</button>
                        </div>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
