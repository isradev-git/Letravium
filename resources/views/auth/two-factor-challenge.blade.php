@extends('layouts.site')

@section('title', 'Segundo factor')
@section('main_class', 'auth-layout')

@section('content')
    <section class="auth-intro" aria-label="Acceso protegido">
        <p class="landing-kicker">Acceso protegido</p>
        <h2>Tu trabajo, solo tuyo.</h2>
        <p>El segundo factor protege tus manuscritos, notas y fichas privadas.</p>
    </section>

    <section class="auth-panel" aria-labelledby="auth-title">
        <h1 id="auth-title">Segundo factor</h1>
        <p class="auth-description">Introduce el código de tu aplicación Authenticator o un código de recuperación.</p>

        @if ($errors->any())
            <p class="form-alert" role="alert">No se pudo comprobar el código. Revísalo e inténtalo de nuevo.</p>
        @endif

        <form class="auth-form" method="POST" action="/two-factor/challenge">
            @csrf
            <div class="field">
                <label for="code">Código de seguridad</label>
                <input id="code" name="code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required autofocus>
            </div>
            <button class="button button-primary" type="submit">Comprobar código</button>
        </form>
    </section>
@endsection
