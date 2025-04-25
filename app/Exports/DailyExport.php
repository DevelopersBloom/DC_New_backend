<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class DailyExport implements WithMultipleSheets
{

    public function sheets(): array
    {
        return [
            'Contracts' => new DailyExportSheet1(),
            'Deals'     => new DailyExportSheet2()

        ];
    }
}
