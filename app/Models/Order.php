<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory,SoftDeletes;
    const NDM_PURPOSE = 'Ներգրավված դրամական միջոցներ';
    const EXPENSE_PURPOSE = 'Ելքագրել ծախս';
    const EXPENSE_FILTER = "expense";
    CONST NDM_FILTER = "ndm";
    CONST REFUND_LUMP = 'Միանվագ վճարի ետվերադարձ';
    CONST REFUND_LUMP_FILTER = "refund_lump";
    CONST FULL_FILTER = 'full';
    CONST PARTIAL_FILTER = 'partial';
    CONST REGULAR_FILTER = 'regular';
    CONST EXECUTE_FILTER = 'execute';
    const ONE_TIME_PAYMENT_FILTER = 'one_time_payment';
    CONST MOTHER_PAYMENT = 'mother_payment';
    const EXECUTION_PURPOSE = 'Իրացում';
    protected $fillable = [
        'contract_id',
        'type',
        'title',
        'pawnshop_id',
        'order',
        'amount',
        'rep_id',
        'date',
        'client_name',
        'purpose',
        'receiver',
        'cashbox',
        'num',
        'cash',
        'filter',
    ];
    public function pawnshop()
    {
        return $this->belongsTo(Pawnshop::class);
    }
}
