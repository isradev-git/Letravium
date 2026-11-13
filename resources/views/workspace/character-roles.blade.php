@extends('layouts.workspace')

@section('title', 'Roles de personajes de '.$novel->title)
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Novela</p>
            <h1>Roles de personajes</h1>
            <p class="muted">{{ $novel->title }} · {{ $universe->name }} · papel, objetivos y arco de cada personaje en esta novela.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/planning">Volver a la planificación</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="library-shelf" aria-labelledby="roles-title">
        <div class="library-shelf-heading">
            <h2 id="roles-title">Personajes de la novela</h2>
        </div>

        @if ($characters->isEmpty())
            <p class="muted">Todavía no hay personajes en este universo.</p>
        @else
            <div class="universe-list">
                @foreach ($characters as $character)
                    @php($role = $rolesByCard->get($character->id))
                    <article class="universe-card">
                        <div class="shelf-card-heading">
                            <div>
                                <h3>{{ $character->name }}</h3>
                                <p class="shelf-meta">{{ $role ? 'Rol definido' : 'Sin rol asignado' }}</p>
                            </div>
                        </div>

                        <form method="POST" action="/workspace/novels/{{ $novel->id }}/cards/{{ $character->id }}/role?format=html" class="inline-form">
                            @csrf
                            @method('PUT')

                            <div class="field">
                                <label for="role-{{ $character->id }}-role">Papel</label>
                                <input id="role-{{ $character->id }}-role" name="role" type="text" maxlength="120" value="{{ $role->role ?? '' }}" placeholder="protagonista, antagonista, secundaria…">
                            </div>
                            <div class="field">
                                <label for="role-{{ $character->id }}-goals">Objetivos</label>
                                <textarea id="role-{{ $character->id }}-goals" name="goals" rows="3">{{ $role->goals ?? '' }}</textarea>
                            </div>
                            <div class="field">
                                <label for="role-{{ $character->id }}-arc">Arco</label>
                                <textarea id="role-{{ $character->id }}-arc" name="arc" rows="3">{{ $role->arc ?? '' }}</textarea>
                            </div>

                            <button class="button button-primary" type="submit">Guardar rol</button>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
