<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Evaluator extends Model
{
    use HasFactory;
    protected $fillable = [
        'full_name',
        'pawnshop_id'
    ];

    public function pawnshop(){
        return $this->belongsTo(Pawnshop::class);
    }

    public function contracts(){
        return $this->hasMany(Contract::class);
    }
}
