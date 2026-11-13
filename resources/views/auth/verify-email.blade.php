@extends('layouts.site')

@section('title', 'Verifica tu correo')
@section('main_class', 'auth-layout')

@section('content')
    <section class="auth-intro" aria-label="Verificación del correo">
        <p class="landing-kicker">Seguridad de la cuenta</p>
        <h2>Un paso más para abrir tu espacio.</h2>
        <p>Confirma el enlace que enviamos a tu correo antes de entrar al contenido privado.</p>
    </section>

    <section class="auth-panel" aria-labelledby="verify-title">
        <h1 id="verify-title">Verifica tu correo</h1>
        <p class="auth-description">
            Hemos enviado un enlace de verificación a <strong>{{ auth()->user()->email }}</strong>.
            Revisa también la carpeta de correo no deseado.
        </p>

        @if (session('status'))
            <p class="form-alert" role="status">{{ session('status') }}</p>
        @endif

        <form class="auth-form" method="POST" action="/account/verify-email/resend">
            @csrf
            <button class="button button-primary" type="submit">Reenviar enlace</button>
        </form>

        <form class="auth-form" method="POST" action="/logout">
            @csrf
            <button class="button button-secondary" type="submit">Cerrar sesión</button>
        </form>
    </section>
@endsection
