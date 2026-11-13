@extends('layouts.site')

@section('title', 'Recuperar contraseña')
@section('main_class', 'auth-layout')

@section('content')
    <section class="auth-intro" aria-label="Recuperar acceso">
        <p class="landing-kicker">Tu espacio de escritura</p>
        <h2>Tu historia te espera.</h2>
        <p>Restablecer la contraseña no cambia tu segundo factor ni tus manuscritos.</p>
    </section>

    <section class="auth-panel" aria-labelledby="forgot-title">
        <h1 id="forgot-title">Recuperar contraseña</h1>
        <p class="auth-description">Escribe el correo de tu cuenta. Si existe, enviaremos un enlace para elegir una nueva contraseña.</p>

        @if (session('status'))
            <p class="form-alert" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="form-alert" role="alert">Revisa el correo e inténtalo de nuevo.</p>
        @endif

        <form class="auth-form" method="POST" action="/forgot-password">
            @csrf
            <div class="field">
                <label for="email">Correo</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
            </div>
            <button class="button button-primary" type="submit">Enviar enlace</button>
        </form>

        <p class="auth-switch"><a href="/login">Volver a entrar</a></p>
    </section>
@endsection
