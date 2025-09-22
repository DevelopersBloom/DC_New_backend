<?php

namespace App\Traits;

use App\Models\DocumentJournal;

trait Journalable
{
    public static function bootJournalable(): void
    {
        static::created(function ($model) {
            if (method_exists($model, 'toJournalRow')) {
                $data = $model->toJournalRow();

                $data['user_id'] = $data['user_id'] ?? auth()->id();

                $data['journalable_type'] = get_class($model);
                $data['journalable_id']   = $model->getKey();

                DocumentJournal::create($data);
            }
        });
    }
}
