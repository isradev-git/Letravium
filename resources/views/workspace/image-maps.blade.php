@extends('layouts.workspace')

@section('title', 'Mapas de '.($novel?->title ?? $universe->name))
@section('main_class', 'site-main library-main')

@section('content')
    @php
        $placeNames = $places->pluck('name', 'id');
        $createPath = $novel
            ? '/workspace/novels/'.$novel->id.'/image-maps'
            : '/workspace/universes/'.$universe->id.'/image-maps';
    @endphp

    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">{{ $novel ? 'Novela' : 'Universo' }}</p>
            <h1>Mapas</h1>
            <p class="muted">{{ $novel?->title ?? $universe->name }} · imágenes con marcadores de lugares.</p>
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

    <section class="universe-card" aria-labelledby="new-map-title">
        <h2 id="new-map-title">Nuevo mapa</h2>
        <form method="POST" action="{{ $createPath }}?format=html" class="inline-form">
            @csrf
            <div class="field">
                <label for="map-image">Imagen</label>
                <select id="map-image" name="image_asset_id" required>
                    <option value="">Selecciona una imagen</option>
                    @foreach ($assets as $asset)
                        <option value="{{ $asset->id }}">{{ $asset->original_name }}</option>
                    @endforeach
                </select>
            </div>
            <fieldset>
                <legend>Primer marcador (opcional)</legend>
                <div class="field">
                    <label for="map-place">Lugar</label>
                    <select id="map-place" name="place_card_id">
                        <option value="">Sin marcador</option>
                        @foreach ($places as $place)
                            <option value="{{ $place->id }}">{{ $place->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="map-x">Coordenada X (0 a 1)</label>
                        <input id="map-x" name="x" type="number" step="0.0001" min="0" max="1" inputmode="decimal">
                    </div>
                    <div class="field">
                        <label for="map-y">Coordenada Y (0 a 1)</label>
                        <input id="map-y" name="y" type="number" step="0.0001" min="0" max="1" inputmode="decimal">
                    </div>
                </div>
                <div class="field">
                    <label for="map-label">Etiqueta del marcador</label>
                    <input id="map-label" name="label" type="text" maxlength="200">
                </div>
                <div class="field">
                    <label for="map-note">Nota del marcador</label>
                    <textarea id="map-note" name="note" rows="2"></textarea>
                </div>
            </fieldset>
            <button class="button button-primary" type="submit">Crear mapa</button>
        </form>
    </section>

    <section class="library-shelf" aria-labelledby="maps-title">
        <div class="library-shelf-heading">
            <h2 id="maps-title">Mapas del {{ $novel ? 'novela' : 'universo' }}</h2>
        </div>

        @if ($maps->isEmpty())
            <p class="muted">Todavía no hay mapas. Crea el primero a partir de una imagen propia.</p>
        @else
            <div class="universe-list">
                @foreach ($maps as $map)
                    @php($markers = $map->markers ?? [])
                    <article class="universe-card">
                        <div class="shelf-card-heading">
                            <div>
                                <h3>{{ $map->asset?->original_name ?? 'Imagen eliminada' }}</h3>
                                <p class="muted">{{ count($markers) }} {{ count($markers) === 1 ? 'marcador' : 'marcadores' }}</p>
                            </div>
                        </div>

                        @if ($map->asset)
                            <img src="/workspace/assets/{{ $map->asset->id }}/view"
                                 alt="Vista previa de {{ $map->asset->original_name }}"
                                 style="max-width: 100%; height: auto;">
                        @endif

                        @if ($markers !== [])
                            <ul class="novel-list">
                                @foreach ($markers as $marker)
                                    <li>
                                        <span class="novel-title">{{ $marker['label'] ?? ($placeNames[$marker['place_card_id']] ?? 'Lugar') }}</span>
                                        <span class="novel-action">X {{ $marker['x'] }} · Y {{ $marker['y'] }}</span>
                                        @if (! empty($marker['note']))
                                            <p class="muted">{{ $marker['note'] }}</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <form method="POST" action="/workspace/image-maps/{{ $map->id }}/markers?format=html" class="inline-form">
                            @csrf
                            <div class="field">
                                <label for="marker-place-{{ $map->id }}">Lugar del marcador</label>
                                <select id="marker-place-{{ $map->id }}" name="place_card_id" required>
                                    <option value="">Selecciona un lugar</option>
                                    @foreach ($places as $place)
                                        <option value="{{ $place->id }}">{{ $place->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field-row">
                                <div class="field">
                                    <label for="marker-x-{{ $map->id }}">Coordenada X (0 a 1)</label>
                                    <input id="marker-x-{{ $map->id }}" name="x" type="number" step="0.0001" min="0" max="1" inputmode="decimal" required>
                                </div>
                                <div class="field">
                                    <label for="marker-y-{{ $map->id }}">Coordenada Y (0 a 1)</label>
                                    <input id="marker-y-{{ $map->id }}" name="y" type="number" step="0.0001" min="0" max="1" inputmode="decimal" required>
                                </div>
                            </div>
                            <div class="field">
                                <label for="marker-label-{{ $map->id }}">Etiqueta del marcador</label>
                                <input id="marker-label-{{ $map->id }}" name="label" type="text" maxlength="200">
                            </div>
                            <div class="field">
                                <label for="marker-note-{{ $map->id }}">Nota del marcador</label>
                                <textarea id="marker-note-{{ $map->id }}" name="note" rows="2"></textarea>
                            </div>
                            <button class="button button-primary" type="submit">Añadir marcador</button>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
