<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivateAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $request->expectsJson()
                ? response()->json(['message' => 'No autenticado.'], 401)
                : redirect('/login');
        }

        if ($user->suspended_at) {
            return $this->deny($request, 'Cuenta suspendida.', false, 403);
        }

        if ($user->closed_at) {
            return $this->deny($request, 'Cuenta cerrada.', false, 403);
        }

        if (! $user->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Correo sin verificar.'], 403)
                : redirect($user->totp_confirmed_at ? '/account/verify-email' : '/account/two-factor');
        }

        if (! $user->totp_confirmed_at) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Doble factor sin confirmar.'], 403)
                : redirect('/account/two-factor');
        }

        $session = $request->session();
        $verifiedAt = (int) $session->get('totp_verified_at', 0);
        $startedAt = (int) $session->get('session_started_at', $verifiedAt);
        $lastActivity = (int) $session->get('last_activity_at', $verifiedAt);
        $totalExpired = $verifiedAt === 0 || $startedAt === 0 || now()->timestamp - $startedAt > 12 * 3600;
        $idleExpired = $verifiedAt === 0 || now()->timestamp - $lastActivity > 30 * 60;

        if ($totalExpired) {
            return $this->deny($request, 'Sesión bloqueada.');
        }

        if ($idleExpired) {
            $remembered = (bool) $session->get('auth_remember', false) || Auth::viaRemember();

            return $this->deny($request, 'Sesión bloqueada.', $remembered, 401, $startedAt);
        }

        $session->put('last_activity_at', now()->timestamp);

        return $next($request);
    }

    private function deny(Request $request, string $message, bool $remembered = false, int $status = 401, ?int $startedAt = null): Response
    {
        if ($request->expectsJson()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => $message], $status);
        }

        $user = $request->user();
        $target = $request->isMethod('GET') ? $request->fullUrl() : null;

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($remembered && $user) {
            $request->session()->put('pending_user_id', $user->id);
            $request->session()->put('pending_remember', true);
            $request->session()->put('pending_started_at', $startedAt);

            if ($target) {
                $request->session()->put('url.intended', $target);
            }

            return redirect('/two-factor/challenge');
        }

        return redirect('/login');
    }
}
