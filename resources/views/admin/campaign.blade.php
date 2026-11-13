@extends('layouts.admin')

@section('title', $campaign->subject)

@section('content')
    @php($labels = ['draft' => 'Borrador', 'running' => 'En curso', 'paused' => 'Pausada', 'done' => 'Terminada'])
    @php($stateLabels = ['pending' => 'Pendiente', 'sending' => 'Enviando', 'accepted' => 'Aceptado', 'failed' => 'Fallido', 'uncertain' => 'Incierto'])

    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Campaña</p>
            <h1>{{ $campaign->subject }}</h1>
            <p class="muted">{{ $campaign->from_name }} &lt;{{ $campaign->from_email }}&gt; · {{ $labels[$campaign->status] ?? $campaign->status }}</p>
        </div>
        <div class="library-actions">
            <a class="button button-secondary" href="/admin/campaigns">Volver a campañas</a>
        </div>
    </div>

    @if (session('status'))
        <p class="form-notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="form-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <div class="universe-list">
        <section class="universe-card" aria-labelledby="summary-title">
            <h2 id="summary-title">Resumen</h2>
            <p>Destinatarios autorizados: <strong>{{ $eligible }}</strong></p>
            <p class="muted">Pendientes de confirmar: {{ $excluded['pending'] }} · Retirados: {{ $excluded['withdrawn'] }}</p>
            <ul class="chapter-list">
                @foreach ($states as $state => $total)
                    <li><span class="muted">{{ $stateLabels[$state] ?? $state }}:</span> {{ $total }}</li>
                @endforeach
            </ul>

            <div class="novel-actions">
                @if ($campaign->status !== 'done')
                    <form method="POST" action="/admin/campaigns/{{ $campaign->id }}/start?format=html">
                        @csrf
                        <button class="button button-primary" type="submit">{{ $campaign->status === 'draft' ? 'Iniciar primer lote' : 'Reanudar lote' }}</button>
                    </form>
                @endif
                @if (in_array($campaign->status, ['draft', 'running'], true))
                    <form method="POST" action="/admin/campaigns/{{ $campaign->id }}/pause?format=html">
                        @csrf
                        <button class="button button-secondary" type="submit">Pausar</button>
                    </form>
                @endif
            </div>
        </section>

        <section class="universe-card" aria-labelledby="recipients-title">
            <h2 id="recipients-title">Destinatarios</h2>
            @if ($recipients->isEmpty())
                <p class="muted">El primer lote prepara los destinatarios desde los consentimientos activos.</p>
            @else
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th scope="col">Correo</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Error</th>
                                <th scope="col">Enviado</th>
                                <th scope="col">Reconciliar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recipients as $recipient)
                                <tr>
                                    <td>{{ $recipient->email }}</td>
                                    <td>{{ $stateLabels[$recipient->state] ?? $recipient->state }}</td>
                                    <td>{{ $recipient->error ?: '—' }}</td>
                                    <td>{{ $recipient->sent_at?->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td>
                                        @if (in_array($recipient->state, ['sending', 'uncertain'], true))
                                            <form method="POST" action="/admin/campaigns/{{ $campaign->id }}/recipients/{{ $recipient->id }}/reconcile?format=html" class="inline-form">
                                                @csrf
                                                <label for="state-{{ $recipient->id }}">Reconciliar</label>
                                                <div class="field-row">
                                                    <select id="state-{{ $recipient->id }}" name="state" required>
                                                        <option value="accepted">Aceptado</option>
                                                        <option value="failed">Fallido</option>
                                                        <option value="uncertain">Incierto</option>
                                                    </select>
                                                    <button class="button button-secondary" type="submit">Guardar</button>
                                                </div>
                                            </form>
                                        @else
                                            <span class="muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
