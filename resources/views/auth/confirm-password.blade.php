@extends('layouts.site')

@section('title', 'Confirmar acceso')
@section('main_class', 'auth-layout')

@section('content')
    <section class="auth-intro" aria-label="Acceso protegido">
        <p class="landing-kicker">Acceso protegido</p>
        <h2>Confirma que sigues siendo tú.</h2>
        <p>Las acciones críticas requieren tu contraseña y un código actual de tu Authenticator.</p>
    </section>

    <section class="auth-panel" aria-labelledby="auth-title">
        <h1 id="auth-title">Confirmar acceso</h1>
        <p class="auth-description">Introduce tu contraseña y el código actual de tu aplicación Authenticator o un código de recuperación.</p>

        @if ($errors->any())
            <p class="form-alert" role="alert">No se pudo confirmar. Revisa la contraseña y el código.</p>
        @endif

        <form class="auth-form" method="POST" action="/account/confirm-password">
            @csrf
            <div class="field">
                <label for="password">Contraseña</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
            </div>
            <div class="field">
                <label for="code">Código de seguridad</label>
                <input id="code" name="code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required>
            </div>
            <button class="button button-primary" type="submit">Confirmar</button>
        </form>
    </section>
@endsection
