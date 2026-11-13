@extends('layouts.workspace')

@section('title', 'Fuentes privadas')
@section('main_class', 'site-main library-main')

@section('content')
    @if (! $fonts->isEmpty())
        <style>
            @foreach ($fonts as $font)
                @font-face { font-family: 'priv-{{ $font->id }}'; src: url('/workspace/fonts/{{ $font->id }}/view'); font-display: swap; }
            @endforeach
        </style>
    @endif

    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Biblioteca</p>
            <h1>Fuentes privadas</h1>
            <p class="muted">Solo tú puedes usar estas familias. Se emplean en el editor y en la exportación del libro; una fuente en uso no se elimina.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace">Volver a la biblioteca</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="universe-card" aria-labelledby="font-upload-title">
        <h2 id="font-upload-title">Importar una fuente</h2>
        <p class="muted">TTF u OTF estático, hasta 10 MiB. No se admiten colecciones ni fuentes web.</p>
        <form method="POST" action="/workspace/fonts?format=html" class="inline-form" enctype="multipart/form-data">
            @csrf
            <div class="field-row">
                <div class="field">
                    <label for="font-family">Familia</label>
                    <input id="font-family" name="family" type="text" required maxlength="160" value="{{ old('family') }}">
                </div>
                <div class="field">
                    <label for="font-variant">Variante</label>
                    <input id="font-variant" name="variant" type="text" required maxlength="80" value="{{ old('variant', 'regular') }}">
                </div>
            </div>
            <div class="field">
                <label for="font-license">Licencia y declaración de uso</label>
                <input id="font-license" name="license" type="text" maxlength="500" value="{{ old('license') }}" placeholder="SIL OFL 1.1">
            </div>
            <div class="field">
                <label for="font-file">Archivo TTF/OTF</label>
                <input id="font-file" name="file" type="file" accept=".ttf,.otf,font/ttf,font/otf" required>
            </div>
            <button class="button button-primary" type="submit">Importar fuente</button>
        </form>
    </section>

    <h2>Fuentes importadas</h2>
    @if ($fonts->isEmpty())
        <section class="empty-state">
            <h2>Todavía no hay fuentes</h2>
            <p>Importa una familia con su licencia para usarla en tus novelas.</p>
        </section>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table font-table">
                <thead>
                    <tr>
                        <th scope="col">Familia</th>
                        <th scope="col">Muestra</th>
                        <th scope="col">Cobertura</th>
                        <th scope="col">Formato</th>
                        <th scope="col">Tamaño</th>
                        <th scope="col">Licencia</th>
                        <th scope="col">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fonts as $font)
                        <tr>
                            <td data-label="Familia">
                                <strong>{{ $font->family }}</strong><br>
                                <span class="muted">{{ $font->variant }}</span>
                            </td>
                            <td data-label="Muestra">
                                <span class="font-sample" style="font-family: 'priv-{{ $font->id }}', serif" lang="es">{{ $fontSample }}</span>
                            </td>
                            <td data-label="Cobertura">
                                @php($check = $fontChecks[$font->id] ?? ['status' => 'unavailable', 'missing' => []])
                                @if ($check['status'] === 'complete')
                                    <span class="coverage-ok">Cobertura completa</span>
                                @elseif ($check['status'] === 'partial')
                                    <span class="coverage-missing">Faltan: {{ implode(' ', $check['missing']) }}</span>
                                @else
                                    <span class="muted">No verificable</span>
                                @endif
                            </td>
                            <td data-label="Formato">{{ strtoupper((string) ($font->metadata['format'] ?? '—')) }}</td>
                            <td data-label="Tamaño">{{ number_format($font->bytes / 1024, 1, ',', '.') }} KiB</td>
                            <td data-label="Licencia">{{ $font->license ?: 'Sin declarar' }}</td>
                            <td data-label="Acción">
                                <form method="POST" action="/workspace/fonts/{{ $font->id }}?format=html">
                                    @csrf
                                    @method('DELETE')
                                    <button class="button button-secondary" type="submit">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
