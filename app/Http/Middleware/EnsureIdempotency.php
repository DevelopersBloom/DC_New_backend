<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;

class EnsureIdempotency
{
    public function handle(Request $request, Closure $next, string $route): mixed
    {
        $key = $request->header('Idempotency-Key');

        if (!$key) {
            return response()->json(['message' => 'Idempotency-Key header is required'], 400);
        }

        $userId = auth()->id();
        $record = IdempotencyKey::where('key', $key)->first();

        if ($record) {
            if ($record->route !== $route || $record->user_id !== $userId) {
                return response()->json(['message' => 'Idempotency key does not match the request context'], 422);
            }

            if ($record->response !== null) {
                return response()->json($record->response, $record->status_code);
            }

            if ($record->locked_at !== null && $record->locked_at->gt(now()->subSeconds(60))) {
                return response()->json(['message' => 'A request with this key is already being processed'], 409);
            }

            // Lock expired (crash recovery) — reset and reprocess
            $record->update(['locked_at' => now()]);
        } else {
            IdempotencyKey::create([
                'key'       => $key,
                'route'     => $route,
                'user_id'   => $userId,
                'locked_at' => now(),
            ]);
        }

        $response = $next($request);

        // Clear lock on server error so the client can retry with the same key
        if ($response->getStatusCode() >= 500) {
            IdempotencyKey::where('key', $key)->update(['locked_at' => null]);
        }

        return $response;
    }
}
