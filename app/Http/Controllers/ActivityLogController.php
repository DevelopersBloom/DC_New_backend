<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController
{
    public function getLogs(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 20);

        $query = ActivityLog::with('user:id,name,surname')
        ->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('model')) {
            $query->where('model', $request->model);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $logs = $query->paginate($perPage);

        $data = $logs->getCollection()->map(fn ($log) => [
            'id'          => $log->id,
            'user_id'     => $log->user_id,
            'user_name'   => $log->user->name ?? null,
            'user_surname'=> $log->user->surname ?? null,
            'action'      => $log->action,
            'description' => $log->description,
            'model'       => $log->model,
            'model_id'    => $log->model_id,
            'ip_address'  => $log->ip_address,
            'created_at'  => $log->created_at->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'logs' => [
                'data' => $data,
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ]
        ]);
    }

}
