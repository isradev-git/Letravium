@extends('layouts.workspace')

@section('title', 'Cronología de '.$universe->name)
@section('main_class', 'site-main library-main')

@section('content')
    @php
        $cardNames = $cards->pluck('name', 'id');
        $eventNames = $events->pluck('name', 'id');
        $relationLabels = [
            'before' => 'antes que',
            'after' => 'después de',
            'simultaneous' => 'a la vez que',
        ];
    @endphp

    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Universo</p>
            <h1>Cronología</h1>
            <p class="muted">{{ $universe->name }} · eventos, épocas y relaciones de orden.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/calendars">Calendarios</a>
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/cards">Volver a fichas</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="universe-card" aria-labelledby="new-event-title">
        <h2 id="new-event-title">Nuevo evento</h2>
        <form method="POST" action="/workspace/universes/{{ $universe->id }}/events?format=html" class="inline-form">
            @csrf
            <div class="field-row">
                <div class="field">
                    <label for="event-name">Nombre</label>
                    <input id="event-name" name="name" type="text" required maxlength="200" placeholder="Fundación de Arven">
                </div>
                <div class="field">
                    <label for="event-type">Tipo</label>
                    <select id="event-type" name="type">
                        <option value="event">Evento</option>
                        <option value="epoch">Época</option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="event-description">Descripción</label>
                <textarea id="event-description" name="description" rows="2"></textarea>
            </div>
            <div class="field">
                <label for="event-moment">Momento</label>
                <input id="event-moment" name="moment" type="text" maxlength="200" placeholder="Año 1">
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="event-participants">Participantes</label>
                    <select id="event-participants" name="participants[]" multiple>
                        @foreach ($cards as $card)
                            <option value="{{ $card->id }}">{{ $card->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="event-places">Lugares</label>
                    <select id="event-places" name="places[]" multiple>
                        @foreach ($cards as $card)
                            <option value="{{ $card->id }}">{{ $card->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <button class="button button-primary" type="submit">Añadir evento</button>
        </form>
    </section>

    <section class="library-shelf" aria-labelledby="events-title">
        <div class="library-shelf-heading">
            <h2 id="events-title">Eventos</h2>
        </div>

        @if ($events->isEmpty())
            <p class="muted">Todavía no hay eventos. Añade el primero para ordenar la historia del universo.</p>
        @else
            <div class="universe-list">
                @foreach ($events as $event)
                    <article class="universe-card">
                        <div class="shelf-card-heading">
                            <div>
                                <h3>{{ $event->name }}</h3>
                                <p class="muted">
                                    {{ $event->type === 'epoch' ? 'Época' : 'Evento' }}
                                    @if ($event->moment)
                                        · {{ $event->moment }}
                                    @endif
                                </p>
                            </div>
                            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/events/{{ $event->id }}/compare">Comparar</a>
                        </div>

                        @if ($event->description)
                            <p>{{ $event->description }}</p>
                        @endif
                        @if (! empty($event->participants))
                            <p class="muted">Participantes: {{ collect($event->participants)->map(fn ($id) => $cardNames[(int) $id] ?? 'Ficha '.$id)->implode(', ') }}</p>
                        @endif
                        @if (! empty($event->places))
                            <p class="muted">Lugares: {{ collect($event->places)->map(fn ($id) => $cardNames[(int) $id] ?? 'Ficha '.$id)->implode(', ') }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="universe-card" aria-labelledby="new-relation-title">
        <h2 id="new-relation-title">Nueva relación de orden</h2>
        @if ($events->count() < 2)
            <p class="muted">Necesitas al menos dos eventos para relacionarlos.</p>
        @else
            <form method="POST" action="/workspace/universes/{{ $universe->id }}/event-relations?format=html" class="inline-form">
                @csrf
                <div class="field-row">
                    <div class="field">
                        <label for="relation-from">Evento</label>
                        <select id="relation-from" name="from_event_id" required>
                            @foreach ($events as $event)
                                <option value="{{ $event->id }}">{{ $event->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="relation-type">Relación</label>
                        <select id="relation-type" name="type">
                            <option value="before">antes que</option>
                            <option value="after">después de</option>
                            <option value="simultaneous">a la vez que</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="relation-to">Evento</label>
                        <select id="relation-to" name="to_event_id" required>
                            @foreach ($events as $event)
                                <option value="{{ $event->id }}">{{ $event->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <button class="button button-primary" type="submit">Añadir relación</button>
            </form>
        @endif
    </section>

    <section class="library-shelf" aria-labelledby="relations-title">
        <div class="library-shelf-heading">
            <h2 id="relations-title">Relaciones de orden</h2>
        </div>

        @if ($relations->isEmpty())
            <p class="muted">Sin relaciones registradas.</p>
        @else
            <ul class="novel-list">
                @foreach ($relations as $relation)
                    <li class="muted">
                        {{ $eventNames[$relation->from_event_id] ?? 'Evento '.$relation->from_event_id }}
                        {{ $relationLabels[$relation->relation] ?? $relation->relation }}
                        {{ $eventNames[$relation->to_event_id] ?? 'Evento '.$relation->to_event_id }}
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if (isset($comparedEvent))
        <section class="universe-card" aria-labelledby="compare-title">
            <h2 id="compare-title">Comparación</h2>
            <p class="muted">{{ $comparedEvent->name }} frente a otro evento.</p>

            <form method="GET" action="/workspace/universes/{{ $universe->id }}/events/{{ $comparedEvent->id }}/compare" class="inline-form">
                <div class="field-row">
                    <div class="field">
                        <label for="compare-event">Otro evento</label>
                        <select id="compare-event" name="other_event_id" required>
                            @foreach ($events as $event)
                                @if ($event->id !== $comparedEvent->id)
                                    <option value="{{ $event->id }}" @selected($otherEvent !== null && $otherEvent->id === $event->id)>{{ $event->name }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <button class="button button-secondary" type="submit">Comparar</button>
                </div>
            </form>

            @if ($otherEvent !== null)
                <p role="status">
                    {{ $comparedEvent->name }}
                    {{ $relationLabels[$comparison] ?? $comparison }}
                    {{ $otherEvent->name }}.
                </p>
            @endif
        </section>
    @endif
@endsection
