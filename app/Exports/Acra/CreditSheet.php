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

    public function title(): string { return 'Credit'; }

    public function collection() { return $this->contracts; }

    public function headings(): array {
        return [
            'Վարկառուի ներք. Իդենտ. Համար', 'Վարկի ներք. Իդենտ. Համար', 'Վարկի տրամ. Ամս.',
            'Վարկի մարմ. Ամս.', 'Վարկի փաստ. Մարմ. Ամս.', 'Վարկի տեսակ', 'Պայմ. Վարկի գումար',
            'Փաստ. Տրամ. Գումար', 'Փաստ. Մարվ. Գումար', 'Փաստ. մնաց.', 'Ժամկետանց մնաց.',
            'Ժամկետանց տոկոս', 'Ժամկետանց դառնալու ամսաթիվ', 'Արժույթի կոդ', 'Վարկ. Ռիսկի դաս',
            'Վարկի կարգավիճակ', 'Վարկի տոկոսադրույք', 'Վարկի օգտ. Ոլորտ', 'Վարկի օգտ. Վայր',
            'Վարկի պայման. Վերան. Թիվ', 'Վարկի պայմանագրի ամսաթիվ', 'Վարկային ռեգիստրի կոդ',
            'Ժամկետանց օրերի քանակ', 'Վարկի վերջին դասակարգման ամսաթիվ', 'Նշումներ'
        ];
    }

    public function map($contract): array {
        return [
            $contract->client_id,
            $contract->num,
            $contract->date,
            $contract->deadline,
            $contract->closed_at ?? '',
            $this->mapCategoryToAcra($contract->category_id),
            $contract->provided_amount,
            $contract->provided_amount,
            ($contract->provided_amount - $contract->mother),
            $contract->mother,
            $contract->overdue_balance ?? 0,
            $contract->overdue_interest ?? 0,
            $contract->overdue_date ?? '',
            '1', // AMD
            '1', // Ստանդարտ դաս
            $contract->status == 'initial' ? '1' : '2',
            $contract->interest_rate,
            '8', // Սպառողական ոլորտ
            '1', // Երևան (կամ ըստ պահանջի)
            '',
            $contract->date,
            '',
            $contract->delay_days ?? 0,
            now()->format('Y-m-d'),
            ''
        ];
    }

    private function mapCategoryToAcra($id) {
        $map = [1 => '11', 2 => '12', 3 => '13', 4 => '15'];
        return $map[$id] ?? '15';
    }
}
