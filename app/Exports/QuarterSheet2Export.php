<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class QuarterSheet2Export implements FromView
{
    public function view(): View
    {
        return view('excel.quarter_sheet2');
    }
}
