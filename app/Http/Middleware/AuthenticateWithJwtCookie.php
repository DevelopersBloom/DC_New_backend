<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthenticateWithJwtCookie
{
//    /**
//     * Handle an incoming request.
//     *
//     * @param \Illuminate\Http\Request $request
//     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse) $next
//     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
//     */
    public function handle($request, Closure $next)
    {
        try {
            $token = $request->cookie('token');
            if ($token) {
                JWTAuth::setToken($token)->authenticate();
            }
        } catch (Exception $e) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
