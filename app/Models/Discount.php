<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;
    const PENDING = 'pending';
    const ACCEPTED = 'accepted';
    const REJECTED = 'rejected';

    protected $fillable = [
        'amount',
        'user_id',
        'contract_id',
        'status',
        'pawnshop_id'
    ];

    public function contract(){
        return $this->belongsTo(Contract::class);
    }
    public function pawnshop(){
        return $this->belongsTo(Pawnshop::class);
    }
    public function user(){
        return $this->belongsTo(User::class);
    }
}
