<?php

namespace App\Exports;

use App\Models\Transaction;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class TransactionsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected $from;
    protected $to;

    public function __construct($from = null, $to = null)
    {
        $this->from = $from;
        $this->to = $to;
    }

    public function collection()
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

        return $query->orderBy('date', 'desc')->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Ամսաթիվ',
            'Փաստաթղթի համար',
            'Փաստաթղթի տեսակ',
            'Գումար (AMD)',
            'Գումար (արտարժույթ)',
            'Արտարժույթի կոդ',
            'Դեբետ հաշիվ',
            'Կրեդիտ հաշիվ',
            'Օգտատեր',
            'Դեբետ արժույթ',
            'Կրեդիտ արժույթ',
            'Համակարգային',
            'Դեբետ գործընկեր',
            'Կրեդիտ գործընկեր',
        ];
    }

    public function map($t): array
    {
        return [
            $t->id,
            $t->date,
            $t->document_number,
            $t->document_type,
            $t->amount_amd,
            $t->amount_currency,
            optional($t->amountCurrencyRelation)->code,
            optional($t->debitAccount)->code,
            optional($t->creditAccount)->code,
            optional($t->user)->name . ' ' . optional($t->user)->surname,
            optional($t->debitCurrency)->code,
            optional($t->creditCurrency)->code,
            $t->is_system ? 'Այո' : 'Ոչ',
            optional($t->debitPartner)->company_name ?? optional($t->debitPartner)->name . ' ' . optional($t->debitPartner)->surname,
            optional($t->creditPartner)->company_name ?? optional($t->creditPartner)->name . ' ' .optional($t->creditPartner)->surname,
        ];
    }
}
