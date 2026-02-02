<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController
{
    public function getLogs(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);

        $logs = ActivityLog::with('user:id,name,surname')
            ->when($request->action, fn($q) => $q->where('action', $request->action))
            ->when($request->model, fn($q) => $q->where('model', $request->model))
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'logs' => ActivityLogResource::collection($logs),
        ]);
    }
}
