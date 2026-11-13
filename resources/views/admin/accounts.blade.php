@extends('layouts.admin')

@section('title', 'Cuentas')

@section('content')
    <div class="library-header">
        <div class="library-intro">
            <p class="kicker">Administración de plataforma</p>
            <h1>Cuentas</h1>
            <p class="muted">Datos de cuenta, cuotas y estado. Nunca se muestran secretos TOTP ni contenido creativo.</p>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th scope="col">Cuenta</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Cuota (bytes)</th>
                    <th scope="col">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($accounts as $account)
                    <tr>
                        <td>
                            <strong>{{ $account->name }}</strong><br>
                            <span class="muted">{{ $account->email }}</span>
                        </td>
                        <td>
                            @if ($account->suspended_at)
                                <span class="badge badge-danger">Suspendida</span>
                            @else
                                <span class="badge">Activa</span>
                            @endif
                            @if (! $account->email_verified_at)
                                <span class="badge">Correo sin verificar</span>
                            @endif
                            @if ($account->is_platform_admin)
                                <span class="badge">Administración</span>
                            @endif
                        </td>
                        <td>
                            <form method="POST" action="/admin/accounts/{{ $account->id }}/quota" class="quota-form">
                                @csrf
                                @method('PATCH')
                                <label class="quota-label" for="quota-{{ $account->id }}">Cuota en bytes</label>
                                <div class="field-row">
                                    <input
                                        id="quota-{{ $account->id }}"
                                        name="bytes_limit"
                                        type="number"
                                        min="0"
                                        step="1"
                                        required
                                        value="{{ $quotas->get($account->id)?->bytes_limit ?? \App\Models\AccountQuota::DEFAULT_BYTES }}"
                                    >
                                    <button class="button button-secondary" type="submit">Actualizar</button>
                                </div>
                            </form>
                        </td>
                        <td>
                            @if ($account->id === $currentUserId)
                                <span class="muted">Tu cuenta</span>
                            @elseif ($account->suspended_at)
                                <form method="POST" action="/admin/accounts/{{ $account->id }}/restore">
                                    @csrf
                                    @method('PATCH')
                                    <button class="button button-secondary" type="submit">Restaurar</button>
                                </form>
                            @else
                                <form method="POST" action="/admin/accounts/{{ $account->id }}/suspend">
                                    @csrf
                                    @method('PATCH')
                                    <button class="button button-secondary" type="submit">Suspender</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
