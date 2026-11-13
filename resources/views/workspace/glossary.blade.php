@extends('layouts.workspace')

@section('title', 'Glosario de '.$universe->name)
@section('main_class', 'site-main library-main')

@section('content')
    @php($languageNames = $languages->pluck('name', 'id'))

    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Universo</p>
            <h1>Glosario</h1>
            <p class="muted">{{ $universe->name }} · términos propios, formas preferidas y variantes.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/cards">Volver a fichas</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="universe-card" aria-labelledby="new-term-title">
        <h2 id="new-term-title">Nuevo término</h2>
        <form method="POST" action="/workspace/universes/{{ $universe->id }}/glossary-terms?format=html" class="inline-form">
            @csrf
            <div class="field-row">
                <div class="field">
                    <label for="term">Término</label>
                    <input id="term" name="term" type="text" required maxlength="200" placeholder="valdarés">
                </div>
                <div class="field">
                    <label for="term-language">Lengua</label>
                    <select id="term-language" name="language_card_id">
                        <option value="">Sin lengua vinculada</option>
                        @foreach ($languages as $language)
                            <option value="{{ $language->id }}">{{ $language->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="term-preferred">Forma preferida</label>
                <input id="term-preferred" name="preferred_form" type="text" maxlength="200" placeholder="valdarés">
            </div>
            <div class="field">
                <label for="term-meaning">Significado</label>
                <textarea id="term-meaning" name="meaning" rows="3" placeholder="Lengua de Valdara"></textarea>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="term-variants">Variantes (separadas por comas o líneas)</label>
                    <textarea id="term-variants" name="variants_text" rows="2" placeholder="valdares, valdarés antiguo"></textarea>
                </div>
                <div class="field">
                    <label for="term-examples">Ejemplos (separados por comas o líneas)</label>
                    <textarea id="term-examples" name="examples_text" rows="2" placeholder="Sural valdarés."></textarea>
                </div>
            </div>
            <button class="button button-primary" type="submit">Añadir término</button>
        </form>
    </section>

    <section class="library-shelf" aria-labelledby="terms-title">
        <div class="library-shelf-heading">
            <h2 id="terms-title">Términos del glosario</h2>
        </div>

        @if ($terms->isEmpty())
            <p class="muted">Todavía no hay términos. Añade el primero para fijar formas y variantes del universo.</p>
        @else
            <div class="universe-list">
                @foreach ($terms as $term)
                    <article class="universe-card">
                        <div class="shelf-card-heading">
                            <div>
                                <h3>{{ $term->term }}</h3>
                                <p class="muted">
                                    @if ($term->language_card_id !== null)
                                        {{ $languageNames[$term->language_card_id] ?? 'Lengua eliminada' }}
                                    @else
                                        Sin lengua vinculada
                                    @endif
                                    @if ($term->preferred_form)
                                        · Forma preferida: {{ $term->preferred_form }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        @if ($term->meaning)
                            <p>{{ $term->meaning }}</p>
                        @endif
                        @if (! empty($term->variants))
                            <p class="muted">Variantes: {{ implode(', ', $term->variants) }}</p>
                        @endif
                        @if (! empty($term->examples))
                            <p class="muted">Ejemplos: {{ implode(' · ', $term->examples) }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
