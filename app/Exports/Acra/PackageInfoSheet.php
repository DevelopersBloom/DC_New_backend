<?php

namespace App\Exports\Acra;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

class PackageInfoSheet implements FromArray, WithTitle
{
    protected $startDate;
    protected $endDate;

    public function __construct($start, $end) {
        $this->startDate = $start;
        $this->endDate = $end;
    }

    public function array(): array {
        return [
            ['SourceName', 'TMP'],
            ['StartDate', $this->startDate],
            ['EndDate', $this->endDate],
            ['CreatedDateTime', now()->format('Y-m-d H:i:s')],
            ['FileCount', '1'],
            ['FileNum', '1'],
        ];
    }

    public function title(): string { return 'PackageInfo'; }
}
