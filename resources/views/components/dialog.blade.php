@props([
    'id',
    'title',
    'description' => null,
    'danger' => false,
    'open' => false,
    'fallback' => null,
])
<dialog
    id="{{ $id }}"
    class="app-dialog{{ $danger ? ' is-danger' : '' }}"
    data-dialog
    aria-labelledby="{{ $id }}-title"
    @if ($description) aria-describedby="{{ $id }}-description" @endif
    @if ($open) open @endif
>
    <div class="app-dialog-head">
        <div>
            <h2 id="{{ $id }}-title" class="app-dialog-title">{{ $title }}</h2>
            @if ($description)
                <p id="{{ $id }}-description" class="app-dialog-description">{{ $description }}</p>
            @endif
        </div>
        <button type="button" class="app-dialog-close" data-dialog-close aria-label="Cerrar">×</button>
    </div>

    <div class="app-dialog-body">
        {{ $slot }}
    </div>

</dialog>
@if ($fallback)
    <noscript>
        <p class="app-dialog-fallback"><a href="{{ $fallback }}">Continuar sin JavaScript</a></p>
    </noscript>
@endif
