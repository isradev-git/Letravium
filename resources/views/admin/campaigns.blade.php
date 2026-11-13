@extends('layouts.admin')

@section('title', 'Campañas')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Administración de plataforma</p>
            <h1>Campañas</h1>
            <p class="muted">Envíos acotados solo a consentimientos comerciales activos. No se usan títulos, manuscritos ni datos creativos para segmentar.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="universe-card" aria-labelledby="new-campaign-title">
        <div class="universe-heading">
            <h2 id="new-campaign-title">Nueva campaña</h2>
        </div>
        <form method="POST" action="/admin/campaigns?format=html" class="inline-form">
            @csrf
            <div class="field">
                <label for="subject">Asunto</label>
                <input id="subject" name="subject" type="text" required maxlength="255" value="{{ old('subject') }}">
            </div>
            <div class="field">
                <label for="from_name">Nombre del remitente</label>
                <input id="from_name" name="from_name" type="text" required maxlength="120" value="{{ old('from_name') }}">
            </div>
            <div class="field">
                <label for="from_email">Correo del remitente</label>
                <input id="from_email" name="from_email" type="email" required maxlength="255" value="{{ old('from_email') }}">
            </div>
            <div class="field">
                <label for="body">Contenido</label>
                <textarea id="body" name="body" rows="5" required maxlength="100000">{{ old('body') }}</textarea>
            </div>
            <button class="button button-primary" type="submit">Guardar borrador</button>
        </form>
    </section>

    @if ($campaigns->isEmpty())
        <section class="empty-state">
            <h2>Todavía no hay campañas</h2>
            <p>Crea un borrador y revísalo antes de iniciar un lote.</p>
        </section>
    @else
        @php($labels = ['draft' => 'Borrador', 'running' => 'En curso', 'paused' => 'Pausada', 'done' => 'Terminada'])
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Campaña</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Creada</th>
                        <th scope="col">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($campaigns as $campaign)
                        <tr>
                            <td>
                                <strong>{{ $campaign->subject }}</strong><br>
                                <span class="muted">{{ $campaign->from_name }} &lt;{{ $campaign->from_email }}&gt;</span>
                            </td>
                            <td><span class="badge">{{ $labels[$campaign->status] ?? $campaign->status }}</span></td>
                            <td>{{ $campaign->created_at?->format('Y-m-d H:i') }}</td>
                            <td><a class="button button-secondary" href="/admin/campaigns/{{ $campaign->id }}">Abrir</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
