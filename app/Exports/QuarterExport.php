<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use \Illuminate\Contracts\View\View;

class QuarterExport implements  WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            'Sheet1' => new QuarterSheet1Export(),
            'Sheet2' => new QuarterSheet2Export(),
        ];
    }
}
