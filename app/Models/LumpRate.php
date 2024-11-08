<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LumpRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'min_amount',
        'max_amount',
        'lump_rate',
    ];


    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public static function getRateByCategoryAndAmount(int $category_id, float $amount)
    {
        return self::where('category_id', $category_id)
            ->where('min_amount','<=',$amount)
            ->where('max_amount','>=',$amount)
            ->whereNull('deleted_at')
            ->first();
    }
}
