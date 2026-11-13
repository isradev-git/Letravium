@extends('layouts.workspace')

@section('title', 'Importar manuscrito')
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Manuscrito</p>
            <h1>Importar manuscrito</h1>
            <p class="muted">Sube un TXT o DOCX, revisa los capítulos propuestos y confirma. Nada se escribe hasta que decidas importar.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/workspace">Volver a la biblioteca</a>
        </div>
    </div>

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="/workspace/imports/preview?format=html" enctype="multipart/form-data" class="admin-card">
        @csrf
        <h2>Archivo y destino</h2>

        <div class="format-field">
            <label for="import-file">Archivo TXT o DOCX</label>
            <input id="import-file" name="file" type="file" accept=".txt,.docx" required>
            <p class="muted">TXT en UTF-8 hasta 10 MiB; DOCX hasta 20 MiB.</p>
        </div>

        <div class="format-field">
            <label for="import-mode">Destino</label>
            <select id="import-mode" name="destination[mode]">
                <option value="new_novel">Crear una novela nueva</option>
                <option value="append">Añadir capítulos a una novela</option>
            </select>
        </div>

        <div class="format-field">
            <label for="import-universe">Universo de la nueva novela</label>
            <select id="import-universe" name="destination[universe_id]">
                <option value="">Elige un universo</option>
                @foreach ($universes as $universe)
                    <option value="{{ $universe->id }}">{{ $universe->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="format-field">
            <label for="import-novel">Novela existente</label>
            <select id="import-novel" name="destination[novel_id]">
                <option value="">Elige una novela</option>
                @foreach ($universes as $universe)
                    @foreach ($universe->novels as $novel)
                        <option value="{{ $novel->id }}">{{ $novel->title }} · {{ $universe->name }}</option>
                    @endforeach
                @endforeach
            </select>
        </div>

        <button class="button button-primary" type="submit">Preparar vista previa</button>
    </form>
@endsection
