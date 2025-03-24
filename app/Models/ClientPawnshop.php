<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ClientPawnshop extends Model
{
    use HasFactory;
    protected $table = 'client_pawnshop';

    protected $fillable = [
        'client_id',
        'pawnshop_id',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function pawnshop(): BelongsTo
    {
        return $this->belongsTo(Pawnshop::class, 'pawnshop_id');
    }

}
