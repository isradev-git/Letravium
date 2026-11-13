<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCommunicationsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canManageCommunications()) {
            abort(403);
        }

        return $next($request);
    }
}
