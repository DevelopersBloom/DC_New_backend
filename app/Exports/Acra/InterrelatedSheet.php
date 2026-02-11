<?php

namespace App\Exports\Acra;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Illuminate\Support\Collection;

class InterrelatedSheet implements FromCollection, WithHeadings, WithTitle
{
    protected $contracts;
    public function __construct($contracts) { $this->contracts = $contracts; }

    public function title(): string { return 'Interrelated'; }

    public function headings(): array {
        return [
            'Վարկառուի ներքին իդենտիֆիկացման համար',
            'Կապակցված վարկառուի ներքին իդենտիֆիկացման համար',
            'Նշումներ'
        ];
    }

    public function collection() {
        // Եթե չկան կապակցվածներ, վերադարձնում ենք դատարկ զանգված
        return new Collection([]);
    }
}
