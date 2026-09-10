<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanApplicationItemRealEstate extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_application_item_id',
        'certificate_number',
        'certificate_password',
        'cadastral_code',
        'area_sqm',
        'is_joint',
    ];

    protected $casts = [
        'area_sqm' => 'float',
        'is_joint' => 'boolean',
    ];

    public function loanApplicationItem(): BelongsTo
    {
        return $this->belongsTo(LoanApplicationItem::class);
    }
}
