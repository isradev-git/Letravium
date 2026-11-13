@extends('layouts.workspace')

@section('title', $card->name)
@section('main_class', 'site-main library-main')

@section('content')
    @php
        $labels = [
            'identity' => 'Identidad', 'names' => 'Nombres', 'appearance' => 'Apariencia',
            'personality' => 'Personalidad', 'motivations' => 'Motivaciones', 'fears' => 'Miedos',
            'biography' => 'Biografía', 'capabilities' => 'Capacidades', 'relationships' => 'Relaciones',
            'notes' => 'Notas', 'type' => 'Tipo', 'containment' => 'Contención', 'description' => 'Descripción',
            'environment' => 'Entorno', 'inhabitants' => 'Habitantes', 'government_groups' => 'Gobierno y grupos',
            'history' => 'Historia', 'operation' => 'Funcionamiento', 'scope' => 'Alcance',
            'conditions' => 'Condiciones', 'limits' => 'Límites', 'costs' => 'Costes',
            'consequences' => 'Consecuencias', 'exceptions' => 'Excepciones', 'affected' => 'Afectados',
            'purpose' => 'Propósito', 'characteristics' => 'Características', 'organization' => 'Organización',
            'members' => 'Miembros', 'seats' => 'Sedes', 'territory' => 'Territorio', 'relations' => 'Relaciones',
            'participants' => 'Participantes', 'places' => 'Lugares', 'moment' => 'Momento',
            'properties' => 'Propiedades', 'uses' => 'Usos', 'origins' => 'Orígenes', 'custody' => 'Custodia',
            'rules' => 'Reglas', 'speakers' => 'Hablantes', 'writing' => 'Escritura', 'pronunciation' => 'Pronunciación',
            'expressions' => 'Expresiones', 'meaning' => 'Significado', 'preferred_form' => 'Forma preferida',
            'variants' => 'Variantes', 'examples' => 'Ejemplos', 'content' => 'Contenido', 'links' => 'Vínculos',
            'sources' => 'Fuentes',
        ];
    @endphp

    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">{{ $template['label'] ?? $card->area }}</p>
            <h1>{{ $card->name }}</h1>
            <p class="muted">{{ $universe->name }}</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/cards?area={{ $card->area }}">Volver a fichas</a>
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="/workspace/cards/{{ $card->id }}" class="universe-card">
        @csrf
        @method('PATCH')

        <div class="field-row">
            <label for="card-name">Nombre</label>
            <input id="card-name" name="name" type="text" value="{{ $card->name }}" required maxlength="200">
        </div>

        <div class="field-row">
            <label for="card-template">Plantilla</label>
            <input id="card-template" name="template" type="text" value="{{ $card->template }}" maxlength="64" placeholder="character, place, rule…">
        </div>

        @foreach ($template['fields'] ?? [] as $field)
            @php($raw = $card->fields[$field] ?? '')
            <div class="field-row">
                <label for="field-{{ $field }}">{{ $labels[$field] ?? ucfirst(str_replace('_', ' ', $field)) }}</label>
                <textarea id="field-{{ $field }}" name="fields[{{ $field }}]" rows="3">{{ is_array($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE) : $raw }}</textarea>
            </div>
        @endforeach

        @foreach ($customFields as $field)
            @php($key = $field['key'])
            @php($value = $card->fields[$key] ?? null)
            @php($inputId = 'custom-'.$key)
            <div class="field-row">
                <label for="{{ $inputId }}">{{ $field['label'] }}</label>
                @if ($field['type'] === 'long_text')
                    <textarea id="{{ $inputId }}" name="fields[{{ $key }}]" rows="3">{{ is_scalar($value) ? $value : '' }}</textarea>
                @elseif ($field['type'] === 'boolean')
                    <input type="hidden" name="fields[{{ $key }}]" value="0">
                    <label class="checkbox-field">
                        <input id="{{ $inputId }}" type="checkbox" name="fields[{{ $key }}]" value="1" @checked($value)>
                        {{ $field['label'] }}
                    </label>
                @elseif ($field['type'] === 'select')
                    <select id="{{ $inputId }}" name="fields[{{ $key }}]">
                        <option value="">—</option>
                        @foreach ($field['options'] as $option)
                            <option value="{{ $option }}" @selected((string) $value === (string) $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                @elseif ($field['type'] === 'multiselect')
                    @php($selected = is_array($value) ? $value : [])
                    <select id="{{ $inputId }}" name="fields[{{ $key }}][]" multiple>
                        @foreach ($field['options'] as $option)
                            <option value="{{ $option }}" @selected(in_array($option, $selected, true))>{{ $option }}</option>
                        @endforeach
                    </select>
                @else
                    <input
                        id="{{ $inputId }}"
                        type="{{ $field['type'] === 'number' ? 'number' : ($field['type'] === 'date' ? 'date' : ($field['type'] === 'url' ? 'url' : 'text')) }}"
                        name="fields[{{ $key }}]"
                        value="{{ is_scalar($value) ? $value : '' }}"
                    >
                @endif
                <input type="hidden" name="present_fields[]" value="{{ $key }}">
            </div>
        @endforeach

        <button class="button button-primary" type="submit">Guardar ficha</button>
    </form>

    <section class="universe-card" aria-labelledby="references-title">
        <h2 id="references-title">Escenas que la referencian</h2>
        @if ($references->isEmpty())
            <p class="muted">Todavía no hay referencias desde el manuscrito.</p>
        @else
            <ul class="novel-list">
                @foreach ($references as $reference)
                    <li>
                        <a class="novel-link" href="/workspace/novels/{{ $reference->novel_id }}#scene-{{ $reference->scene_id }}">
                            <span class="novel-title">{{ $reference->scene_title ?: 'Escena sin título' }}</span>
                            <span class="novel-action">{{ $reference->novel_title }} · {{ $reference->chapter_title }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <form method="POST" action="/workspace/card/{{ $card->id }}/trash" class="inline-form">
        @csrf
        <button class="button button-secondary" type="submit">Mover ficha a la papelera</button>
    </form>

    <section class="universe-card" aria-labelledby="relationships-title">
        <h2 id="relationships-title">Relaciones</h2>
        @if ($relationships->isEmpty())
            <p class="muted">Sin relaciones registradas.</p>
        @else
            <ul class="novel-list">
                @foreach ($relationships as $relationship)
                    <li class="muted">
                        {{ $relationshipCards[$relationship->source_card_id]->name ?? 'Ficha '.$relationship->source_card_id }}
                        → {{ $relationshipCards[$relationship->target_card_id]->name ?? 'Ficha '.$relationship->target_card_id }}
                        · {{ $relationship->label }}
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($targets->isNotEmpty())
            <form method="POST" action="/workspace/universes/{{ $universe->id }}/relationships" class="inline-form">
                @csrf
                <input type="hidden" name="source_card_id" value="{{ $card->id }}">
                <label for="relationship-target">Relacionar con</label>
                <div class="field-row">
                    <select id="relationship-target" name="target_card_id" required>
                        @foreach ($targets as $target)
                            <option value="{{ $target->id }}">{{ $target->name }} ({{ $target->area }})</option>
                        @endforeach
                    </select>
                    <input name="label" type="text" required maxlength="200" placeholder="teme a, viaja a, pertenece a…">
                    <button class="button button-secondary" type="submit">Añadir relación</button>
                </div>
            </form>
        @endif
    </section>

    @if ($isPlace)
        <section class="universe-card" aria-labelledby="containment-title">
            <h2 id="containment-title">Contención</h2>

            @if (count($containmentChain) > 1)
                <p class="muted">Cadena actual: {{ implode(' › ', $containmentChain) }}</p>
            @else
                <p class="muted">Este lugar no está contenido en otro.</p>
            @endif

            @if ($parentOptions->isNotEmpty())
                <form method="POST" action="/workspace/universes/{{ $universe->id }}/places/containment?format=html" class="inline-form">
                    @csrf
                    <input type="hidden" name="child_card_id" value="{{ $card->id }}">
                    <div class="field-row">
                        <div class="field">
                            <label for="containment-parent">Lugar contenedor</label>
                            <select id="containment-parent" name="parent_card_id" required>
                                @foreach ($parentOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="button button-secondary" type="submit">Guardar contención</button>
                    </div>
                </form>
            @else
                <p class="muted">No hay otro lugar disponible como contenedor.</p>
            @endif
        </section>
    @endif

    @if ($objectKind === 'unique')
        <section class="universe-card" aria-labelledby="custody-title">
            <h2 id="custody-title">Custodia</h2>

            @if ($custodyHistory->isEmpty())
                <p class="muted">Sin custodia registrada.</p>
            @else
                <ul class="novel-list">
                    @foreach ($custodyHistory as $custody)
                        <li class="muted">
                            {{ $holderNames[$custody->holder_card_id] ?? 'Sin portador' }}
                            @if ($custody->event_id !== null)
                                · {{ $eventNames[$custody->event_id] ?? 'Evento '.$custody->event_id }}
                            @endif
                            @if ($custody->moment)
                                · {{ $custody->moment }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="/workspace/universes/{{ $universe->id }}/objects/{{ $card->id }}/custody?format=html" class="inline-form">
                @csrf
                <div class="field-row">
                    <div class="field">
                        <label for="custody-holder">Portador</label>
                        <select id="custody-holder" name="holder_card_id">
                            <option value="">Sin portador</option>
                            @foreach ($holders as $holder)
                                <option value="{{ $holder->id }}">{{ $holder->name }} ({{ $holder->area }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="custody-event">Evento</label>
                        <select id="custody-event" name="event_id">
                            <option value="">Sin evento</option>
                            @foreach ($events as $event)
                                <option value="{{ $event->id }}">{{ $event->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="custody-moment">Momento</label>
                        <input id="custody-moment" name="moment" type="text" maxlength="200" placeholder="invierno de 1240">
                    </div>
                </div>
                <button class="button button-secondary" type="submit">Añadir custodia</button>
            </form>
        </section>
    @endif

    @if ($objectKind === 'resource')
        <section class="universe-card" aria-labelledby="origins-title">
            <h2 id="origins-title">Procedencias</h2>

            @if ($origins->isEmpty())
                <p class="muted">Sin procedencias registradas.</p>
            @else
                <ul class="novel-list">
                    @foreach ($origins as $origin)
                        <li class="muted">
                            {{ $origin->origin ?: 'Origen sin describir' }}
                            @if ($origin->place_card_id !== null)
                                · {{ $originPlaceNames[$origin->place_card_id] ?? 'Lugar '.$origin->place_card_id }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="/workspace/universes/{{ $universe->id }}/resources/{{ $card->id }}/origins?format=html" class="inline-form">
                @csrf
                <div class="field-row">
                    <div class="field">
                        <label for="origin-text">Procedencia</label>
                        <input id="origin-text" name="origin" type="text" maxlength="200" placeholder="Minas del norte">
                    </div>
                    <div class="field">
                        <label for="origin-place">Lugar de origen</label>
                        <select id="origin-place" name="place_card_id">
                            <option value="">Sin lugar vinculado</option>
                            @foreach ($originPlaces as $place)
                                <option value="{{ $place->id }}">{{ $place->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <button class="button button-secondary" type="submit">Añadir procedencia</button>
            </form>
        </section>
    @endif
@endsection
