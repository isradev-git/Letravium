@extends('layouts.workspace')

@section('title', 'Fichas de '.$universe->name)
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Universo</p>
            <h1>Fichas del universo</h1>
            <p class="muted">{{ $universe->name }} · ocho áreas de trabajo en un mismo lugar.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/card-fields">Campos personalizados</a>
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/glossary-terms">Glosario</a>
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/image-maps">Mapas</a>
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/events">Cronología</a>
            <a class="button button-secondary" href="/workspace">Volver a la biblioteca</a>
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    <nav class="library-section-heading" aria-label="Áreas del universo">
        @foreach ($areas as $area)
            <a class="button {{ $selected === $area ? 'button-primary' : 'button-secondary' }}"
               href="/workspace/universes/{{ $universe->id }}/cards?area={{ $area }}">
                {{ $templates[$area]['label'] ?? $area }}
                <span aria-hidden="true">({{ $cards->where('area', $area)->count() }})</span>
            </a>
        @endforeach
    </nav>

    <form method="POST" action="/workspace/universes/{{ $universe->id }}/cards" class="inline-form">
        @csrf
        <label for="card-name">Nueva ficha</label>
        <div class="field-row">
            <input id="card-name" name="name" type="text" required maxlength="200" placeholder="Nombre de la ficha">
            <select name="area" aria-label="Área">
                @foreach ($areas as $area)
                    <option value="{{ $area }}" @selected($selected === $area)>{{ $templates[$area]['label'] ?? $area }}</option>
                @endforeach
            </select>
            <button class="button button-primary" type="submit">Crear ficha</button>
        </div>
    </form>

    <ul class="universe-list">
        @foreach ($areas as $area)
            @php($areaCards = $cards->where('area', $area))
            <li class="universe-card">
                <div class="universe-heading">
                    <h2>{{ $templates[$area]['label'] ?? $area }}</h2>
                    <span class="muted">{{ $areaCards->count() }} fichas</span>
                </div>

                @if ($areaCards->isEmpty())
                    <p class="muted">Sin fichas todavía.</p>
                @else
                    <ul class="novel-list">
                        @foreach ($areaCards as $card)
                            <li>
                                <a class="novel-link" href="/workspace/cards/{{ $card->id }}">
                                    <span class="novel-title">{{ $card->name }}</span>
                                    <span class="novel-action">{{ $card->template ?: 'Ficha' }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>
@endsection
