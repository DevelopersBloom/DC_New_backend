<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;
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
    ];
}
