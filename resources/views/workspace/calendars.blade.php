@extends('layouts.workspace')

@section('title', 'Calendarios de '.$universe->name)
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Universo</p>
            <h1>Calendarios</h1>
            <p class="muted">{{ $universe->name }} · calendarios, equivalencias, tiempo de historia y avisos de continuidad.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/events">Cronología</a>
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/continuity-warnings">Avisos de continuidad</a>
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/cards">Volver a fichas</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if (session('conversion_result'))
        <p class="form-notice" role="status">Conversión: {{ session('conversion_result') }}</p>
    @endif
    @if (session('story_time'))
        @php($storyTime = session('story_time'))
        <div class="universe-card" role="status">
            <h2>Tiempo de historia resuelto</h2>
            <p class="muted">Momento: {{ $storyTime['story_moment'] ?? 'sin evento' }}</p>
            @foreach ($storyTime['fields'] ?? [] as $field => $data)
                <p class="muted">
                    {{ $field }}: {{ is_scalar($data['value'] ?? null) ? $data['value'] : json_encode($data['value'], JSON_UNESCAPED_UNICODE) }}
                    @if (! empty($data['uncertain']))
                        · incierto
                    @endif
                </p>
            @endforeach
        </div>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="universe-card" aria-labelledby="new-calendar-title">
        <h2 id="new-calendar-title">Nuevo calendario</h2>
        <form method="POST" action="/workspace/universes/{{ $universe->id }}/calendars?format=html" class="inline-form">
            @csrf
            <div class="field">
                <label for="calendar-name">Nombre</label>
                <input id="calendar-name" name="name" type="text" required maxlength="160" placeholder="Era de Arven">
            </div>
            <button class="button button-primary" type="submit">Crear calendario</button>
        </form>
    </section>

    <section class="library-shelf" aria-labelledby="calendars-title">
        <div class="library-shelf-heading">
            <h2 id="calendars-title">Calendarios del universo</h2>
        </div>

        @if ($calendars->isEmpty())
            <p class="muted">Todavía no hay calendarios. Crea el primero para fechar los eventos.</p>
        @else
            <div class="universe-list">
                @foreach ($calendars as $calendar)
                    <article class="universe-card">
                        <div class="shelf-card-heading">
                            <div>
                                <h3>{{ $calendar->name }}</h3>
                                <p class="muted">
                                    @if ($calendar->is_current)
                                        Calendario vigente
                                    @else
                                        Calendario anterior
                                    @endif
                                    @if ($calendar->superseded_at)
                                        · sustituido el {{ $calendar->superseded_at->format('Y-m-d') }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="universe-card" aria-labelledby="new-conversion-title">
        <h2 id="new-conversion-title">Conversiones</h2>
        @if ($calendars->count() < 2)
            <p class="muted">Necesitas al menos dos calendarios para registrar una equivalencia.</p>
        @else
            <form method="POST" action="/workspace/universes/{{ $universe->id }}/calendar-conversions?format=html" class="inline-form">
                @csrf
                <div class="field-row">
                    <div class="field">
                        <label for="conversion-from">Calendario de origen</label>
                        <select id="conversion-from" name="from_calendar_id" required>
                            @foreach ($calendars as $calendar)
                                <option value="{{ $calendar->id }}">{{ $calendar->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="conversion-to">Calendario de destino</label>
                        <select id="conversion-to" name="to_calendar_id" required>
                            @foreach ($calendars as $calendar)
                                <option value="{{ $calendar->id }}">{{ $calendar->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="conversion-from-label">Etiqueta de origen</label>
                        <input id="conversion-from-label" name="from_label" type="text" required maxlength="200" placeholder="1 de Enero de 1">
                    </div>
                    <div class="field">
                        <label for="conversion-to-label">Etiqueta de destino</label>
                        <input id="conversion-to-label" name="to_label" type="text" required maxlength="200" placeholder="Año 1">
                    </div>
                </div>
                <button class="button button-primary" type="submit">Registrar equivalencia</button>
            </form>
        @endif

        @if ($conversions->isEmpty())
            <p class="muted">Sin equivalencias registradas.</p>
        @else
            <ul class="novel-list">
                @foreach ($conversions as $conversion)
                    <li class="muted">
                        {{ $conversion->from_label }} → {{ $conversion->to_label }}
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="universe-card" aria-labelledby="resolve-conversion-title">
        <h2 id="resolve-conversion-title">Resolver una conversión</h2>
        @if ($calendars->count() < 2)
            <p class="muted">Necesitas al menos dos calendarios para resolver una fecha.</p>
        @else
            <form method="POST" action="/workspace/universes/{{ $universe->id }}/calendar-conversions/resolve?format=html" class="inline-form">
                @csrf
                <div class="field-row">
                    <div class="field">
                        <label for="resolve-from">Calendario de origen</label>
                        <select id="resolve-from" name="from_calendar_id" required>
                            @foreach ($calendars as $calendar)
                                <option value="{{ $calendar->id }}">{{ $calendar->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="resolve-to">Calendario de destino</label>
                        <select id="resolve-to" name="to_calendar_id" required>
                            @foreach ($calendars as $calendar)
                                <option value="{{ $calendar->id }}">{{ $calendar->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="resolve-label">Etiqueta de origen</label>
                        <input id="resolve-label" name="label" type="text" required maxlength="200" placeholder="1 de Enero de 1">
                    </div>
                </div>
                <button class="button button-secondary" type="submit">Resolver</button>
            </form>
        @endif
    </section>

    <section class="library-shelf" aria-labelledby="story-time-title">
        <div class="library-shelf-heading">
            <h2 id="story-time-title">Tiempo de historia</h2>
        </div>

        @if ($cards->isEmpty())
            <p class="muted">Todavía no hay fichas con cambios de historia.</p>
        @else
            <div class="universe-list">
                @foreach ($cards as $card)
                    <article class="universe-card">
                        <h3>{{ $card->name }}</h3>

                        <form method="POST" action="/workspace/universes/{{ $universe->id }}/cards/{{ $card->id }}/story-changes?format=html" class="inline-form">
                            @csrf
                            <div class="field-row">
                                <div class="field">
                                    <label for="change-field-{{ $card->id }}">Campo</label>
                                    <input id="change-field-{{ $card->id }}" name="field" type="text" required maxlength="64" placeholder="eye">
                                </div>
                                <div class="field">
                                    <label for="change-value-{{ $card->id }}">Valor</label>
                                    <input id="change-value-{{ $card->id }}" name="value" type="text" placeholder="perdido">
                                </div>
                                <div class="field">
                                    <label for="change-event-{{ $card->id }}">Evento</label>
                                    <select id="change-event-{{ $card->id }}" name="event_id">
                                        <option value="">Sin evento</option>
                                        @foreach ($events as $event)
                                            <option value="{{ $event->id }}">{{ $event->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <button class="button button-secondary" type="submit">Registrar cambio</button>
                        </form>

                        <form method="GET" action="/workspace/universes/{{ $universe->id }}/cards/{{ $card->id }}/story-time" class="inline-form">
                            <input type="hidden" name="format" value="html">
                            <div class="field-row">
                                <div class="field">
                                    <label for="story-event-{{ $card->id }}">Consultar en el evento</label>
                                    <select id="story-event-{{ $card->id }}" name="event_id">
                                        <option value="">Sin evento</option>
                                        @foreach ($events as $event)
                                            <option value="{{ $event->id }}">{{ $event->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button class="button button-secondary" type="submit">Ver tiempo de historia</button>
                            </div>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @if (is_array($warnings))
        <section class="library-shelf" aria-labelledby="warnings-title">
            <div class="library-shelf-heading">
                <h2 id="warnings-title">Avisos de continuidad</h2>
            </div>

            @if ($warnings === [])
                <p class="muted">Sin avisos de continuidad.</p>
            @else
                <div class="universe-list">
                    @foreach ($warnings as $warning)
                        <article class="universe-card">
                            <h3>{{ $warning['code'] }}</h3>
                            <p>{{ $warning['message'] }}</p>
                            <form method="POST" action="/workspace/universes/{{ $universe->id }}/continuity-warnings/dismiss?format=html" class="inline-form">
                                @csrf
                                <input type="hidden" name="fingerprint" value="{{ $warning['fingerprint'] }}">
                                <button class="button button-secondary" type="submit">Descartar aviso</button>
                            </form>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
@endsection
