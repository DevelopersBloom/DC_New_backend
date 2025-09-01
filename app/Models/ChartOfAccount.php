<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    use HasFactory;
    protected $fillable = [
        'code',
        'name',
        'type',
//        'is_accumulative',
//        'currency_id',
//        'is_partner_accounting',
        'parent_id',
        'description'
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
    public function childrenRecursive()
    {
        return $this->children()
            ->select('id', 'parent_id', 'code', 'name','type')
            ->with('childrenRecursive');
    }
    public function scopeCodeContains($query, string $term)
    {
        $safe = str_replace(['%', '_'], ['\%','\_'], trim($term));

        return $query->where('code', 'like', "{$safe}%");
    }

}
