@extends('layouts.workspace')

@section('title', $novel->title)
@section('body_class', 'workspace-page')
@section('main_class', 'site-main editor-main workspace-main'.(empty($editableChapterIds) ? ' editor-read-only' : ''))

@section('content')
    <script type="application/json" id="character-styles">{!! json_encode($characterStyles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @if ($fonts->isNotEmpty())
        <style>
            @foreach ($fonts as $font)
                @font-face {
                    font-family: 'AppFont{{ $font['id'] }}';
                    src: url('{{ url('/workspace/fonts/'.$font['id'].'/view') }}') format('{{ $font['format'] }}');
                }
            @endforeach
        </style>
    @endif
    <header class="editor-header">
        <div class="editor-heading">
            <p class="kicker">Escribir</p>
            <h1>{{ $novel->title }}</h1>
        </div>

        <div class="editor-panel-controls" role="group" aria-label="Paneles">
            <button type="button" id="toggle-chapters" class="button button-secondary editor-panel-toggle"
                    data-panel-toggle="editor-chapters" aria-controls="editor-chapters" aria-expanded="false">Capítulos</button>
            <button type="button" id="toggle-context" class="button button-secondary editor-panel-toggle"
                    data-panel-toggle="editor-context" aria-controls="editor-context" aria-expanded="false">Contexto</button>
        </div>

        <div class="editor-toolbar" role="toolbar" aria-label="Formato del texto">
            <button type="button" class="tool" data-command="undo" aria-label="Deshacer">↶</button>
            <button type="button" class="tool" data-command="redo" aria-label="Rehacer">↷</button>
            <span class="tool-separator" aria-hidden="true"></span>
            <button type="button" class="tool" data-command="bold" aria-label="Negrita"><strong>N</strong></button>
            <button type="button" class="tool" data-command="italic" aria-label="Cursiva"><em>C</em></button>
            <button type="button" class="tool" data-command="underline" aria-label="Subrayado"><u>S</u></button>
            <button type="button" class="tool" data-command="strikeThrough" aria-label="Tachado"><s>T</s></button>
            <button type="button" class="tool" data-command="superscript" aria-label="Superíndice">x²</button>
            <button type="button" class="tool" data-command="subscript" aria-label="Subíndice">x₂</button>
            <span class="tool-separator" aria-hidden="true"></span>
            <button type="button" class="tool" data-block="p" aria-label="Párrafo">¶</button>
            <button type="button" class="tool" data-block="h2" aria-label="Encabezado">H</button>
            <button type="button" class="tool" data-block="blockquote" aria-label="Cita">“”</button>
            <button type="button" class="tool" data-command="insertUnorderedList" aria-label="Lista con viñetas">•</button>
            <button type="button" class="tool" data-command="insertOrderedList" aria-label="Lista numerada">1.</button>
            <button type="button" class="tool" data-break="scene_break" aria-label="Separación de escena">* * *</button>
            <span class="tool-separator" aria-hidden="true"></span>
            <button type="button" class="tool" data-panel="link" aria-label="Insertar enlace">Enlace</button>
            <button type="button" class="tool" data-panel="bookmark" aria-label="Aplicar marcador">Marcador</button>
            <button type="button" class="tool" data-panel="table" aria-label="Insertar o editar tabla">Tabla</button>
            @if ($isOwner)
                <button type="button" class="tool" data-panel="image" aria-label="Insertar o editar imagen">Imagen</button>
                <button type="button" class="tool" data-panel="note" aria-label="Insertar o editar nota">Nota</button>
            @endif
            <button type="button" class="tool" data-panel="typography" aria-label="Ayudas de tipografía">Tipografía</button>
            <button type="button" class="tool" data-panel="synonyms" aria-label="Buscar sinónimos">Sinónimos</button>
            <button type="button" class="tool" data-panel="spelling" aria-label="Revisar ortografía">Ortografía</button>
            <button type="button" class="tool" data-panel="paragraph" aria-label="Formato de texto y párrafo">Formato</button>
        </div>

        <div class="editor-save">
            <p id="editor-status" class="editor-status" role="status" aria-live="polite" data-state="saved">
                {{ $isOwner || ! empty($editableChapterIds) ? 'Guardado en servidor' : 'Solo lectura' }}
            </p>
            <button
                type="button"
                id="focus-toggle"
                class="button button-secondary"
                aria-pressed="false"
                aria-keyshortcuts="Control+Shift+F Meta+Shift+F"
            >Solo texto</button>
            <button type="button" id="save-all" class="button button-primary" aria-label="Guardar">Guardar</button>
        </div>
    </header>

    <div class="editor-insert-panel" id="link-panel" role="group" aria-label="Insertar enlace" hidden>
        <label for="link-url">URL del enlace</label>
        <input id="link-url" type="url" inputmode="url" maxlength="2048" placeholder="https://">
        <button type="button" id="link-apply" class="button button-secondary">Aplicar enlace</button>
        <button type="button" id="link-remove" class="button button-secondary">Quitar enlace</button>
        <button type="button" id="link-cancel" class="button button-secondary">Cancelar</button>
    </div>

    <div class="editor-insert-panel" id="bookmark-panel" role="group" aria-label="Marcador" hidden>
        <label for="bookmark-name">Nombre del marcador</label>
        <input id="bookmark-name" type="text" maxlength="120">
        <button type="button" id="bookmark-apply" class="button button-secondary">Aplicar marcador</button>
        <button type="button" id="bookmark-remove" class="button button-secondary">Quitar marcador</button>
        <button type="button" id="bookmark-cancel" class="button button-secondary">Cancelar</button>
    </div>

    <div class="editor-insert-panel" id="paste-panel" role="group" aria-label="Pegar contenido" hidden>
        <p id="paste-summary" class="muted">Elige cómo insertar el contenido pegado.</p>
        <button type="button" id="paste-formatted" class="button button-secondary">Conservar formato compatible</button>
        <button type="button" id="paste-plain" class="button button-secondary">Solo texto</button>
        <button type="button" id="paste-cancel" class="button button-secondary">Cancelar</button>
    </div>

    <div class="editor-insert-panel editor-table-panel" id="table-panel" role="group" aria-label="Tabla" hidden>
        <div class="format-field">
            <label for="table-caption">Pie de tabla</label>
            <input id="table-caption" type="text" maxlength="300">
        </div>
        <div id="table-grid" class="table-grid" role="group" aria-label="Celdas de la tabla"></div>
        <div class="format-actions">
            <button type="button" id="table-add-row" class="button button-secondary">Añadir fila</button>
            <button type="button" id="table-add-column" class="button button-secondary">Añadir columna</button>
            <button type="button" id="table-remove-row" class="button button-secondary">Quitar última fila</button>
            <button type="button" id="table-remove-column" class="button button-secondary">Quitar última columna</button>
        </div>
        <div class="format-actions">
            <button type="button" id="table-apply" class="button button-primary">Aplicar tabla</button>
            <button type="button" id="table-delete" class="button button-secondary">Eliminar tabla</button>
            <button type="button" id="table-cancel" class="button button-secondary">Cancelar</button>
        </div>
    </div>

    <div class="editor-insert-panel editor-image-panel" id="image-panel" role="group" aria-label="Imagen" hidden>
        <div class="format-field">
            <label for="image-asset">Imagen existente</label>
            <select id="image-asset">
                <option value="">Selecciona una imagen</option>
            </select>
        </div>
        <div class="format-field">
            <label for="image-file">Subir imagen (JPEG, PNG o WebP)</label>
            <input id="image-file" type="file" accept="image/png,image/jpeg,image/webp">
        </div>
        <div class="format-field">
            <label for="image-alt">Descripción alternativa</label>
            <input id="image-alt" type="text" maxlength="500" required>
        </div>
        <div class="format-field">
            <label for="image-caption">Pie de imagen</label>
            <input id="image-caption" type="text" maxlength="300">
        </div>
        <div class="format-field">
            <label for="image-width">Ancho (px)</label>
            <input id="image-width" type="number" min="1">
        </div>
        <div class="format-field">
            <label for="image-height">Alto (px)</label>
            <input id="image-height" type="number" min="1">
        </div>
        <div class="format-field">
            <label for="image-align">Alineación</label>
            <select id="image-align">
                <option value="left">Izquierda</option>
                <option value="center" selected>Centrada</option>
                <option value="right">Derecha</option>
            </select>
        </div>
        <p id="image-status" class="muted" role="status" aria-live="polite"></p>
        <div class="format-actions">
            <button type="button" id="image-apply" class="button button-primary">Aplicar imagen</button>
            <button type="button" id="image-delete" class="button button-secondary">Eliminar imagen</button>
            <button type="button" id="image-cancel" class="button button-secondary">Cancelar</button>
        </div>
    </div>

    <div class="editor-insert-panel" id="note-panel" role="group" aria-label="Nota" hidden>
        <div class="format-field">
            <label for="note-select">Nota existente</label>
            <select id="note-select">
                <option value="">Nueva nota</option>
            </select>
        </div>
        <div class="format-field">
            <label for="note-kind">Tipo</label>
            <select id="note-kind">
                <option value="foot">Al pie</option>
                <option value="end">Al final</option>
            </select>
        </div>
        <div class="format-field">
            <label id="note-body-label">Cuerpo de la nota</label>
            <div
                id="note-body"
                class="note-body"
                contenteditable="true"
                role="textbox"
                aria-multiline="true"
                aria-labelledby="note-body-label"
            ></div>
        </div>
        <p id="note-status" class="muted" role="status" aria-live="polite"></p>
        <p id="note-orphans" class="muted"></p>
        <div class="format-actions">
            <button type="button" id="note-save" class="button button-secondary">Guardar nota</button>
            <button type="button" id="note-reference" class="button button-secondary">Insertar referencia</button>
            <button type="button" id="note-remove-reference" class="button button-secondary">Quitar referencia</button>
            <button type="button" id="note-close" class="button button-secondary">Cerrar</button>
        </div>
    </div>

    <div class="editor-insert-panel editor-typography-panel" id="typography-panel" role="group" aria-label="Ayudas de tipografía" hidden>
        <label class="typography-option"><input type="checkbox" id="typography-dialogue" checked> Rayas de diálogo</label>
        <label class="typography-option"><input type="checkbox" id="typography-quotes" checked> Comillas españolas</label>
        <label class="typography-option"><input type="checkbox" id="typography-ellipsis" checked> Puntos suspensivos</label>
        <label class="typography-option"><input type="checkbox" id="typography-nbsp" checked> Espacios de no separación</label>
        <p class="muted">No reescribe frases: solo sustituye signos locales. Puedes deshacerlo con Deshacer.</p>
        <div class="format-actions">
            <button type="button" id="typography-apply" class="button button-secondary">Aplicar al texto seleccionado</button>
            <button type="button" id="typography-cancel" class="button button-secondary">Cancelar</button>
        </div>
    </div>

    <div class="editor-insert-panel editor-synonyms-panel" id="synonyms-panel" role="group" aria-label="Sinónimos" hidden>
        <p id="synonyms-word" class="muted">Selecciona una palabra en el texto.</p>
        <p id="synonyms-status" class="muted" role="status" aria-live="polite"></p>
        <div id="synonyms-groups" class="synonym-groups"></div>
        <div class="format-actions">
            <button type="button" id="synonyms-close" class="button button-secondary">Cerrar</button>
        </div>
    </div>

    <div class="editor-insert-panel editor-spelling-panel" id="spelling-panel" role="group" aria-label="Ortografía" hidden>
        <p id="spelling-summary" class="muted">Revisando la escena…</p>
        <div id="spelling-list" class="spelling-list"></div>
        <div class="format-actions">
            <button type="button" id="spelling-close" class="button button-secondary">Cerrar</button>
        </div>
    </div>

    <div id="spelling-popover" class="spelling-popover" role="dialog" aria-label="Sugerencias ortográficas" hidden>
        <p id="spelling-popover-word" class="spelling-word"></p>
        <div id="spelling-popover-actions" class="synonym-list"></div>
        <button type="button" id="spelling-popover-close" class="button button-secondary">Cerrar</button>
    </div>

    <div id="synonym-popover" class="spelling-popover" role="dialog" aria-label="Sinónimos" hidden>
        <p id="synonym-popover-word" class="spelling-word"></p>
        <p id="synonym-popover-status" class="muted" role="status" aria-live="polite"></p>
        <div id="synonym-popover-groups" class="synonym-groups"></div>
        <button type="button" id="synonym-popover-close" class="button button-secondary">Cerrar</button>
    </div>

    <div class="editor-insert-panel editor-format-panel" id="paragraph-panel" role="group" aria-label="Formato de texto y párrafo" hidden>
        <div class="format-field">
            <label for="format-font">Fuente</label>
            <select id="format-font">
                <option value="">Predeterminada</option>
                <option value="serif">Serif</option>
                <option value="sans">Sans</option>
                @foreach ($fonts as $font)
                    <option value="font:{{ $font['id'] }}">{{ $font['family'] }} · {{ $font['variant'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="format-field">
            <label for="format-size">Tamaño (pt)</label>
            <input id="format-size" type="number" min="8" max="72" step="1">
        </div>
        <div class="format-field">
            <label for="format-align">Alineación</label>
            <select id="format-align">
                <option value="">Predeterminada</option>
                <option value="left">Izquierda</option>
                <option value="center">Centrada</option>
                <option value="right">Derecha</option>
                <option value="justify">Justificada</option>
            </select>
        </div>
        <div class="format-field">
            <label for="format-indent-first">Sangría 1.ª línea (em)</label>
            <input id="format-indent-first" type="number" min="-6" max="6" step="1">
        </div>
        <div class="format-field">
            <label for="format-indent-left">Sangría izquierda (em)</label>
            <input id="format-indent-left" type="number" min="0" max="6" step="1">
        </div>
        <div class="format-field">
            <label for="format-line-height">Interlineado (%)</label>
            <input id="format-line-height" type="number" min="100" max="300" step="5">
        </div>
        <div class="format-field">
            <label for="format-spacing-before">Espacio antes (pt)</label>
            <input id="format-spacing-before" type="number" min="0" max="72" step="1">
        </div>
        <div class="format-field">
            <label for="format-spacing-after">Espacio después (pt)</label>
            <input id="format-spacing-after" type="number" min="0" max="72" step="1">
        </div>
        <div class="format-field">
            <label for="format-character-style">Estilo de carácter</label>
            <select id="format-character-style">
                <option value="">Sin cambio</option>
            </select>
        </div>
        <div class="format-field">
            <label for="format-run-family">Fuente del texto seleccionado</label>
            <select id="format-run-family">
                <option value="">Sin cambio</option>
                <option value="serif">Serif</option>
                <option value="sans">Sans</option>
                @foreach ($fonts as $font)
                    <option value="font:{{ $font['id'] }}">{{ $font['family'] }} · {{ $font['variant'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="format-field">
            <label for="format-run-size">Tamaño del texto seleccionado (pt)</label>
            <input id="format-run-size" type="number" min="8" max="72" step="1" placeholder="Sin cambio">
        </div>
        <div class="format-actions">
            <button type="button" id="run-apply" class="button button-secondary">Aplicar al texto</button>
            <button type="button" id="run-remove" class="button button-secondary">Quitar del texto</button>
        </div>
        <div class="format-actions">
            <button type="button" id="character-apply" class="button button-secondary">Aplicar estilo al texto</button>
            <button type="button" id="character-remove" class="button button-secondary">Quitar estilo del texto</button>
        </div>
        <div class="format-actions">
            <button type="button" id="paragraph-apply" class="button button-secondary">Aplicar formato</button>
            <button type="button" id="paragraph-clear" class="button button-secondary">Quitar formato</button>
            <button type="button" id="paragraph-cancel" class="button button-secondary">Cancelar</button>
        </div>
    </div>

    <div class="editor-workspace">
        <div class="editor-layout">
            <aside id="editor-chapters" class="editor-chapters" aria-label="Capítulos">
            <ol class="chapter-list">
                <li>
                    <a
                        href="/workspace/novels/{{ $novel->id }}?view=manuscript"
                        @class(['is-active' => $mode === 'manuscript'])
                    >Manuscrito completo</a>
                </li>
                @foreach ($chapterList as $chapter)
                    <li>
                        <a
                            href="/workspace/novels/{{ $novel->id }}?view=chapter&chapter={{ $chapter->id }}"
                            @class(['is-active' => $mode === 'chapter' && $selectedChapterId === $chapter->id])
                        >{{ $chapter->title ?: 'Capítulo sin título' }}</a>
                    </li>
                @endforeach
            </ol>

            @if ($isOwner)
                <form method="POST" action="/workspace/novels/{{ $novel->id }}/chapters" class="inline-form">
                    @csrf
                    <label for="chapter-title">Nuevo capítulo</label>
                    <div class="field-row">
                        <input id="chapter-title" name="title" type="text" required maxlength="200" placeholder="Título del capítulo">
                        <button class="button button-secondary" type="submit">Crear capítulo</button>
                    </div>
                </form>
            @endif
        </aside>

        <div class="editor-sheet" data-novel-id="{{ $novel->id }}" data-is-owner="{{ $isOwner ? 'true' : 'false' }}">
            @forelse ($chapters as $chapter)
                @php($alternative = $chapter->alternatives->first())
                @php($canEdit = in_array((int) $chapter->id, $editableChapterIds, true))
                <section
                    class="editor-chapter"
                    id="chapter-{{ $chapter->id }}"
                    data-chapter-id="{{ $chapter->id }}"
                    data-read-only="{{ $canEdit ? 'false' : 'true' }}"
                >
                    <h2 class="chapter-title">{{ $chapter->title ?: 'Capítulo sin título' }}</h2>

                    @if (! $alternative || $alternative->scenes->isEmpty())
                        <p class="muted">Sin escenas todavía.</p>
                        @if ($alternative && $isOwner)
                            <form method="POST" action="/workspace/alternatives/{{ $alternative->id }}/scenes">
                                @csrf
                                <button class="button button-secondary" type="submit">Añadir escena</button>
                            </form>
                        @endif
                    @else
                        @foreach ($alternative->scenes as $scene)
                            <article class="scene" id="scene-{{ $scene->id }}" data-scene-id="{{ $scene->id }}" data-revision="{{ $scene->revision }}">
                                @if ($scene->title)
                                    <h3 class="scene-title">{{ $scene->title }}</h3>
                                @endif
                                @if ($isOwner)
                                    <a class="muted" href="/workspace/scenes/{{ $scene->id }}/revisions">Historial</a>
                                @endif
                                <div
                                    class="scene-editor"
                                    contenteditable="{{ $canEdit ? 'true' : 'false' }}"
                                    data-requires-lease="{{ ! $isOwner && $canEdit ? 'true' : 'false' }}"
                                    role="textbox"
                                    aria-multiline="true"
                                    aria-label="Escena {{ $scene->title ?: $loop->iteration }}"
                                >{!! $documentHtml->render(is_array($scene->content) ? $scene->content : []) !!}</div>
                            </article>
                        @endforeach

                        @if ($isOwner)
                            <form method="POST" action="/workspace/alternatives/{{ $alternative->id }}/scenes">
                                @csrf
                                <button class="button button-secondary" type="submit">Añadir escena</button>
                            </form>
                        @endif
                    @endif
                </section>
            @empty
                <div class="empty-state">
                    <h2>Todavía no hay capítulos</h2>
                    <p>Crea un capítulo para empezar a escribir.</p>
                </div>
            @endforelse

            @if ($hasMore)
                <nav class="editor-more" aria-label="Más capítulos">
                    <a class="button button-secondary" href="/workspace/novels/{{ $novel->id }}?view=manuscript&cursor={{ $nextCursor }}">
                        Cargar más capítulos
                    </a>
                </nav>
            @endif
        </div>
        </div>

        <aside id="editor-context" class="editor-context" aria-label="Contexto">
            <p class="kicker">Contexto</p>
            <h2>{{ $novel->title }}</h2>
            <p class="muted">{{ $novel->universe?->name ?? 'Universo' }}</p>
            <dl class="context-list">
                <div><dt>Capítulos</dt><dd>{{ $chapterList->count() }}</dd></div>
                <div><dt>Escenas visibles</dt><dd>{{ $chapters->sum(fn ($chapter) => $chapter->alternatives->sum(fn ($alternative) => $alternative->scenes->count())) }}</dd></div>
                <div><dt>Vista</dt><dd>{{ $mode === 'chapter' ? 'Capítulo' : 'Manuscrito' }}</dd></div>
                <div><dt>Acceso</dt><dd>{{ $isOwner ? 'Autoría' : (empty($editableChapterIds) ? 'Lectura' : 'Edición') }}</dd></div>
            </dl>
            @if ($isOwner)
                <h3>Accesos</h3>
                <ul class="context-links">
                    <li><a href="/workspace/novels/{{ $novel->id }}/planning">Planificación</a></li>
                    <li><a href="/workspace/novels/{{ $novel->id }}/search">Buscar en la novela</a></li>
                    <li><a href="/workspace/novels/{{ $novel->id }}/comments">Revisar comentarios</a></li>
                    <li><a href="/workspace/novels/{{ $novel->id }}/snapshots">Historial y lectores</a></li>
                    <li><a href="/workspace/novels/{{ $novel->id }}/book">Libro y exportación</a></li>
                </ul>
            @endif
            <p class="muted">Abre fichas y planificación desde los accesos.</p>
        </aside>
    </div>
@endsection
