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
        'address',
        'passport',
        'email',
        'bank',
        'card',
        'phone1',
        'phone2',
        'pawnshop_id',
        'comment',
        'dob',
        'passport_given',
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
