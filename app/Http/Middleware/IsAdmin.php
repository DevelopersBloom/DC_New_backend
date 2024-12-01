<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param  Closure(Request): (Response|RedirectResponse)  $next
     * @return JsonResponse
     */
//    public function handle(Request $request, Closure $next): JsonResponse
//    {
//        if (Auth::check() &&  Auth::user()->role == 'admin') {
//            return $next($request);
//        }
//
//        return response()->json(['error' => 'Unauthorized'], 403);
//    }
    public function handle(Request $request, Closure $next)
    {
        if (auth()->user() && auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $response = $next($request);

        if ($response instanceof BinaryFileResponse) {
            return $response;
        }

        return $response instanceof JsonResponse ? $response : response()->json($response);
    }
}
