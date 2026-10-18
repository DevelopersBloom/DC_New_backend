<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Blocks every route for a user who still has the one-time password an admin
 * issued, except the route that changes it. Must run after jwt.auth.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if ($user && $user->must_change_password && !$request->routeIs('admin.change-password')) {
            return response()->json([
                'code'    => 'password_change_required',
                'message' => 'Շարունակելու համար փոխեք ժամանակավոր գաղտնաբառը։',
            ], 403);
        }

        return $next($request);
    }
}
