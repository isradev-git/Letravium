@extends('layouts.workspace')

@section('title', 'Documentos de '.$universe->name)
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Investigación</p>
            <h1>Documentos</h1>
            <p class="muted">Adjuntos privados de «{{ $universe->name }}». PDF, DOCX y TXT con validación de contenido; los TXT se pueden leer aquí.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace/universes/{{ $universe->id }}/cards">Fichas</a>
            <a class="button button-secondary" href="/workspace">Volver a la biblioteca</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="universe-card" aria-labelledby="upload-title">
        <h2 id="upload-title">Añadir un documento</h2>
        <form method="POST" action="/workspace/assets?format=html" class="inline-form" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="kind" value="attachment">
            <input type="hidden" name="universe_id" value="{{ $universe->id }}">
            <div class="field">
                <label for="document-novel">Destino</label>
                <select id="document-novel" name="novel_id">
                    <option value="">Todo el universo</option>
                    @foreach ($novels as $novel)
                        <option value="{{ $novel->id }}">{{ $novel->title }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="document-file">Archivo PDF, DOCX o TXT (máx. 20 MiB)</label>
                <input id="document-file" name="file" type="file" accept=".pdf,.docx,.txt,application/pdf,text/plain,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
            </div>
            <button class="button button-primary" type="submit">Añadir documento</button>
        </form>
    </section>

    <h2>Documentos del universo</h2>
    @if ($assets->isEmpty())
        <section class="empty-state">
            <h2>Todavía no hay documentos</h2>
            <p>Añade un PDF, DOCX o TXT para tu documentación privada.</p>
        </section>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Documento</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Tamaño</th>
                        <th scope="col">Destino</th>
                        <th scope="col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assets as $asset)
                        @php($size = $asset->bytes >= 1048576 ? number_format($asset->bytes / 1048576, 1, ',', '.').' MiB' : number_format($asset->bytes / 1024, 1, ',', '.').' KiB')
                        <tr>
                            <td>
                                <strong>{{ $asset->original_name }}</strong>
                                @if (isset($previews[$asset->id]))
                                    <details class="document-preview-wrap">
                                        <summary>Leer</summary>
                                        <pre class="document-preview">{{ $previews[$asset->id] }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td>{{ strtoupper((string) ($asset->metadata['format'] ?? '—')) }}</td>
                            <td>{{ $size }}</td>
                            <td>{{ $asset->novel?->title ?: 'Todo el universo' }}</td>
                            <td>
                                <div class="novel-actions">
                                    <a class="button button-secondary" href="/workspace/assets/{{ $asset->id }}/download">Descargar</a>
                                    <form method="POST" action="/workspace/assets/{{ $asset->id }}?format=html">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button button-secondary" type="submit">Eliminar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
