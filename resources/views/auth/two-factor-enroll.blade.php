@extends('layouts.site')

@section('title', 'Activar doble factor')
@section('main_class', 'auth-layout')

@section('content')
    <section class="auth-intro" aria-label="Seguridad de la cuenta">
        <p class="landing-kicker">Seguridad de la cuenta</p>
        <h2>Protege lo que escribes.</h2>
        <p>Configura el segundo factor antes de abrir tu espacio privado.</p>
    </section>

    <section class="auth-panel" aria-labelledby="auth-title">
        <h1 id="auth-title">Activar doble factor</h1>
        <p class="auth-description">Añade esta clave a tu aplicación Authenticator y confirma el código que te muestre.</p>
        <div class="setup-key">
            <span>Clave de configuración</span>
            <code>{{ $secret }}</code>
        </div>

        @if ($errors->any())
            <p class="form-alert" role="alert">No se pudo confirmar el código. Comprueba la clave y vuelve a intentarlo.</p>
        @endif

        <form class="auth-form" method="POST" action="/account/two-factor">
            @csrf
            <div class="field">
                <label for="code">Código de la app</label>
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
            </div>
            <button class="button button-primary" type="submit">Confirmar doble factor</button>
        </form>
    </section>
@endsection
