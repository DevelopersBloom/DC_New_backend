<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PawnshopConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'cashboxes_calculated',
        'online_cashbox_set',
        'pawnshop_id',
        'orders_set'
    ];
}
