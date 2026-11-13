@php
    $labels = [
        'account.quota' => 'Cuota modificada',
        'account.suspend' => 'Cuenta suspendida',
        'account.restore' => 'Cuenta restaurada',
    ];
@endphp

@if ($events->isEmpty())
    <p class="muted">Sin actividad registrada.</p>
@else
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th scope="col">Fecha</th>
                    <th scope="col">Acción</th>
                    <th scope="col">Actor</th>
                    <th scope="col">Sujeto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($events as $event)
                    <tr>
                        <td>{{ $event->created_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $labels[$event->action] ?? $event->action }}</td>
                        <td>{{ $emails[$event->actor_id] ?? '#'.$event->actor_id }}</td>
                        <td>{{ $event->subject_id ? ($emails[$event->subject_id] ?? '#'.$event->subject_id) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
