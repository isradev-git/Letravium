@extends('layouts.workspace')

@section('title', 'Planificación de '.$novel->title)
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Planificación</p>
            <h1>{{ $novel->title }}</h1>
            <p class="muted">Planes de capítulo y alternativa, estados de trabajo y extensión. No cambia el texto del manuscrito.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}">Volver a escribir</a>
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/search">Buscar</a>
            <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}/character-roles">Roles de personajes</a>
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <section class="admin-card">
        <div class="admin-card-heading">
            <h2>Extensión</h2>
            <span class="badge">{{ $totalWords }} palabras</span>
        </div>
        <p class="muted">
            @if ($target === null)
                Sin meta de palabras.
            @elseif ($totalWords >= $target)
                Meta de {{ $target }} palabras alcanzada.
            @else
                Meta de {{ $target }} palabras; faltan {{ $target - $totalWords }}.
            @endif
        </p>
        <form method="POST" action="/workspace/novels/{{ $novel->id }}/goal?format=html" class="inline-form">
            @csrf
            @method('PUT')
            <label for="planning-goal">Meta orientativa de palabras</label>
            <div class="field-row">
                <input id="planning-goal" name="target_words" type="number" min="0" step="1" value="{{ $target ?? 0 }}">
                <button class="button button-secondary" type="submit">Guardar meta</button>
            </div>
        </form>
    </section>

    <section class="admin-card">
        <div class="admin-card-heading">
            <h2>Partes</h2>
            <span class="muted">{{ $novel->parts->count() }} {{ $novel->parts->count() === 1 ? 'parte' : 'partes' }}</span>
        </div>

        <form method="POST" action="/workspace/novels/{{ $novel->id }}/parts?format=html" class="inline-form">
            @csrf
            <label for="new-part-title">Nueva parte</label>
            <div class="field-row">
                <input id="new-part-title" name="title" type="text" required maxlength="200" placeholder="Nombre de la parte">
                <button class="button button-secondary" type="submit">Crear parte</button>
            </div>
        </form>

        @if ($novel->parts->isNotEmpty())
            <ul class="novel-list">
                @foreach ($novel->parts as $part)
                    <li><span class="novel-title">{{ $part->title }}</span></li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="admin-card">
        <div class="admin-card-heading">
            <h2>Estados de trabajo</h2>
            <span class="muted">Opcionales y manuales.</span>
        </div>

        <form method="POST" action="/workspace/novels/{{ $novel->id }}/work-states?format=html" class="inline-form">
            @csrf
            <label for="new-work-state">Nuevo estado</label>
            <div class="field-row">
                <input id="new-work-state" name="name" type="text" required maxlength="120" placeholder="Por ejemplo, Revisar diálogos">
                <button class="button button-secondary" type="submit">Crear estado</button>
            </div>
        </form>

        @if ($workStates->isEmpty())
            <p class="muted">Todavía no hay estados personalizados.</p>
        @else
            <ul class="novel-list">
                @foreach ($workStates as $state)
                    <li>
                        <form method="POST" action="/workspace/work-states/{{ $state->id }}?format=html" class="inline-form">
                            @csrf
                            @method('PATCH')
                            <label for="work-state-{{ $state->id }}">Estado {{ $loop->iteration }}</label>
                            <div class="field-row">
                                <input id="work-state-{{ $state->id }}" name="name" type="text" required maxlength="120" value="{{ $state->name }}">
                                <button class="button button-secondary" type="submit">Renombrar</button>
                            </div>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="admin-card">
        <div class="admin-card-heading">
            <h2>Tramas</h2>
            <span class="muted">{{ $plots->count() }} {{ $plots->count() === 1 ? 'trama' : 'tramas' }}</span>
        </div>

        <form method="POST" action="/workspace/novels/{{ $novel->id }}/plots?format=html" class="inline-form">
            @csrf
            <label for="new-plot-name">Nueva trama</label>
            <div class="field-row">
                <input id="new-plot-name" name="name" type="text" required maxlength="200" placeholder="Nombre de la trama">
                <button class="button button-secondary" type="submit">Crear trama</button>
            </div>
            <div class="format-field">
                <label for="new-plot-description">Descripción</label>
                <textarea id="new-plot-description" name="description" rows="2"></textarea>
            </div>
        </form>

        @if ($plots->isEmpty())
            <p class="muted">Todavía no hay tramas.</p>
        @else
            <ul class="universe-list">
                @foreach ($plots as $plot)
                    <li class="universe-card">
                        <div class="universe-heading">
                            <h3>{{ $plot->name }}</h3>
                            <span class="muted">{{ $plot->scenes->count() }} escenas</span>
                        </div>
                        @if ($plot->description)
                            <p class="muted">{{ $plot->description }}</p>
                        @endif
                        @if ($plot->scenes->isNotEmpty())
                            <ul class="novel-list">
                                @foreach ($plot->scenes as $linkedScene)
                                    <li><span class="novel-title">{{ $linkedScene->title ?: 'Escena sin título' }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($chapters->isEmpty())
        <section class="empty-state">
            <h2>Sin capítulos todavía</h2>
            <p>Crea un capítulo en el editor para empezar a planificar.</p>
        </section>
    @else
        <section aria-labelledby="planning-chapters-title">
            <div class="library-section-heading">
                <h2 id="planning-chapters-title">Capítulos</h2>
                <span class="muted">{{ $chapters->count() }} {{ $chapters->count() === 1 ? 'capítulo' : 'capítulos' }}</span>
            </div>

            @foreach ($chapters as $chapter)
                @php($active = $chapter->alternatives->firstWhere('is_active', true))
                @php($part = $novel->parts->firstWhere('id', $chapter->part_id))
                <article class="universe-card">
                    <div class="universe-heading">
                        <h3>
                            <a href="/workspace/novels/{{ $novel->id }}?view=chapter&chapter={{ $chapter->id }}">
                                {{ $chapter->title ?: 'Capítulo sin título' }}
                            </a>
                        </h3>
                        <span class="muted">
                            {{ $wordCounts[$chapter->id] ?? 0 }} palabras
                            @if ($part) · {{ $part->title }} @endif
                        </span>
                    </div>

                    @php($chapterIds = $chapters->pluck('id')->all())
                    @php($upOrder = $chapterIds)
                    @php($downOrder = $chapterIds)
                    @if ($loop->index > 0)
                        @php([$upOrder[$loop->index - 1], $upOrder[$loop->index]] = [$upOrder[$loop->index], $upOrder[$loop->index - 1]])
                    @endif
                    @if ($loop->index < count($chapterIds) - 1)
                        @php([$downOrder[$loop->index], $downOrder[$loop->index + 1]] = [$downOrder[$loop->index + 1], $downOrder[$loop->index]])
                    @endif
                    <div class="field-row">
                        @if ($loop->index > 0)
                            <form method="POST" action="/workspace/novels/{{ $novel->id }}/chapters/order?format=html" class="inline-form">
                                @csrf
                                @method('PATCH')
                                @foreach ($upOrder as $id)
                                    <input type="hidden" name="order[]" value="{{ $id }}">
                                @endforeach
                                <button class="button button-secondary" type="submit">Subir</button>
                            </form>
                        @endif
                        @if ($loop->index < count($chapterIds) - 1)
                            <form method="POST" action="/workspace/novels/{{ $novel->id }}/chapters/order?format=html" class="inline-form">
                                @csrf
                                @method('PATCH')
                                @foreach ($downOrder as $id)
                                    <input type="hidden" name="order[]" value="{{ $id }}">
                                @endforeach
                                <button class="button button-secondary" type="submit">Bajar</button>
                            </form>
                        @endif
                    </div>

                    <div class="format-field">
                        <h4>Alternativas</h4>
                        @if ($chapter->alternatives->isEmpty())
                            <p class="muted">Este capítulo no tiene alternativas.</p>
                        @else
                            <ul class="novel-list">
                                @foreach ($chapter->alternatives as $alternative)
                                    <li>
                                        <span class="novel-title">{{ $alternative->alias }}</span>
                                        @if ($alternative->is_active)
                                            <span class="badge">Activa</span>
                                        @else
                                            <form method="POST" action="/workspace/alternatives/{{ $alternative->id }}/activate?format=html" class="inline-form">
                                                @csrf
                                                @method('PATCH')
                                                <button class="button button-secondary" type="submit">Activar</button>
                                            </form>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <form method="POST" action="/workspace/chapters/{{ $chapter->id }}/alternatives?format=html" class="inline-form">
                            @csrf
                            <label for="new-alternative-{{ $chapter->id }}">Nueva alternativa</label>
                            <div class="field-row">
                                <input id="new-alternative-{{ $chapter->id }}" name="alias" type="text" required maxlength="160" placeholder="Nombre de la alternativa">
                                <button class="button button-secondary" type="submit">Crear alternativa</button>
                            </div>
                        </form>
                    </div>

                    <form method="POST" action="/workspace/chapters/{{ $chapter->id }}/plan?format=html" class="inline-form">
                        @csrf
                        @method('PUT')
                        <h4>Plan del capítulo</h4>
                        <div class="format-field">
                            <label for="chapter-summary-{{ $chapter->id }}">Resumen</label>
                            <textarea id="chapter-summary-{{ $chapter->id }}" name="plan[summary]" rows="3">{{ $chapter->plan['summary'] ?? '' }}</textarea>
                        </div>
                        <div class="format-field">
                            <label for="chapter-intent-{{ $chapter->id }}">Intención del pasaje</label>
                            <textarea id="chapter-intent-{{ $chapter->id }}" name="plan[intent]" rows="2">{{ $chapter->plan['intent'] ?? '' }}</textarea>
                        </div>
                        <div class="format-field">
                            <label for="chapter-notes-{{ $chapter->id }}">Notas de planificación</label>
                            <textarea id="chapter-notes-{{ $chapter->id }}" name="plan[notes]" rows="2">{{ $chapter->plan['notes'] ?? '' }}</textarea>
                        </div>
                        <button class="button button-secondary" type="submit">Guardar plan del capítulo</button>
                    </form>

                    @if ($active)
                        <form method="POST" action="/workspace/alternatives/{{ $active->id }}/plan?format=html" class="inline-form">
                            @csrf
                            @method('PUT')
                            <h4>Plan de la alternativa activa: {{ $active->alias }}</h4>
                            <div class="format-field">
                                <label for="alternative-summary-{{ $active->id }}">Resumen de la alternativa</label>
                                <textarea id="alternative-summary-{{ $active->id }}" name="plan[summary]" rows="2">{{ $active->plan['summary'] ?? '' }}</textarea>
                            </div>
                            <div class="format-field">
                                <label for="alternative-intent-{{ $active->id }}">Intención</label>
                                <textarea id="alternative-intent-{{ $active->id }}" name="plan[intent]" rows="2">{{ $active->plan['intent'] ?? '' }}</textarea>
                            </div>
                            <button class="button button-secondary" type="submit">Guardar plan de la alternativa</button>
                        </form>

                        <form method="POST" action="/workspace/alternatives/{{ $active->id }}/work-state?format=html" class="inline-form">
                            @csrf
                            @method('PUT')
                            <label for="alternative-state-{{ $active->id }}">Estado de trabajo</label>
                            <div class="field-row">
                                <select id="alternative-state-{{ $active->id }}" name="work_state_id">
                                    <option value="">Sin estado</option>
                                    @foreach ($workStates as $state)
                                        <option value="{{ $state->id }}" @selected((int) $active->work_state_id === (int) $state->id)>{{ $state->name }}</option>
                                    @endforeach
                                </select>
                                <button class="button button-secondary" type="submit">Asignar estado</button>
                            </div>
                        </form>
                    @else
                        <p class="muted">Este capítulo no tiene una alternativa activa.</p>
                    @endif

                    @if ($active && $active->scenes->isNotEmpty())
                        <h4>Escenas</h4>
                        @foreach ($active->scenes as $scene)
                            <form method="POST" action="/workspace/scenes/{{ $scene->id }}?format=html" class="inline-form">
                                @csrf
                                @method('PUT')
                                <fieldset class="admin-card">
                                    <legend>{{ $scene->title ?: 'Escena sin título' }}</legend>
                                    <div class="format-field">
                                        <label for="scene-title-{{ $scene->id }}">Título de la escena</label>
                                        <input id="scene-title-{{ $scene->id }}" name="title" type="text" maxlength="200" value="{{ $scene->title }}">
                                    </div>
                                    <div class="format-field">
                                        <label for="scene-summary-{{ $scene->id }}">Resumen</label>
                                        <textarea id="scene-summary-{{ $scene->id }}" name="summary" rows="2">{{ $scene->summary }}</textarea>
                                    </div>
                                    <div class="format-field">
                                        <label for="scene-moment-{{ $scene->id }}">Momento narrativo</label>
                                        <input id="scene-moment-{{ $scene->id }}" name="narrative_moment" type="text" maxlength="200" value="{{ $scene->narrative_moment }}">
                                    </div>
                                    <div class="format-field">
                                        <label for="scene-intent-{{ $scene->id }}">Intención del pasaje</label>
                                        <textarea id="scene-intent-{{ $scene->id }}" name="plan[intent]" rows="2">{{ $scene->plan['intent'] ?? '' }}</textarea>
                                    </div>
                                    <div class="format-field">
                                        <label for="scene-characters-{{ $scene->id }}">Personajes</label>
                                        <textarea id="scene-characters-{{ $scene->id }}" name="plan[characters]" rows="2">{{ $scene->plan['characters'] ?? '' }}</textarea>
                                    </div>
                                    <div class="field-row">
                                        <div class="format-field">
                                            <label for="scene-pov-{{ $scene->id }}">Punto de vista</label>
                                            <select id="scene-pov-{{ $scene->id }}" name="pov_card_id">
                                                <option value="">Sin ficha</option>
                                                @foreach ($characters as $character)
                                                    <option value="{{ $character->id }}" @selected((int) $scene->pov_card_id === (int) $character->id)>{{ $character->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="format-field">
                                            <label for="scene-place-{{ $scene->id }}">Lugar</label>
                                            <select id="scene-place-{{ $scene->id }}" name="place_card_id">
                                                <option value="">Sin ficha</option>
                                                @foreach ($places as $place)
                                                    <option value="{{ $place->id }}" @selected((int) $scene->place_card_id === (int) $place->id)>{{ $place->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <button class="button button-secondary" type="submit">Guardar plan de la escena</button>
                                </fieldset>
                            </form>
                            @if ($plots->isNotEmpty())
                                <form method="POST" action="/workspace/scenes/{{ $scene->id }}/plots?format=html" class="inline-form">
                                    @csrf
                                    <label for="scene-plot-{{ $scene->id }}">Vincular esta escena a una trama</label>
                                    <div class="field-row">
                                        <select id="scene-plot-{{ $scene->id }}" name="plot_id">
                                            @foreach ($plots as $plot)
                                                <option value="{{ $plot->id }}">{{ $plot->name }}</option>
                                            @endforeach
                                        </select>
                                        <button class="button button-secondary" type="submit">Vincular</button>
                                    </div>
                                </form>
                            @endif
                        @endforeach
                    @endif
                </article>
            @endforeach
        </section>
    @endif
@endsection
