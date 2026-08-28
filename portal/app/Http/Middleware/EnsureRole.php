<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Guard a route by one or more roles, e.g. middleware('role:admin') or
     * middleware('role:manager,admin').
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! in_array($user->role, $roles, true)) {
            abort(403, 'You do not have permission for this action.');
        }

        return $next($request);
    }
}
