<?php

namespace App\Exports;

use App\Models\Contract;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class DailyExportSheet1 implements FromCollection, WithEvents
{
    protected array $generalHeaderRows = [];
    protected array $paymentHeaderRows = [];

    public function collection(): Collection
    {
        $pawnshop_id = auth()->user()->pawnshop_id;

        $contracts = Contract::with(['client', 'items', 'category', 'payments' => function ($q) {
            $q->orderBy('date');
        }])->where('pawnshop_id',$pawnshop_id)->get();

        $exportData = collect();
        $rowIndex = 1;

        foreach ($contracts as $contract) {
            $this->generalHeaderRows[] = $rowIndex;
            $exportData->push([
                'Կնքման Ամսաթիվ',
                'Պայմանագրի №',
                'Գրավատան համար',
                'Անուն/Ազգանուն/Հայրանուն',
                'Անձնագրի սերիա',
                'Անձնագրի վավերականություն',
                'Տրված',
                'Երկիր',
                'Քաղաք',
                'Փողոց/շենք',
                'Ծննդյան օր',
                'Մեյլ',
                'Հեռ․ Համար',
                'Բանկ',
                'Քարտի համար',
                'Հաշվեհամար',
                'Գնահատված',
                'Տրամադրված',
                'Տոկոսադրույք (%)',
                'Տուգանք(%)',
                'Միանվագ(%)',
                'Օրեր',
                'Փակման ամսաթիվ',
                'Նկարագրություն',
                'Կատեգորիա',
            ]);
            $rowIndex++;

            $exportData->push([
                $contract->date,
                $contract->num,
                $contract->pawnshop_id,
                $contract->client->name . ' ' . $contract->client->surname . ' ' . $contract->client->middle_name,
                $contract->client->passport_series,
                $contract->client->passport_validity,
                $contract->client->passport_issued,
                $contract->client->country,
                $contract->client->city,
                $contract->street,
                $contract->client->date_of_birth,
                $contract->client->email,
                $contract->client->phone && $contract->client->additional_phone
                    ? $contract->client->phone . ', ' . $contract->client->additional_phone
                    : ($contract->client->phone ?? $contract->client->additional_phone),
                $contract->client->bank_name,
                $contract->client->card_number,
                $contract->client->account_number,
                $contract->estimated_amount,
                $contract->provided_amount,
                $contract->interest_rate,
                $contract->penalty,
                $contract->lump_rate,
                $contract->deadline_days,
                $contract->deadline,
                $contract->description,
                $contract->category->title,
            ]);
            $rowIndex++;

            $this->paymentHeaderRows[] = $rowIndex;
            $exportData->push([
                '',
                'Տեսակ',
                'Վճարման Ամսաթիվ',
                'Վճարված Գումար',
                'Չվճարված գումար',
                'Կարգավիճակ',
                'ՄԳ',
            ]);
            $rowIndex++;

            foreach ($contract->payments as $payment) {
                $status = $payment->status == 'completed' ? 'Վճարված' : 'Չվճարված';
                $type = match ($payment->type) {
                    'regular' => 'Հերթական',
                    'partial' => 'Մասնակի',
                    'full'    => 'Ամբողջական',
                    'penalty' => 'Տուգանք',
                    default   => '',
                };
                $amount = $payment->type == 'regular' ? $payment->amount : '';

                $exportData->push([
                    '',
                    $type,
                    $payment->date,
                    $payment->paid,
                    $amount,
                    $status,
                    $payment->mother,
                ]);
                $rowIndex++;
            }

            $exportData->push(['']);
            $rowIndex++;
        }

        return $exportData;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {

                foreach (range('A', 'Y') as $column) {
                    $event->sheet->getDelegate()->getColumnDimension($column)->setAutoSize(true);
                }

                foreach ($this->generalHeaderRows as $row) {
                    $event->sheet->getDelegate()->getStyle("A{$row}:Y{$row}")->applyFromArray([
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['argb' => 'FFE5E5E5'],
                        ],
                        'font' => ['bold' => true],
                    ]);
                }

                foreach ($this->paymentHeaderRows as $row) {
                    $event->sheet->getDelegate()->getStyle("B{$row}:G{$row}")->applyFromArray([
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['argb' => 'FFCCE5FF'],
                        ],
                        'font' => ['bold' => true],
                    ]);
                }
            },
        ];
    }}
