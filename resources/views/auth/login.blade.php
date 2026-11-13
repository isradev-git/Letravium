@extends('layouts.site')

@section('title', 'Entrar')
@section('main_class', 'auth-layout')

@section('content')
    <section class="auth-intro" aria-label="Tu espacio de escritura">
        <p class="landing-kicker">Tu espacio de escritura</p>
        <h2>Vuelve a tu historia.</h2>
        <p>Retoma el capítulo, consulta tu universo y sigue escribiendo desde donde lo dejaste.</p>
    </section>

    <section class="auth-panel" aria-labelledby="auth-title">
        <h1 id="auth-title">Entrar</h1>
        <p class="auth-description">Accede con tu correo y contraseña. Después confirmaremos tu código de seguridad.</p>

        @if (session('status'))
            <p class="form-notice" role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <p class="form-alert" role="alert">No se pudo entrar. Revisa los datos e inténtalo de nuevo.</p>
        @endif

        <form class="auth-form" method="POST" action="/login">
            @csrf
            <div class="field">
                <label for="email">Correo o usuario</label>
                <input id="email" type="text" name="email" value="{{ old('email') }}" autocomplete="username" required>
            </div>
            <div class="field">
                <label for="password">Contraseña</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required>
            </div>
            <div class="checkbox-field">
                <input id="remember" type="checkbox" name="remember" value="1" @checked(old('remember'))>
                <label for="remember">Recordarme en este equipo</label>
            </div>
            <button class="button button-primary" type="submit">Entrar</button>
        </form>

        <p class="auth-switch">¿No tienes cuenta? <a href="/register">Crear una cuenta</a></p>
        <p class="auth-switch"><a href="/forgot-password">¿Olvidaste tu contraseña?</a></p>
    </section>
@endsection
