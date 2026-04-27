<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemRealEstate extends Model
{
    protected $fillable = [
        'item_id',
        'certificate_number',
        'certificate_password',
        'cadastral_code',
        'area_sqm',
        'appraiser_company',
        'appraisal_report_number',
        'appraisal_date',
        'appraised_value',
        'unified_reference_number',
        'unified_reference_password',
        'is_joint',
    ];

    protected $casts = [
        'appraisal_date' => 'date',
        'is_joint'       => 'boolean',
        'area_sqm'       => 'float',
        'appraised_value'=> 'float',
    ];

    public function item(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
