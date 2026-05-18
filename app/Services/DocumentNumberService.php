<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public function next(): int
    {
        return DB::transaction(function () {
            $max = DB::table('transactions')
                ->selectRaw('MAX(document_number) as max_document_number')
                ->lockForUpdate()
                ->value('max_document_number');

            return ((int) ($max ?? 0)) + 1;
        });
    }
}
