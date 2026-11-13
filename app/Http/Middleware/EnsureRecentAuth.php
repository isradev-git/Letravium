<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRecentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $reauthAt = (int) $request->session()->get('reauth_at', 0);

        if ($reauthAt === 0 || now()->timestamp - $reauthAt > 5 * 60) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Confirma tu contraseña y tu factor.'], 423);
            }

            if ($return = $this->safeReturn($request)) {
                $request->session()->put('reauth_return', $return);
            }

            return redirect('/account/confirm-password');
        }

        return $next($request);
    }

    private function safeReturn(Request $request): ?string
    {
        $referer = $request->headers->get('referer');

        if (! $referer) {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);
        $path = parse_url($referer, PHP_URL_PATH);

        if (! is_string($host) || ! is_string($path) || $host !== $request->getHost()) {
            return null;
        }

        if ($path === '/account/confirm-password' || str_starts_with($path, '//')) {
            return null;
        }

        return $path === '' ? '/' : $path;
    }
}
