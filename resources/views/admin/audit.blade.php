@extends('layouts.admin')

@section('title', 'Auditoría')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Administración de plataforma</p>
            <h1>Auditoría</h1>
            <p class="muted">Cambios de cuota, suspensiones y restauraciones con autor y fecha.</p>
        </div>
    </div>

    <section class="admin-card">
        @include('admin._audit', ['events' => $events, 'emails' => $emails])
    </section>
@endsection
