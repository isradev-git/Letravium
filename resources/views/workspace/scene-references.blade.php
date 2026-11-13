@extends('layouts.workspace')

@section('title', 'Referencias de '.($scene->title ?: 'la escena'))
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Escena</p>
            <h1>Referencias de texto</h1>
            <p class="muted">
                {{ $novel->title }} · {{ $chapter->title }} · {{ $scene->title ?: 'Escena sin título' }}
            </p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}">Volver a la novela</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="universe-card" aria-labelledby="new-reference-title">
        <h2 id="new-reference-title">Añadir referencia</h2>
        <form method="POST" action="/workspace/scenes/{{ $scene->id }}/references?format=html" class="inline-form">
            @csrf
            <div class="field">
                <label for="reference-card">Ficha del universo</label>
                <select id="reference-card" name="card_id" required>
                    <option value="">Selecciona una ficha</option>
                    @foreach ($cards as $card)
                        <option value="{{ $card->id }}">{{ $card->name }} ({{ $card->area }})</option>
                    @endforeach
                </select>
            </div>
            <button class="button button-primary" type="submit">Añadir referencia</button>
        </form>
    </section>

    <section class="library-shelf" aria-labelledby="references-title">
        <div class="library-shelf-heading">
            <h2 id="references-title">Fichas referenciadas</h2>
        </div>

        @if ($references->isEmpty())
            <p class="muted">Todavía no hay fichas referenciadas desde esta escena.</p>
        @else
            <ul class="novel-list">
                @foreach ($references as $reference)
                    @php($card = $referencedCards[$reference->card_id] ?? null)
                    <li>
                        @if ($card)
                            <a class="novel-link" href="/workspace/cards/{{ $card->id }}">
                                <span class="novel-title">{{ $card->name }}</span>
                                <span class="novel-action">{{ $card->area }}</span>
                            </a>
                        @else
                            <span class="muted">Ficha eliminada</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
