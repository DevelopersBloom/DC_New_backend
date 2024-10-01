<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'title',
        'pawnshop_id',
        'rate',
        'penalty',
        'lump_rate'
    ];


    public function contracts(){
        return $this->hasMany(Contract::class);
    }
}
