<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pawnshop extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $fillable = [
        'city',
        'address',
        'license',
        'representative',
        'telephone',
        'phone1',
        'phone2',
        'email',
        'bank',
        'cashbox',
        'bank_cashbox',
        'funds',
        'worth',
        'given',
        'insurance',
        'order_in',
        'order_out',
        'bank_order_in',
        'bank_order_out',
        'card_account_number',
    ];


    public function contracts(){
        return $this->hasMany(Contract::class);
    }

    public function users(){
        return $this->hasMany(User::class);
    }
}
