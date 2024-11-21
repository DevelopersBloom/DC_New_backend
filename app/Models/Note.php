<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property int         $contract_id
 * @property string      $title
 * @property string      $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 */
class Note extends Model
{
    protected $fillable = [
        'contract_id',
        'title',
        'description'
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
