<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientClassification extends Model
{
    use HasFactory,SoftDeletes;
    const TYPE_PROBLEMATIC = 'problematic';
    const TYPE_RESPONSIBLE = 'responsible';

    protected $fillable = [
        'client_id',
        'type',
        'description',
        'note',
        'date'
    ];
    protected $casts = [
        'date' => 'date'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
