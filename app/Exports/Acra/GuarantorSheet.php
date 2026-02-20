<?php

namespace App\Exports\Acra;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class GuarantorSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    protected $contracts;
    public function __construct($contracts) { $this->contracts = $contracts; }

    public function title(): string { return 'Guarantor'; }

    public function collection() {
        $guarantorsData = collect();
        foreach ($this->contracts as $contract) {
            foreach ($contract->guarantors as $guarantor) {
                $guarantor->temp_contract_num = $contract->num;
                $guarantorsData->push($guarantor);
            }
        }
        return $guarantorsData;
    }

    public function headings(): array {
        return [
            'Վարկի ներք. Իդենտ. Համար', 'Երաշխավորի ներքին իդենտ. Համար', 'Կարգավիճակ',
            'Անվանում (Ա.Ա.)', 'ՀՎՀՀ (Անձնագրի համար)', 'Ծննդ. Ամս.', 'Անձնագրի տրման ամսաթիվ',
            'Անձ. Տվող մարմին', 'Սոց. քարտ', 'Սեռ', 'Ռեզ.', 'Սեփ. Ձև', 'Հասցե', 'Գործուն. Ոլորտ',
            'Պետ. Ռեգ. Գրանցմ. Համար', 'Պետ. Ռեգ. Գրանցմ. Ամսաթիվ', 'Գործ. Տնօրենի Ա.Ա.',
            'Գործ. Տն. Անձնագրի համար', 'Երաշխ. Գումար', 'Երաշխավորության արժույթի կոդ',
            'Նույնականացման քարտի համար', 'Նույնականացման քարտի տրման ամսաթիվ',
            'Նույնականացման քարտն ում կողմից է տրվել', 'Նշումներ'
        ];
    }

    public function map($guarantor): array {
        return [
            $guarantor->temp_contract_num,
            $guarantor->id,
            '1', // Ֆիզ. անձ
            $guarantor->name . ' ' . $guarantor->surname,
            $guarantor->passport,
            $guarantor->birth_date ?? '',
            $guarantor->passport_date ?? '',
            $guarantor->passport_by ?? '',
            $guarantor->ssn ?? '',
            $guarantor->gender == 'male' ? '1' : '2',
            '1', // Ռեզիդենտ
            '10', // Մասնավոր
            $guarantor->address ?? '',
            '8', // Ոլորտ
            '', '', '', '', // Իրավաբանականի դաշտեր
            '', // Երաշխավորության գումար (եթե բազայում չկա առանձին, թողնում ենք դատարկ)
            '1', // AMD
            $guarantor->id_card ?? '',
            $guarantor->id_card_date ?? '',
            $guarantor->id_card_by ?? '',
            ''
        ];
    }
}
