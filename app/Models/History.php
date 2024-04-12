<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class History extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $fillable = [
        'amount',
        'type_id',
        'user_id',
        'date',
        'contract_id',
        'order_id'
    ];

    public function type(){
        return $this->belongsTo(HistoryType::class,'type_id');
    }

    public function user(){
        return $this->belongsTo(User::class);
    }

    public function order(){
        return $this->belongsTo(Order::class);
    }
}
