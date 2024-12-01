<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HistoryType extends Model
{
    use HasFactory, SoftDeletes;
    const REGULAR_PAYMENT = 'regular_payment';
    const PARTIAL_PAYMENT = 'partial_payment';
    CONST FULL_PAYMENT = 'full_payment';
    CONST PENALTY_PAYMENT = 'penalty_payment';
    const MOTHER_PAYMENT = 'mother_payment';
    const ONE_TIME_PAYMENT = 'one_time_payment';
    protected $fillable = [
        'name', 'title'
    ];
    public function history(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(History::class);
    }
}
