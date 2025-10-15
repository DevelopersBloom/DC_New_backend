<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientClassification extends Model
{
    use HasFactory;

    protected $table = 'clients_classification';

    protected $fillable = [
        'title',
        'name',
        'order',
        'reserve_percent',
        'risk_weight',
    ];
}
