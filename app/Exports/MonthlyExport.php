<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MonthlyExport implements WithMultipleSheets
{
    private $year;
    private $month;
    private $pawnshop_id;
    public function __construct($year, $month, $pawnshop_id)
    {
        $this->year = $year;
        $this->month = $month;
        $this->pawnshop_id = $pawnshop_id;
    }

    public function sheets(): array
    {
        return [
            'Sheet1' => new MonthlySheet1Export($this->year,$this->month,$this->pawnshop_id),
            'Sheet2' => new MonthlySheet2Export(),
            'Sheet3' => new MonthlySheet3Export($this->year, $this->month, $this->pawnshop_id),
        ];
    }
}
