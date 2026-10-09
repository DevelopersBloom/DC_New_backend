<?php

namespace App\Services\Reports;

/**
 * Source-of-truth registry for the /reports page: ONE business definition, ONE authoritative source, ONE date rule
 * and ONE formula per figure. Backend-owned; the UI tooltips read `definition` from the API, so a business formula
 * never lives only in a frontend component. Full audit tables: docs/REPORTS_SOURCE_OF_TRUTH.md.
 *
 * kind: A stock/as-of | B period flow | H ratio | J data-quality figure   (see the audit task for the full A..J list)
 */
final class ReportKpiRegistry
{
    /** Financial Indicators tab (daily table). Keys are the keys of FinancialIndicatorsService rows. */
    public const FINANCIAL = [
        'estimated_collateral' => [
            'label' => 'Գնահատված գրավ', 'kind' => 'A',
            'definition' => 'Ընտրված օրվա դրությամբ գրավի գնահատված արժեքի մնացորդ (վերագնահատումները ներառված են)։',
            'source' => 'contract_amount_histories (amount_type=estimated_amount, deleted_at IS NULL, pawnshop_id)',
            'date_basis' => 'history.date <= D',
            'formula' => 'SUM(in) - SUM(out) of estimated_amount rows',
            'not_used' => 'contracts.estimated_amount (current state, changes after the date)',
        ],
        'outstanding_principal' => [
            'label' => 'Վարկային պորտֆելի մնացորդ', 'kind' => 'A',
            'definition' => 'Ընտրված օրվա դրությամբ չմարված հիմնական գումարի մնացորդ։',
            'source' => 'contract_amount_histories (amount_type=provided_amount, deleted_at IS NULL, pawnshop_id)',
            'date_basis' => 'history.date <= D',
            'formula' => 'SUM(in) - SUM(out) of provided_amount rows',
            'not_used' => 'contracts.provided_amount (current state; reconciliation only)',
        ],
        'loan_collateral_ratio' => [
            'label' => 'Վարկ / գրավ', 'kind' => 'H',
            'definition' => 'Վարկային պորտֆելի մնացորդի և գնահատված գրավի հարաբերությունը (%)։',
            'source' => 'derived from the two stocks above', 'date_basis' => 'D',
            'formula' => 'outstanding_principal / estimated_collateral * 100; null when collateral <= 0',
        ],
        'cash_balance' => [
            'label' => 'Դրամարկղ (կանխիկ)', 'kind' => 'A',
            'definition' => 'Ընտրված օրվա դրությամբ ֆիզիկական դրամական միջոցների գործառնական մնացորդ (ներառում է դրամարկղից բանկ փոխանցումները)։',
            'source' => "deals (cash=1, type in in/out/expense/cost_out, deleted_at IS NULL, valid ISO date, pawnshop_id)",
            'date_basis' => 'deals.date <= D',
            'formula' => "SUM(type='in') - SUM(type in out, expense, cost_out) over cash=1 deals",
            'not_used' => 'chart_of_accounts/transactions (accounting ledger, no pawnshop_id: reconciliation only)',
        ],
        'bank_balance' => [
            'label' => 'Անկանխիկ (գործառնական)', 'kind' => 'A',
            'definition' => 'Ընտրված օրվա դրությամբ անկանխիկ գործառնությունների զուտ մնացորդ։ Բանկային հաշվի մնացորդ չէ․ կարող է լինել բացասական։',
            'source' => 'deals (cash=0, same types and filters as cash)',
            'date_basis' => 'deals.date <= D',
            'formula' => "SUM(type='in') - SUM(type in out, expense, cost_out) over cash=0 deals",
            'not_used' => 'ledger account 10210 (bank statement balance): different concept, shown only as validation',
        ],
        'new_disbursement' => [
            'label' => 'Նոր վարկերի տրամադրում', 'kind' => 'B',
            'definition' => 'Օրվա ընթացքում տրամադրված նոր վարկերի առաջին տրամադրումներ։',
            'source' => 'contract_amount_histories provided_amount/in, ranked per contract (CreditActivityReportService::disbursementEvents)',
            'date_basis' => 'logical disbursement date (first history row; rows of the same deal are folded into it)',
            'formula' => 'SUM(amount) of events with kind=first',
        ],
        'top_up_disbursement' => [
            'label' => 'Լրացուցիչ տրամադրում (top-up)', 'kind' => 'B',
            'definition' => 'Գոյություն ունեցող վարկին օրվա ընթացքում լրացուցիչ տրամադրված գումար։',
            'source' => 'same events, kind=topup', 'date_basis' => 'history.date',
            'formula' => 'SUM(amount) of events with kind=topup (rows of the first deal are never top-ups)',
        ],
        'total_disbursement' => [
            'label' => 'Օրվա տրամադրում', 'kind' => 'B',
            'definition' => 'Օրվա ընթացքում տրամադրված ընդհանուր գումար՝ նոր վարկեր + top-up։',
            'source' => 'same events', 'date_basis' => 'logical disbursement date',
            'formula' => 'new_disbursement + top_up_disbursement',
            'not_used' => 'deals out/contract (cash-flow evidence, reconciled in the management summary only)',
        ],
        'principal_reduction' => [
            'label' => 'Մայր գումարի նվազում', 'kind' => 'B',
            'definition' => 'Օրվա ընթացքում պորտֆելի մնացորդի նվազումը (մարումներ, փակումներ, իրացումներ)։',
            'source' => 'contract_amount_histories provided_amount/out', 'date_basis' => 'history.date',
            'formula' => 'SUM(amount) of provided_amount rows with type=out',
        ],
        'receipts' => [
            'label' => 'Ստացված վճարումներ', 'kind' => 'E',
            'definition' => 'Օրվա ընթացքում հաճախորդներից ստացված վճարումների ընդհանուր գումար (մայր գումար + տոկոս + տուգանք)։',
            'source' => "deals (type='in', filter_type in payment/full_payment): the repayment deals used by PaymentHistoryResolver",
            'date_basis' => 'deals.date',
            'formula' => 'SUM(amount); not split by component (the split is a PaymentHistoryResolver concern)',
        ],
    ];

    /** Deal classification (type + filter_type) used for cash/bank and receipts. Order = evaluation order. */
    public const DEAL_CLASSES = [
        ['type' => 'in', 'filter_type' => 'payment', 'event' => 'Regular repayment', 'cash_bank' => '+', 'disbursement' => false, 'receipt' => true],
        ['type' => 'in', 'filter_type' => 'full_payment', 'event' => 'Full repayment', 'cash_bank' => '+', 'disbursement' => false, 'receipt' => true],
        ['type' => 'in', 'filter_type' => 'partial_payment', 'event' => 'Partial repayment (legacy)', 'cash_bank' => '+', 'disbursement' => false, 'receipt' => false],
        ['type' => 'in', 'filter_type' => 'ndm', 'event' => 'Borrowed funds received', 'cash_bank' => '+', 'disbursement' => false, 'receipt' => false],
        ['type' => 'in', 'filter_type' => null, 'event' => 'Cashbox/bank top-up (addCashbox) or unknown sender', 'cash_bank' => '+', 'disbursement' => false, 'receipt' => false],
        ['type' => 'in', 'filter_type' => 'contract', 'event' => 'One-off contract fee', 'cash_bank' => '+', 'disbursement' => false, 'receipt' => false],
        ['type' => 'in', 'filter_type' => 'expense', 'event' => 'Expense refund', 'cash_bank' => '+', 'disbursement' => false, 'receipt' => false],
        ['type' => 'out', 'filter_type' => 'contract', 'event' => 'Loan disbursement (opening / top-up)', 'cash_bank' => '-', 'disbursement' => true, 'receipt' => false],
        ['type' => 'out', 'filter_type' => null, 'event' => 'Internal transfer cash -> bank (addCashbox, cash=1 leg)', 'cash_bank' => '-', 'disbursement' => false, 'receipt' => false],
        ['type' => 'out', 'filter_type' => 'refund_lump', 'event' => 'Refund of a lump payment', 'cash_bank' => '-', 'disbursement' => false, 'receipt' => false],
        ['type' => 'cost_out', 'filter_type' => 'expense', 'event' => 'Expense', 'cash_bank' => '-', 'disbursement' => false, 'receipt' => false],
        ['type' => 'cost_out', 'filter_type' => 'ndm', 'event' => 'Borrowed funds repaid', 'cash_bank' => '-', 'disbursement' => false, 'receipt' => false],
    ];
}
