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
            'PackageInfo'  => new PackageInfoSheet($this->startDate, $this->endDate),
            'Debtor'       => new DebtorSheet($this->contracts),
            'Interrelated' => new InterrelatedSheet($this->contracts),
            'Owner'        => new OwnerSheet($this->contracts),
            'Credit'       => new CreditSheet($this->contracts),
            'Collateral'   => new CollateralSheet($this->contracts),
            'Guarantor'    => new OwnerSheet($this->contracts),
        ];
    }
}
