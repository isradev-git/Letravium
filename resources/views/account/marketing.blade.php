@extends('layouts.workspace')

@section('title', 'Comunicaciones comerciales')
@section('main_class', 'site-main library-main')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Cuenta</p>
            <h1>Comunicaciones comerciales</h1>
            <p class="muted">Es opcional, separado de los mensajes necesarios de tu cuenta y puedes retirarlo cuando quieras.</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/account/sessions">Sesiones y dispositivos</a>
            <a class="button button-secondary" href="/account/close">Cerrar cuenta</a>
            <a class="button button-secondary" href="/workspace">Volver a la biblioteca</a>
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="muted">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="muted">{{ $errors->first() }}</p>
    @endif

    <section class="universe-card">
        @php($status = $consent?->status ?? \App\Models\MarketingConsent::STATUS_NONE)
        <div class="universe-heading">
            <h2>Estado actual</h2>
            <span class="muted">
                @switch($status)
                    @case(\App\Models\MarketingConsent::STATUS_ACTIVE) Activo @break
                    @case(\App\Models\MarketingConsent::STATUS_PENDING) Pendiente de confirmar @break
                    @case(\App\Models\MarketingConsent::STATUS_WITHDRAWN) Retirado @break
                    @default No solicitado
                @endswitch
            </span>
        </div>

        @if ($status === \App\Models\MarketingConsent::STATUS_ACTIVE)
            <form method="POST" action="/account/marketing/withdraw" class="inline-form">
                @csrf
                <button class="button button-secondary" type="submit">Retirar consentimiento</button>
            </form>
        @elseif ($status === \App\Models\MarketingConsent::STATUS_PENDING && $consent)
            <form method="POST" action="/account/marketing/confirm" class="inline-form">
                @csrf
                <input type="hidden" name="token" value="{{ $consent->token }}">
                <button class="button button-primary" type="submit">Confirmar consentimiento</button>
            </form>
        @else
            <form method="POST" action="/account/marketing/request" class="inline-form">
                @csrf
                <button class="button button-primary" type="submit">Solicitar consentimiento</button>
            </form>
        @endif
    </section>
@endsection
