<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = ['key', 'route', 'user_id', 'status_code', 'response', 'locked_at'];

    protected $casts = [
        'response'   => 'array',
        'locked_at'  => 'datetime',
    ];
}
