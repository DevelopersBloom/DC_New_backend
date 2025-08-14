<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DealAction extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = [
        'deal_id',
        'actionable_id',
        'actionable_type',
        'amount',
        'type',
        'description',
        'created_by',
        'updated_by',
        'date',
        'history',
    ];
    protected $casts = [
        'date' => 'datetime',
        'history' => 'array',
    ];
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->created_by = auth()->id();
            $model->updated_by = auth()->id();
        });
        static::updating(function ($model) {
            $model->updated_by = auth()->id();
        });
    }

    public function actionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class,'created_by');
    }
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class,'updated_by');
    }
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class,'deal_id');
    }

}
