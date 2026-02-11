<?php

namespace App\Exports\Acra;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class CollateralSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    protected $contracts;
    public function __construct($contracts) { $this->contracts = $contracts; }

    public function title(): string { return 'Collateral'; }

    public function collection() {
        return $this->contracts->flatMap->items;
    }

    public function headings(): array {
        return ['Վարկի ներք. Իդենտ. Համար', 'Գրավի արժեք', 'Արժույթի կոդը', 'Գրավի առարկան', 'Նշումներ'];
    }

    public function map($item): array {
        $contract = $item->contracts->first();
        return [
            $contract ? $contract->num : '',
            $item->provided_amount, // rated դաշտը
            '1', // AMD
            $item->subcategory . ' ' . $item->description,
            ''
        ];
    }
}
