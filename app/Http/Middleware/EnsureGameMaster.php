<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGameMaster
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_gm) {
            abort(403, 'Only a game master can do that.');
        }

        return $next($request);
    }
}
