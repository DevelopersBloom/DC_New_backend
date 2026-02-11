<?php

namespace App\Exports\Acra;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class CreditSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    protected $contracts;

    public function __construct($contracts) { $this->contracts = $contracts; }

    public function collection() { return $this->contracts; }

    public function title(): string { return 'Credit'; }

    public function headings(): array {
        return [
            'Վարկառուի ներք. Իդենտ. Համար', 'Վարկի ներք. Իդենտ. Համար', 'Վարկի տրամ. Ամս.',
            'Վարկի մարմ. Ամս.', 'Վարկի փաստ. Մարմ. Ամս.', 'Վարկի տեսակ', 'Պայմ. Վարկի գումար',
            'Փաստ. Տրամ. Գումար', 'Փաստ. Մարվ. Գումար', 'Փաստ. մնաց.', 'Ժամկետանց մնաց.',
            'Ժամկետանց տոկոս', 'Ժամկետանց դառնալու ամսաթիվ', 'Արժույթի կոդ', 'Վարկի կարգավիճակ',
            'Վարկի տոկոսադրույք'
        ];
    }

    public function map($contract): array {
        return [
            $contract->client_id,
            $contract->num,
            $contract->date,
            $contract->deadline,
            $contract->closed_at ?? '', // Առկայության դեպքում
            $contract->category_id, // Կոդավորումն ըստ ACRA-ի
            $contract->provided_amount,
            $contract->provided_amount,
            ($contract->provided_amount - $contract->mother), // Մարված գումար
            $contract->mother, // Փաստացի մնացորդ
            $contract->overdue_amount ?? 0,
            $contract->penalty_amount ?? 0,
            $contract->delay_date ?? '',
            '1', // AMD
            $contract->status == 'initial' ? '1' : '2',
            $contract->interest_rate,
        ];
    }
}
