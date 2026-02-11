<?php

namespace App\Exports\Acra;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class DebtorSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    protected $contracts;
    public function __construct($contracts) { $this->contracts = $contracts; }

    public function title(): string { return 'Debtor'; }

    public function collection() {
        return $this->contracts->map->client->unique('id');
    }

    public function headings(): array {
        return [
            'Վարկառուի ներք. իդենտ. համար', 'Վարկառուի իրավ. կարգ.', 'Անվանում',
            'Վարկառուի ՀՎՀՀ/Անձնագիր', 'Ֆիզ անձի ծննդյան ամսաթիվ', 'Ֆիզ անձի անձգրի տրմ. ամսթ.',
            'Ֆիզ անձի անձգիր տվող մ.կոդ', 'Սոցիալական քարտ', 'Սեռը', 'Ռեզ.', 'Սեփ. ձև',
            'Հասցե', 'Ոլորտ', 'Պետ. Ռեգ. Գրանցմ. Համար', 'Պետ. Ռեգ. Գրանցման ամսաթիվ',
            'Վարկ. Կազմակերպության տնօրեն', 'Տնօրենի անձնագիր', 'Նույնականացման քարտի համար',
            'Նույնականացման քարտի տրման ամսաթիվ', 'Նույնականացման քարտն ում կողմից է տրվել', 'Նշումներ'
        ];
    }

    public function map($client): array {
        return [
            $client->id,
            '1', // Ֆիզիկական անձ
            $client->name . ' ' . $client->surname,
            $client->passport,
            $client->birth_date ?? '',
            $client->passport_date ?? '',
            $client->passport_by ?? '',
            $client->ssn ?? '',
            $client->gender == 'male' ? '1' : '2',
            '1', // Ռեզիդենտ
            '10', // Մասնավոր սեփականություն
            $client->address ?? '',
            '8', // Ոլորտ (սպառողական)
            '', '', '', '', // Իրավաբանական անձանց դաշտեր
            $client->id_card ?? '',
            $client->id_card_date ?? '',
            $client->id_card_by ?? '',
            ''
        ];
    }
}
