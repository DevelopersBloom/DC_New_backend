<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassificationHistory extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = [
        'client_id',
        'classification_id',
        'risk_weight',
        'reserve_percent',
        'comment',
        'actionable_type',
        'actionable_id',
        'meta',
        'date',
    ];

    protected $casts = [
        'meta' => 'array',
        'date' => 'datetime',
        'reserve_percent' => 'decimal:2',
        'reserve_amount' => 'decimal:2',
        'total_reserve_amount' => 'decimal:2',
        'provided_amount' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(ClientClassification::class);
    }

    public function actionable(): MorphTo
    {
        return $this->morphTo();
    }
}
