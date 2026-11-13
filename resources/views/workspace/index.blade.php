@extends('layouts.workspace')

@section('title', 'Biblioteca')
@section('body_class', 'workspace-page library-page')
@section('main_class', 'site-main library-main workspace-main')

@section('content')
    @php($openDialog = old('dialog'))
    <header class="library-hero">
        <div class="library-hero-copy">
            <p class="kicker">Estudio creativo</p>
            <h1>Biblioteca</h1>
            <p class="library-lede">Tus universos y novelas, más lo que otros comparten contigo.</p>
        </div>

        <div class="library-hero-actions">
            <button type="button" class="button button-primary" data-dialog-open="dialog-new-novel">Nueva novela</button>
            <button type="button" class="button button-secondary" data-dialog-open="dialog-new-collection">Nueva colección</button>
            <button type="button" class="button button-secondary" data-dialog-open="dialog-new-universe">Nuevo universo</button>
            <a class="button button-quiet" href="/workspace/imports">Importar manuscrito</a>
        </div>
    </header>

    <noscript>
        <section class="library-shelf" aria-labelledby="noscript-title">
            <div class="library-shelf-heading">
                <div>
                    <p class="kicker">Sin JavaScript</p>
                    <h2 id="noscript-title">Crear sin diálogos</h2>
                </div>
            </div>
            <form method="POST" action="/workspace/universes" class="inline-form">
                @csrf
                <label for="fallback-universe-name">Nuevo universo</label>
                <div class="field-row">
                    <input id="fallback-universe-name" name="name" type="text" required maxlength="160" placeholder="Nombre del universo">
                    <button class="button button-primary" type="submit">Crear universo</button>
                </div>
            </form>
            <form method="POST" action="/workspace/collections?format=html" class="inline-form">
                @csrf
                <label for="fallback-collection-name">Nueva colección</label>
                <div class="field-row">
                    <input id="fallback-collection-name" name="name" type="text" required maxlength="160" placeholder="Nombre de la colección">
                    <button class="button button-secondary" type="submit">Crear colección</button>
                </div>
            </form>
        </section>
    </noscript>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section id="colecciones" class="library-shelf" aria-labelledby="collections-title">
        <div class="library-shelf-heading">
            <div>
                <p class="kicker">Orden editorial</p>
                <h2 id="collections-title">Colecciones</h2>
            </div>
            <button type="button" class="text-action" data-dialog-open="dialog-new-collection">Crear colección</button>
        </div>

        @forelse ($collections as $collection)
            @php($inCollection = $collection->novels->pluck('id'))
            <article class="shelf-card collection-card">
                <div class="shelf-card-heading">
                    <div>
                        <h3>{{ $collection->name }}</h3>
                        <p class="shelf-meta">{{ $collection->novels->count() }} {{ $collection->novels->count() === 1 ? 'novela' : 'novelas' }}</p>
                    </div>
                    <div class="shelf-heading-actions">
                        <button type="button" class="text-action" data-dialog-open="dialog-rename-collection-{{ $collection->id }}">Renombrar</button>
                        <button type="button" class="text-action text-danger" data-dialog-open="dialog-delete-collection-{{ $collection->id }}">Eliminar</button>
                    </div>
                </div>

                @if ($collection->novels->isEmpty())
                    <p class="shelf-empty">Todavía no tiene novelas. Añade una desde cualquier universo.</p>
                @else
                    <ul class="novel-list">
                        @foreach ($collection->novels as $novel)
                            <li class="novel-item">
                                <a class="novel-link" href="/workspace/novels/{{ $novel->id }}">
                                    <span class="novel-title">{{ $novel->title }}</span>
                                    <span class="novel-action">Escribir</span>
                                </a>
                                <div class="novel-actions">
                                    @if (! $loop->first)
                                        <form method="POST" action="/workspace/collections/{{ $collection->id }}/novels/{{ $novel->id }}/move?format=html">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="direction" value="up">
                                            <button class="button button-small" type="submit">Subir</button>
                                        </form>
                                    @endif
                                    @if (! $loop->last)
                                        <form method="POST" action="/workspace/collections/{{ $collection->id }}/novels/{{ $novel->id }}/move?format=html">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="direction" value="down">
                                            <button class="button button-small" type="submit">Bajar</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="/workspace/collections/{{ $collection->id }}/novels/{{ $novel->id }}?format=html">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button button-small" type="submit">Retirar</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($activeNovels->whereNotIn('id', $inCollection)->isNotEmpty())
                    <div class="shelf-add-row">
                        @foreach ($activeNovels->whereNotIn('id', $inCollection) as $novel)
                            <form method="POST" action="/workspace/collections/{{ $collection->id }}/novels/{{ $novel->id }}?format=html">
                                @csrf
                                <button class="button button-small" type="submit">Añadir «{{ $novel->title }}»</button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <p class="shelf-empty">Aún no hay colecciones. Crea una para ordenar novelas sin duplicarlas.</p>
        @endforelse
    </section>

    <section id="universos" class="library-shelf" aria-labelledby="universes-title">
        <div class="library-shelf-heading">
            <div>
                <p class="kicker">Tu obra</p>
                <h2 id="universes-title">Universos</h2>
            </div>
            <button type="button" class="text-action" data-dialog-open="dialog-new-universe">Crear universo</button>
        </div>

        @if ($universes->isEmpty())
            <div class="empty-state" aria-live="polite">
                <h2>Todavía no hay universos</h2>
                <p>Crea un universo y después una novela para empezar a escribir.</p>
                <button type="button" class="button button-primary" data-dialog-open="dialog-new-universe">Crear universo</button>
            </div>
        @else
            <ul class="universe-list">
                @foreach ($universes as $universe)
                    <li class="universe-card">
                        <div class="shelf-card-heading">
                            <div>
                                <h3>{{ $universe->name }}</h3>
                                <p class="shelf-meta">{{ $universe->novels->count() }} {{ $universe->novels->count() === 1 ? 'novela' : 'novelas' }}</p>
                            </div>
                            <div class="shelf-heading-actions">
                                <a class="text-action" href="/workspace/universes/{{ $universe->id }}/cards">Fichas</a>
                                <a class="text-action" href="/workspace/universes/{{ $universe->id }}/documents">Documentos</a>
                                <button type="button" class="text-action" data-dialog-open="dialog-edit-universe-{{ $universe->id }}">Editar</button>
                                <button type="button" class="text-action" data-dialog-open="dialog-duplicate-universe-{{ $universe->id }}">Duplicar</button>
                            </div>
                        </div>

                        @if ($universe->novels->isEmpty())
                            <p class="shelf-empty">Sin novelas todavía.</p>
                        @else
                            <ul class="novel-list">
                                @foreach ($universe->novels as $novel)
                                    <li class="novel-item">
                                        <a class="novel-link" href="/workspace/novels/{{ $novel->id }}">
                                            <span class="novel-title">{{ $novel->title }}</span>
                                            <span class="novel-action">Escribir</span>
                                        </a>
                                        <div class="novel-actions">
                                            <button type="button" class="button button-small" data-dialog-open="dialog-rename-novel-{{ $novel->id }}">Renombrar</button>
                                            <button type="button" class="button button-small" data-dialog-open="dialog-duplicate-novel-{{ $novel->id }}">Duplicar</button>
                                            <button type="button" class="button button-small button-danger-quiet" data-dialog-open="dialog-archive-novel-{{ $novel->id }}">Archivar</button>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="shelf-add-row">
                            <button type="button" class="text-action" data-dialog-open="dialog-new-novel" data-dialog-universe="{{ $universe->id }}">+ Nueva novela en «{{ $universe->name }}»</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($archivedNovels->isNotEmpty())
        <section class="library-shelf" aria-labelledby="archived-title">
            <div class="library-shelf-heading">
                <div>
                    <p class="kicker">Fuera del trabajo habitual</p>
                    <h2 id="archived-title">Archivadas</h2>
                </div>
                <span class="shelf-meta">Conservan sus referencias.</span>
            </div>
            <ul class="novel-list">
                @foreach ($archivedNovels as $novel)
                    <li class="novel-item">
                        <div>
                            <span class="novel-title">{{ $novel->title }}</span>
                            <span class="shelf-meta"> · {{ $novel->universe?->name }}</span>
                        </div>
                        <div class="novel-actions">
                            <button type="button" class="button button-small" data-dialog-open="dialog-unarchive-novel-{{ $novel->id }}">Restaurar</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($shared->isNotEmpty())
        <section class="library-shelf" aria-labelledby="shared-title">
            <div class="library-shelf-heading">
                <div>
                    <p class="kicker">En colaboración</p>
                    <h2 id="shared-title">Compartidas contigo</h2>
                </div>
                <span class="shelf-meta">Solo verás los capítulos autorizados.</span>
            </div>
            <ul class="universe-list">
                @foreach ($shared as $entry)
                    <li class="universe-card">
                        <div class="shelf-card-heading">
                            <h3>{{ $entry['novel']->title }}</h3>
                        </div>
                        <ul class="chapter-list">
                            @foreach ($entry['chapters'] as $chapter)
                                <li>
                                    <a href="/workspace/novels/{{ $entry['novel']->id }}?view=chapter&chapter={{ $chapter->id }}">
                                        {{ $chapter->title ?: 'Capítulo sin título' }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Dialogs --}}
    <x-dialog id="dialog-new-universe" title="Nuevo universo" description="Un contenedor para las novelas, fichas y documentación de una misma historia." :open="$openDialog === 'new-universe'">
        <form method="POST" action="/workspace/universes">
            @csrf
            <input type="hidden" name="dialog" value="new-universe">
            <div class="field">
                <label for="universe-name">Nombre del universo</label>
                <input id="universe-name" name="name" type="text" required maxlength="160" placeholder="Nombre del universo" value="{{ old('name') }}" @if ($openDialog === 'new-universe') autofocus @endif>
                @if ($openDialog === 'new-universe' && $errors->has('name'))
                    <p data-dialog-error>{{ $errors->first('name') }}</p>
                @endif
            </div>
            <div class="app-dialog-actions">
                <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                <button type="submit" class="button button-primary">Crear universo</button>
            </div>
        </form>
    </x-dialog>

    <x-dialog id="dialog-new-novel" title="Nueva novela" description="Empieza con un título. El resto puede esperar." :open="$openDialog === 'new-novel'">
        @if ($universes->isEmpty())
            <p class="shelf-empty">Primero crea un universo. Una novela siempre pertenece a uno.</p>
            <div class="app-dialog-actions">
                <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                <button type="button" class="button button-primary" data-dialog-open="dialog-new-universe">Crear universo</button>
            </div>
        @else
            <form method="POST" action="/workspace/universes/{{ $universes->first()->id }}/novels" data-novel-form>
                @csrf
                <input type="hidden" name="dialog" value="new-novel">
                <div class="field">
                    <label for="novel-universe">Universo</label>
                    <select id="novel-universe" name="universe_id" data-novel-universe>
                        @foreach ($universes as $universe)
                            <option value="{{ $universe->id }}" @selected((int) old('universe_id') === (int) $universe->id)>{{ $universe->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="novel-title">Título</label>
                    <input id="novel-title" name="title" type="text" required maxlength="200" placeholder="Título de la novela" value="{{ old('title') }}" @if ($openDialog === 'new-novel') autofocus @endif>
                    @if ($openDialog === 'new-novel' && $errors->has('title'))
                        <p data-dialog-error>{{ $errors->first('title') }}</p>
                    @endif
                </div>
                <div class="app-dialog-actions">
                    <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                    <button type="submit" class="button button-primary">Crear novela</button>
                </div>
            </form>
        @endif
    </x-dialog>

    <x-dialog id="dialog-new-collection" title="Nueva colección" description="Agrupa novelas sin duplicarlas." :open="$openDialog === 'new-collection'">
        <form method="POST" action="/workspace/collections?format=html">
            @csrf
            <input type="hidden" name="dialog" value="new-collection">
            <div class="field">
                <label for="collection-name">Nombre de la colección</label>
                <input id="collection-name" name="name" type="text" required maxlength="160" placeholder="Nombre de la colección" value="{{ old('name') }}" @if ($openDialog === 'new-collection') autofocus @endif>
                @if ($openDialog === 'new-collection' && $errors->has('name'))
                    <p data-dialog-error>{{ $errors->first('name') }}</p>
                @endif
            </div>
            <div class="app-dialog-actions">
                <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                <button type="submit" class="button button-primary">Crear colección</button>
            </div>
        </form>
    </x-dialog>

    @foreach ($collections as $collection)
        <x-dialog id="dialog-rename-collection-{{ $collection->id }}" title="Renombrar colección" :open="$openDialog === 'rename-collection-'.$collection->id">
            <form method="POST" action="/workspace/collections/{{ $collection->id }}?format=html">
                @csrf
                @method('PATCH')
                <input type="hidden" name="dialog" value="rename-collection-{{ $collection->id }}">
                <div class="field">
                    <label for="collection-name-{{ $collection->id }}">Nombre</label>
                    <input id="collection-name-{{ $collection->id }}" name="name" type="text" required maxlength="160" value="{{ old('name', $collection->name) }}" @if ($openDialog === 'rename-collection-'.$collection->id) autofocus @endif>
                </div>
                <div class="app-dialog-actions">
                    <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                    <button type="submit" class="button button-primary">Guardar nombre</button>
                </div>
            </form>
        </x-dialog>

        <x-dialog id="dialog-delete-collection-{{ $collection->id }}" title="Eliminar colección" description="Las novelas seguirán en la biblioteca." danger>
            <form method="POST" action="/workspace/collections/{{ $collection->id }}?format=html">
                @csrf
                @method('DELETE')
                <div class="app-dialog-actions">
                    <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                    <button type="submit" class="button button-danger">Eliminar colección</button>
                </div>
            </form>
        </x-dialog>
    @endforeach

    @foreach ($universes as $universe)
        <x-dialog id="dialog-edit-universe-{{ $universe->id }}" title="Editar universo" :open="$openDialog === 'edit-universe-'.$universe->id">
            <form method="POST" action="/workspace/universes/{{ $universe->id }}?format=html">
                @csrf
                @method('PATCH')
                <input type="hidden" name="dialog" value="edit-universe-{{ $universe->id }}">
                <div class="field">
                    <label for="universe-title-{{ $universe->id }}">Nombre del universo</label>
                    <input id="universe-title-{{ $universe->id }}" name="name" type="text" required maxlength="160" value="{{ old('name', $universe->name) }}" @if ($openDialog === 'edit-universe-'.$universe->id) autofocus @endif>
                </div>
                <div class="field">
                    <label for="universe-description-{{ $universe->id }}">Descripción</label>
                    <textarea id="universe-description-{{ $universe->id }}" name="description" rows="3">{{ old('description', $universe->description) }}</textarea>
                </div>
                <div class="app-dialog-actions">
                    <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                    <button type="submit" class="button button-primary">Guardar universo</button>
                </div>
            </form>
        </x-dialog>

        <x-dialog id="dialog-duplicate-universe-{{ $universe->id }}" title="Duplicar universo" description="Se creará una copia privada con sus novelas y referencias.">
            <form method="POST" action="/workspace/universes/{{ $universe->id }}/duplicate?format=html">
                @csrf
                <div class="app-dialog-actions">
                    <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                    <button type="submit" class="button button-primary">Duplicar universo</button>
                </div>
            </form>
        </x-dialog>

        @foreach ($universe->novels as $novel)
            <x-dialog id="dialog-rename-novel-{{ $novel->id }}" title="Renombrar novela" :open="$openDialog === 'rename-novel-'.$novel->id">
                <form method="POST" action="/workspace/novels/{{ $novel->id }}?format=html">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="dialog" value="rename-novel-{{ $novel->id }}">
                    <div class="field">
                        <label for="novel-title-{{ $novel->id }}">Título</label>
                        <input id="novel-title-{{ $novel->id }}" name="title" type="text" required maxlength="200" value="{{ old('title', $novel->title) }}" @if ($openDialog === 'rename-novel-'.$novel->id) autofocus @endif>
                    </div>
                    <div class="app-dialog-actions">
                        <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                        <button type="submit" class="button button-primary">Guardar título</button>
                    </div>
                </form>
            </x-dialog>

            <x-dialog id="dialog-duplicate-novel-{{ $novel->id }}" title="Duplicar novela" description="Se creará una copia privada en el mismo universo.">
                <form method="POST" action="/workspace/novels/{{ $novel->id }}/duplicate?format=html">
                    @csrf
                    <div class="app-dialog-actions">
                        <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                        <button type="submit" class="button button-primary">Duplicar novela</button>
                    </div>
                </form>
            </x-dialog>

            <x-dialog id="dialog-archive-novel-{{ $novel->id }}" title="Archivar novela" description="Saldrá del trabajo habitual, pero conservará sus referencias y podrás restaurarla." danger>
                <form method="POST" action="/workspace/novels/{{ $novel->id }}/archive?format=html">
                    @csrf
                    @method('PATCH')
                    <div class="app-dialog-actions">
                        <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                        <button type="submit" class="button button-danger">Archivar novela</button>
                    </div>
                </form>
            </x-dialog>
        @endforeach
    @endforeach

    @foreach ($archivedNovels as $novel)
        <x-dialog id="dialog-unarchive-novel-{{ $novel->id }}" title="Restaurar novela" description="Volverá a la lista habitual de su universo.">
            <form method="POST" action="/workspace/novels/{{ $novel->id }}/unarchive?format=html">
                @csrf
                @method('PATCH')
                <div class="app-dialog-actions">
                    <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                    <button type="submit" class="button button-primary">Restaurar novela</button>
                </div>
            </form>
        </x-dialog>
    @endforeach
@endsection
