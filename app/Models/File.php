<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class File extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_type',
        'fileable_id',
        'fileable_type',
        'name',
        'original_name',
        'type',
        'doc_type',
        'path',
    ];

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }
}
