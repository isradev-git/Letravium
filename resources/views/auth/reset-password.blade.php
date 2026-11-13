@extends('layouts.site')

@section('title', 'Nueva contraseña')
@section('main_class', 'auth-layout')

@section('content')
    <section class="auth-intro" aria-label="Cambiar contraseña">
        <p class="landing-kicker">Tu espacio de escritura</p>
        <h2>Elige una clave nueva.</h2>
        <p>Tu código Authenticator sigue siendo el mismo después del cambio.</p>
    </section>

    <section class="auth-panel" aria-labelledby="reset-title">
        <h1 id="reset-title">Nueva contraseña</h1>
        <p class="auth-description">Usa al menos 12 caracteres. Después entrarás con la nueva contraseña y tu segundo factor.</p>

        @if ($errors->any())
            <p class="form-alert" role="alert">No se pudo cambiar la contraseña. Pide un enlace nuevo si ha caducado.</p>
        @endif

        <form class="auth-form" method="POST" action="/reset-password">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="field">
                <label for="email">Correo</label>
                <input id="email" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required>
            </div>
            <div class="field">
                <label for="password">Nueva contraseña</label>
                <input id="password" type="password" name="password" autocomplete="new-password" minlength="12" required>
            </div>
            <div class="field">
                <label for="password_confirmation">Repite la nueva contraseña</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required>
            </div>
            <button class="button button-primary" type="submit">Cambiar contraseña</button>
        </form>

        <p class="auth-switch"><a href="/forgot-password">Pedir otro enlace</a></p>
    </section>
@endsection
