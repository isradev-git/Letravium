@extends('layouts.workspace')

@section('title', 'Sesiones y dispositivos')
@section('main_class', 'site-main library-main')

@section('content')
    <header class="library-hero">
        <div class="library-intro">
            <p class="kicker">Cuenta</p>
            <h1>Sesiones y dispositivos</h1>
            <p class="muted">Revisa dónde está abierta tu cuenta y revoca lo que no reconozcas.</p>
        </div>
        <div class="library-hero-actions">
            <a class="button button-secondary" href="/account/marketing">Comunicaciones comerciales</a>
            <a class="button button-secondary" href="/account/close">Cerrar cuenta</a>
            <a class="button button-secondary" href="/workspace">Volver a la biblioteca</a>
        </div>
    </header>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    <ul class="universe-list">
        @foreach ($sessions as $session)
            <li class="universe-card">
                <div class="universe-heading">
                    <h2>{{ $session['current'] ? 'Esta sesión' : 'Sesión' }}</h2>
                    <span class="muted">{{ $session['ip_address'] ?: 'IP desconocida' }}</span>
                </div>
                <p class="muted">{{ $session['user_agent'] ?: 'Navegador no identificado' }}</p>
                <p class="muted">Última actividad: {{ date('Y-m-d H:i', (int) $session['last_activity']) }}</p>

                @if (! $session['current'])
                    <button type="button" class="button button-small button-danger-quiet" data-dialog-open="dialog-revoke-session-{{ $loop->index }}">Revocar sesión</button>
                @endif
            </li>
        @endforeach
    </ul>

    @foreach ($sessions as $session)
        @if (! $session['current'])
            <x-dialog id="dialog-revoke-session-{{ $loop->index }}" title="Revocar sesión" description="Ese dispositivo tendrá que volver a iniciar sesión y confirmar el segundo factor." danger>
                <form method="POST" action="/account/sessions/{{ $session['id'] }}">
                    @csrf
                    @method('DELETE')
                    <div class="app-dialog-actions">
                        <button type="button" class="button button-secondary" data-dialog-safe data-dialog-close>Cancelar</button>
                        <button type="submit" class="button button-danger">Revocar sesión</button>
                    </div>
                </form>
            </x-dialog>
        @endif
    @endforeach
@endsection
