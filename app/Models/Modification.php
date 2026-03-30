<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Modification extends Model
{

    protected $fillable = [
        'subject_type',
        'subject_id',
        'modification_type',
        'field_code',
        'old_value',
        'new_value',
        'effective_date',
        'is_sent',
        'sent_at',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'is_sent' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

