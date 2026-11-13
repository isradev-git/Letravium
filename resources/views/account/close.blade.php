@extends('layouts.workspace')

@section('title', 'Cerrar cuenta')
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Cuenta</p>
            <h1>Cerrar cuenta</h1>
            <p class="muted">Descarga una copia antes de cerrar. El cierre revoca tu acceso, pero no borra automáticamente tus universos.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/account/sessions">Sesiones y dispositivos</a>
            <a class="button button-secondary" href="/account/marketing">Comunicaciones comerciales</a>
            <a class="button button-secondary" href="/workspace">Volver a la biblioteca</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">No se pudo cerrar la cuenta.</p>
    @endif

    <div class="universe-list">
        <section class="universe-card" aria-labelledby="before-title">
            <h2 id="before-title">Antes de cerrar</h2>
            <ul>
                <li>Descarga una copia completa de cada universo en <a href="/workspace/backups">Copias de seguridad</a>.</li>
                <li>Puedes descargar un resumen de los datos de tu cuenta en <a href="/account/export?download=1">JSON</a>.</li>
                <li>El cierre revoca tus sesiones, retira tu acceso creativo y retira tus consentimientos comerciales.</li>
                <li>Tus universos, novelas y fichas propias no se borran automáticamente; quedan sujetos a la política de conservación.</li>
                <li>Las contribuciones a obras de otras personas se conservan con la atribución necesaria.</li>
                <li>Las copias operativas y los respaldos pueden mantener datos durante el plazo publicado; no se promete una desaparición instantánea.</li>
            </ul>
        </section>

        <section class="universe-card" aria-labelledby="confirm-title">
            <h2 id="confirm-title">Confirmar el cierre</h2>
            <p class="muted">Por seguridad se pedirá tu contraseña y tu segundo factor si la confirmación reciente ha caducado.</p>

            <form method="POST" action="/account/close?format=html" class="inline-form">
                @csrf
                <label for="confirmation">Escribe tu correo o tu usuario para confirmar</label>
                <div class="field-row">
                    <input id="confirmation" name="confirmation" type="text" required maxlength="255" autocomplete="off" value="{{ old('confirmation') }}">
                    <button class="button button-danger" type="submit">Cerrar mi cuenta</button>
                </div>
                @error('confirmation')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </form>
        </section>
    </div>
@endsection
