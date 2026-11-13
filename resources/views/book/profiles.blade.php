@extends('layouts.workspace')

@section('title', 'Perfiles editoriales de '.$novel->title)
@section('main_class', 'site-main book-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Libro</p>
            <h1>Perfiles editoriales</h1>
            <p class="muted">{{ $novel->title }}. Define el tamaño de página, la orientación y los márgenes que usarán la previsualización y la exportación.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/book">Volver al libro</a>
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <section class="admin-card">
        <h2>Nuevo perfil</h2>
        <form method="POST" action="/workspace/novels/{{ $novel->id }}/book/profiles" class="inline-form">
            @csrf
            <div class="format-field">
                <label for="new-profile-name">Nombre</label>
                <input id="new-profile-name" name="name" type="text" required maxlength="120" placeholder="Por ejemplo, Rústica 148x210">
            </div>
            <div class="field-row">
                <div class="format-field">
                    <label for="new-profile-size">Tamaño de página</label>
                    <input id="new-profile-size" name="page_size" type="text" maxlength="60" placeholder="148x210mm">
                </div>
                <div class="format-field">
                    <label for="new-profile-orientation">Orientación</label>
                    <select id="new-profile-orientation" name="orientation">
                        <option value="portrait">Vertical</option>
                        <option value="landscape">Horizontal</option>
                    </select>
                </div>
            </div>
            <fieldset>
                <legend>Márgenes en milímetros</legend>
                <div class="field-row">
                    <div class="format-field">
                        <label for="new-margin-top">Superior</label>
                        <input id="new-margin-top" name="margins[top]" type="number" min="0" step="1" value="18">
                    </div>
                    <div class="format-field">
                        <label for="new-margin-bottom">Inferior</label>
                        <input id="new-margin-bottom" name="margins[bottom]" type="number" min="0" step="1" value="22">
                    </div>
                    <div class="format-field">
                        <label for="new-margin-left">Izquierdo</label>
                        <input id="new-margin-left" name="margins[left]" type="number" min="0" step="1" value="16">
                    </div>
                    <div class="format-field">
                        <label for="new-margin-right">Derecho</label>
                        <input id="new-margin-right" name="margins[right]" type="number" min="0" step="1" value="16">
                    </div>
                </div>
            </fieldset>
            <button class="button button-primary" type="submit">Crear perfil</button>
        </form>
    </section>

    @if ($profiles->isEmpty())
        <section class="empty-state">
            <h2>Todavía no hay perfiles</h2>
            <p>Crea un perfil para fijar las medidas del libro.</p>
        </section>
    @else
        <ul class="universe-list">
            @foreach ($profiles as $profile)
                <li class="universe-card">
                    <div class="universe-heading">
                        <h3>{{ $profile->name }}</h3>
                        <span class="muted">{{ $profile->page_size }} · {{ $profile->orientation === 'landscape' ? 'Horizontal' : 'Vertical' }}</span>
                    </div>

                    <form method="POST" action="/workspace/book/profiles/{{ $profile->id }}" class="inline-form">
                        @csrf
                        @method('PATCH')
                        <div class="format-field">
                            <label for="profile-name-{{ $profile->id }}">Nombre</label>
                            <input id="profile-name-{{ $profile->id }}" name="name" type="text" required maxlength="120" value="{{ $profile->name }}">
                        </div>
                        <div class="field-row">
                            <div class="format-field">
                                <label for="profile-size-{{ $profile->id }}">Tamaño de página</label>
                                <input id="profile-size-{{ $profile->id }}" name="page_size" type="text" maxlength="60" value="{{ $profile->page_size }}">
                            </div>
                            <div class="format-field">
                                <label for="profile-orientation-{{ $profile->id }}">Orientación</label>
                                <select id="profile-orientation-{{ $profile->id }}" name="orientation">
                                    <option value="portrait" @selected($profile->orientation !== 'landscape')>Vertical</option>
                                    <option value="landscape" @selected($profile->orientation === 'landscape')>Horizontal</option>
                                </select>
                            </div>
                        </div>
                        <fieldset>
                            <legend>Márgenes en milímetros</legend>
                            <div class="field-row">
                                <div class="format-field">
                                    <label for="margin-top-{{ $profile->id }}">Superior</label>
                                    <input id="margin-top-{{ $profile->id }}" name="margins[top]" type="number" min="0" step="1" value="{{ $profile->margins['top'] ?? 18 }}">
                                </div>
                                <div class="format-field">
                                    <label for="margin-bottom-{{ $profile->id }}">Inferior</label>
                                    <input id="margin-bottom-{{ $profile->id }}" name="margins[bottom]" type="number" min="0" step="1" value="{{ $profile->margins['bottom'] ?? 22 }}">
                                </div>
                                <div class="format-field">
                                    <label for="margin-left-{{ $profile->id }}">Izquierdo</label>
                                    <input id="margin-left-{{ $profile->id }}" name="margins[left]" type="number" min="0" step="1" value="{{ $profile->margins['left'] ?? 16 }}">
                                </div>
                                <div class="format-field">
                                    <label for="margin-right-{{ $profile->id }}">Derecho</label>
                                    <input id="margin-right-{{ $profile->id }}" name="margins[right]" type="number" min="0" step="1" value="{{ $profile->margins['right'] ?? 16 }}">
                                </div>
                            </div>
                        </fieldset>
                        <button class="button button-secondary" type="submit">Guardar perfil</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
