<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'title',
        'pawnshop_id',
    ];
    public function contracts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Contract::class);
    }
    public function lumpRates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LumpRate::class);
    }

    public function categoryRates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CategoryRate::class);
    }
}
