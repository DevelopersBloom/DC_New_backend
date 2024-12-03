<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Category extends Model
{
    use HasFactory,SoftDeletes;
    protected $fillable = [
        'name',
        'title',
        'pawnshop_id',
        'duration',
    ];
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
    public function lumpRates(): HasMany
    {
        return $this->hasMany(LumpRate::class);
    }

    public function categoryRates(): HasMany
    {
        return $this->hasMany(CategoryRate::class);
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(Subcategory::class);
    }
}
