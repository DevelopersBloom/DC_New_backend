<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable=[
        'collected',
        'rate',
        'penalty',
        'penalty_amount',
        'one_time_payment',
        'executed',
        'deadline',
        'status',
        'date',
        'close_date',
        'pawnshop_id',
        'category_id',
        'evaluator_id',
        'client_id',
        'user_id',
        'dob',
        'info',
        'extended',
        'ADB_ID',
        'passport_given',
    ];

    public function payments(){
        return $this->hasMany(Payment::class, 'contract_id');
    }

    public function user(){
        return $this->belongsTo(User::class);
    }

    public function client(){
        return $this->belongsTo(Client::class);
    }

    public function history(){
        return $this->hasMany(History::class);
    }

    public function category(){
        return $this->belongsTo(Category::class);
    }

    public function evaluator(){
        return $this->belongsTo(Evaluator::class);
    }

    public function files(){
        return $this->morphMany(File::class,'fileable');
    }

    public function discounts(){
        return $this->hasMany(Discount::class);
    }

    public function pawnshop(){
        return $this->belongsTo(Pawnshop::class);
    }

    public function items(){
        return $this->hasMany(Item::class);
    }

}
