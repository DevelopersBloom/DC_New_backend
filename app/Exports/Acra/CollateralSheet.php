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

    public function collection() {
        return $this->contracts->flatMap->items;
    }

    public function map($item): array {
        // Քանի որ մեկ item-ը կարող է կապված լինել մի քանի contract-ի հետ
        $contract = $item->contracts->first();
        return [
            $contract ? $contract->num : '',
            $item->provided_amount, // rated դաշտը ձեր սերվիսում
            '1', // AMD
            $item->description . ' ' . $item->model,
            '' // Նշումներ (դատարկ)
        ];
    }

    public function headings(): array {
        return ['Վարկի ներք. Իդենտ. Համար', 'Գրավի արժեք', 'Արժույթի կոդը', 'Գրավի առարկան', 'Նշումներ'];
    }

    public function title(): string { return 'Collateral'; }
}
