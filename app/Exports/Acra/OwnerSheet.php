<?php

namespace App\Exports\Acra;


use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class OwnerSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    protected $contracts;
    public function __construct($contracts) { $this->contracts = $contracts; }

    public function title(): string { return 'Owner'; }

    public function collection() {
        // Սովորաբար դատարկ է ֆիզ. անձանց դեպքում, բայց headings-ը պարտադիր է
        return new Collection([]);
    }

    public function headings(): array {
        return [
            'Վարկառուի ներք. Իդենտ. Համար', 'Մասնակցի ներքին իդենտ. Համար', 'Կարգավիճակ',
            'Անվանում (Ա.Ա.)', 'ՀՎՀՀ (Անձնագրի համար)', 'Ծննդ. Ամս.', 'Անձնագրի տրման ամսաթիվ',
            'Անձ. Տվող մարմին', 'Սոց. քարտ', 'Սեռ', 'Ռեզ.', 'Սեփ. Ձև', 'Հասցե', 'Գործուն. Ոլորտ',
            'Պետ. Ռեգ. Գրանցմ. Համար', 'Պետ. Ռեգ. Գրանցմ. Ամսաթիվ', 'Գործ. Տնօրենի Ա.Ա.',
            'Գործ. Տն. Անձնագրի համար', 'Նույնականացման քարտի համար', 'Նույնականացման քարտի տրման ամսաթիվ',
            'Նույնականացման քարտն ում կողմից է տրվել', 'Նշումներ'
        ];
    }

    public function map($owner): array { return []; }
}
