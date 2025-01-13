<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use \Illuminate\Contracts\View\View;

class QuarterExport implements  WithMultipleSheets
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
            'Sheet1' => new QuarterSheet1Export($this->year,$this->month,$this->pawnshop_id),
            'Sheet2' => new QuarterSheet2Export($this->year,$this->month,$this->pawnshop_id),
        ];
    }
}
