<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LoanApplicationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_application_id',
        'category_id',
        'subcategory',
        'model',
        'weight',
        'clear_weight',
        'hallmark',
        'car_make',
        'manufacture',
        'power',
        'license_plate',
        'color',
        'registration',
        'identification',
        'ownership',
        'description',
    ];

    protected $casts = [
        'weight'       => 'float',
        'clear_weight' => 'float',
        'manufacture'  => 'integer',
    ];

    public function loanApplication(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function realEstate(): HasOne
    {
        return $this->hasOne(LoanApplicationItemRealEstate::class);
    }
}
