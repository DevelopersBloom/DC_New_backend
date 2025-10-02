<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NdmRepaymentDetail extends Model
{
    use HasFactory;

    protected $table = 'ndm_repayment_details';

    protected $fillable = [
        'interest_unused_part',
        'penalty_overdue_principal',
        'penalty_overdue_interest',
        'tax_total',
        'tax_from_interest',
        'tax_from_penalty_pr',
        'tax_from_penalty_int',
        'total_amount',
    ];

    protected $casts = [
        'interest_unused_part'      => 'decimal:2',
        'penalty_overdue_principal' => 'decimal:2',
        'penalty_overdue_interest'  => 'decimal:2',
        'tax_total'                 => 'decimal:2',
        'tax_from_interest'         => 'decimal:2',
        'tax_from_penalty_pr'       => 'decimal:2',
        'tax_from_penalty_int'      => 'decimal:2',
        'total_amount'              => 'decimal:2',
    ];

    public function journal(): HasOne
    {
        return $this->hasOne(DocumentJournal::class, 'ndm_repayment_id');
    }
}
