@extends('layouts.site')

@section('title', 'Crear una cuenta')
@section('main_class', 'auth-layout')

@section('content')
    <section class="auth-intro" aria-label="Tu espacio de escritura">
        <p class="landing-kicker">Tu espacio de escritura</p>
        <h2>Empieza tu historia.</h2>
        <p>Crea una cuenta privada, confirma tu correo y protege el acceso con tu Authenticator.</p>
    </section>

    <section class="auth-panel" aria-labelledby="auth-title">
        <h1 id="auth-title">Crear una cuenta</h1>
        <p class="auth-description">Tu cuenta es gratuita. Después confirmaremos tu correo y tu código de seguridad.</p>

        @if ($errors->any())
            <p class="form-alert" role="alert">No se pudo crear la cuenta. Revisa los datos e inténtalo de nuevo.</p>
        @endif

        <form class="auth-form" method="POST" action="/register">
            @csrf
            <input type="hidden" name="invite_token" value="{{ old('invite_token', $inviteToken) }}">

            <div class="field">
                <label for="name">Nombre</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" autocomplete="name" required>
                @error('name')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="email">Correo</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
                @error('email')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password">Contraseña</label>
                <input id="password" type="password" name="password" autocomplete="new-password" required>
                @error('password')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Repite la contraseña</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
            </div>

            @error('invite_token')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <button class="button button-primary" type="submit">Crear cuenta</button>
        </form>

        <p class="auth-switch">¿Ya tienes cuenta? <a href="/login">Entrar</a></p>
    </section>
@endsection
