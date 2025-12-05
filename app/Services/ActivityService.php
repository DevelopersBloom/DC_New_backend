<?php

namespace App\Services;

use App\Models\ActivityLog;

class ActivityService
{
    public function log($action, $description = null, $model = null, $modelId = null)
    {
        ActivityLog::create([
            'user_id'    => auth()->id(),
            'action'     => $action,
            'description'=> $description,
            'model'      => $model,
            'model_id'   => $modelId,
            'ip_address' => request()->ip(),
        ]);
    }
}
