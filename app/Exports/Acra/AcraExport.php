<?php

namespace App\Exports\Acra;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AcraExport implements WithMultipleSheets
{
    protected $contracts;
    protected $startDate;
    protected $endDate;

    public function __construct($contracts, $startDate, $endDate)
    {
        $this->contracts = $contracts;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function sheets(): array
    {
        return [
            new PackageInfoSheet($this->startDate, $this->endDate),
//            new DebtorSheet($this->contracts),
            new CreditSheet($this->contracts),
            new CollateralSheet($this->contracts),
//            new GuarantorSheet($this->contracts),
        ];
    }
}
