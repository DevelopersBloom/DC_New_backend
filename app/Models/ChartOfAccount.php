<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    use HasFactory;
    private const TYPE_ACTIVE      = 'active';
    private const TYPE_PASSIVE     = 'passive';
    private const TYPE_EQUITY      = 'equity';
    private const TYPE_INCOME      = 'income';
    private const TYPE_EXPENSE     = 'expense';
    private const TYPE_OFF_BALANCE = 'off_balance';

    protected $fillable = [
        'code',
        'name',
        'type',
//        'is_accumulative',
//        'currency_id',
//        'is_partner_accounting',
        'parent_id',
        'description',
        'income_expense',
    ];
    protected $appends = ['parent_code'];
    protected $hidden = ['parent'];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
    public function getParentCodeAttribute()
    {
        return $this->parent?->code;
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
    public function isDebitNature(): bool
    {
        return in_array($this->type,['active','expense','off_balance'],true);
    }
    public function isCreditNature(): bool
    {
        return in_array($this->type,['passive','equity','income'],true);
    }
}
