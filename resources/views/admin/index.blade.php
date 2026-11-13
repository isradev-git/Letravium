@extends('layouts.admin')

@section('title', 'Resumen')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Administración de plataforma</p>
            <h1>Resumen</h1>
            <p class="muted">Solo cuentas y operaciones del servicio. El contenido creativo no es accesible desde aquí.</p>
        </div>
    </div>

    <ul class="admin-stats">
        <li class="admin-stat">
            <span class="admin-stat-value">{{ $stats['accounts'] }}</span>
            <span class="admin-stat-label">Cuentas</span>
        </li>
        <li class="admin-stat">
            <span class="admin-stat-value">{{ $stats['writers'] }}</span>
            <span class="admin-stat-label">Escritores</span>
        </li>
        <li class="admin-stat">
            <span class="admin-stat-value">{{ $stats['suspended'] }}</span>
            <span class="admin-stat-label">Suspendidas</span>
        </li>
        <li class="admin-stat">
            <span class="admin-stat-value">{{ $stats['admins'] }}</span>
            <span class="admin-stat-label">Administración</span>
        </li>
    </ul>

    <section class="admin-card">
        <div class="admin-card-heading">
            <h2>Última actividad</h2>
            <a href="/admin/audit">Ver toda la auditoría</a>
        </div>
        @include('admin._audit', ['events' => $recent, 'emails' => $emails])
    </section>
@endsection
