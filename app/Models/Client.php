<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'surname',
        'middle_name',
        'passport_series',
        'passport_validity',
        'passport_issued',
        'date_of_birth',
        'email',
        'phone',
        'additional_phone',
        'country',
        'city',
        'street',
        'building',
        'bank_name',
        'account_number',
        'card_number',
        'iban',

    ];

    public function contracts(){
        return $this->hasMany(Contract::class);
    }

    public function completedContracts(){
        return $this->contracts()->where('status','completed');
    }

    public function activeContracts(){
        return $this->contracts()->where('status','initial');
    }

    public function files(){
        return $this->morphMany(File::class,'fileable');
    }
}
