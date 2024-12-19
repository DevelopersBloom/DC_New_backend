<?php

namespace App\Http\Controllers;

use App\Exports\MonthlyExport;
use App\Exports\MonthlySheet3Export;
use App\Exports\QuarterExport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ExcelController extends Controller
{
    public function downloadMonthlyExport(Request $request){
        $year = $request->year;
        $month = $request->month;
        $pawnshop_id = $request->pawnshop_id;
        $lastDayOfMonth = Carbon::create($year,$month,1)->endOfMonth()->format('d.m.Y');
        $file_name = $lastDayOfMonth.' ամսական.xlsx';
        return Excel::download(new MonthlyExport($year,$month,$pawnshop_id), $file_name);
    }
    public function downloadQuarterExport(){
        $file_name = 'test.xlsx';
        return Excel::download(new QuarterExport(), $file_name);
    }
}
