<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;
    protected $fillable = [
        'contract_id',
        'category_id',
        'subcategory',
        'model',
        'weight',
        'clear_weight',
        'hallmark',
        'car_make',
        'manufacture',
        'power',
        'license_plate',
        'color',
        'registration',
        'identification',
        'ownership',
        'issued_by',
        'date_of_issuance',
        'description',
        'sn',
        'imei'
    ];
    public function category(){
        return $this->belongsTo(Category::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }
}
