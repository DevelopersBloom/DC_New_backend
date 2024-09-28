<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;
    protected $fillable = [
        'contract_id',
        'weight',
        'clear_weight',
        'category_id',
        'type',
        'description'
    ];
    public function category(){
        return $this->belongsTo(Category::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }
}
