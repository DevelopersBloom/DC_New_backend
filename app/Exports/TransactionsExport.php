<?php

namespace App\Exports;

use App\Models\Transaction;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionsExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithChunkReading
{
    protected $from;
    protected $to;

    public function __construct($from = null, $to = null)
    {
        $this->from = $from;
        $this->to = $to;
    }

    public function query()
    {
        $query = Transaction::select([
            'id',
            'date',
            'document_number',
            'document_type',
            'amount_amd',
            'amount_currency',
            'amount_currency_id',
            'debit_account_id',
            'credit_account_id',
            'user_id',
            'debit_currency_id',
            'credit_currency_id',
            'is_system',
            'debit_partner_id',
            'credit_partner_id',
        ])->with([
            'debitAccount:id,code,name',
            'debitCurrency:id,code',
            'creditAccount:id,code,name',
            'creditCurrency:id,code',
            'amountCurrencyRelation:id,code',
            'user:id,name,surname',
            'debitPartner:id,type,name,surname,company_name,tax_number,social_card_number',
            'creditPartner:id,type,name,surname,company_name,tax_number,social_card_number',
        ]);

        if ($this->from && $this->to) {
            $query->whereBetween('date', [$this->from, $this->to]);
        } elseif ($this->from) {
            $query->where('date', '>=', $this->from);
        } elseif ($this->to) {
            $query->where('date', '<=', $this->to);
        }

        return $query->orderBy('date', 'desc');
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function headings(): array
    {
        return [
            'Ամսաթիվ',
            'Փաստաթղթի համար',
            'Փաստաթղթի տեսակ',
            'Դեբետ Հաշիվ',
            'Դեբետ գործ․ կոդ',
            'Դեբետ գործ․ անվանում',
            'Դեբետ արժ․',
            'Կրեդիտ Հաշիվ',
            'Կրեդիտ Գործ․ կոդ',
            'Կրեդիտ Գործ․ անվանում',
            'Կրեդիտ արժ․',
            'Գումար դրամով',
            'Գումար արժ․Մեկնաբանություն',
            'Օգտագործող',
            'Համակարգչային',
        ];
    }

    public function map($t): array
    {
        return [
            $t->date->format('Y-m-d'),
            $t->document_number,
            $t->document_type,
            optional($t->debitAccount)->code,
            $t->debit_partner_code,
            $t->debit_partner_name,
            optional($t->debitCurrency)->code,
            optional($t->creditAccount)->code,
            $t->credit_partner_code,
            $t->credit_partner_name,
            optional($t->creditCurrency)->code,
            $t->amount_amd,
            '',
            optional($t->user)->name . ' ' . optional($t->user)->surname,
            $t->is_system ? 'Այո' : 'Ոչ',
        ];
    }
    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle($sheet->calculateWorksheetDimension())
            ->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('A1:O1')->getFont()->setBold(true);

        return [];
    }
}
