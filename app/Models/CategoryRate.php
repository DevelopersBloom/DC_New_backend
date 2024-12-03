<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CategoryRate extends Model
{
    protected $fillable = ['category_id', 'interest_rate', 'penalty','lump_rate', 'min_amount', 'max_amount'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public static function getRateByCategoryAndAmount($category_id, $amount)
    {
        return self::where('category_id', $category_id)
            ->where(function($query) use ($amount) {
                $query->where('min_amount', '<=', $amount)
                    ->orWhereNull('min_amount');
            })
            ->where(function($query) use ($amount) {
                $query->where('max_amount', '>=', $amount)
                    ->orWhereNull('max_amount');
            })
            ->first();
    }
}
