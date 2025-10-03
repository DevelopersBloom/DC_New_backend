<?php

namespace App\Http\Controllers;

use App\Exports\LoanNdmJournalExport;
use App\Http\Requests\StoreLoanNdmRequest;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\DocumentJournal;
use App\Models\LoanNdm;
use App\Models\NdmRepaymentDetail;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\LoanNdmInterestService;
use App\Traits\Journalable;
use App\Traits\OrderTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class LoanNdmController extends Controller
{
//    use OrderTrait;
    protected $loanNdmInterestService;
    public function __construct(LoanNdmInterestService $loanNdmInterestService)
    {
        $this->loanNdmInterestService = $loanNdmInterestService;
    }

    public function index(): JsonResponse
    {
            $loans = LoanNdm::with([
                'client:id,name,surname',
                'currency:id,code,name',
                'account:id,code,name',
                'interestAccount:id,code,name',
                'pawnshop:id,city',
            ])->get();

            return response()->json([
                'data'    => $loans,
            ]);
    }

    public function store(StoreLoanNdmRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            DB::beginTransaction();

            $amount = $data['amount'];
            $interestRate = $data['interest_rate'] ?? 0;

            $disbursementDate = Carbon::parse($data['disbursement_date']);
            $maturityDate     = Carbon::parse($data['maturity_date']);
            $days = $disbursementDate->diffInDays($maturityDate);

            switch ($data['day_count_convention'] ?? 'calendar_year') {
                case 'days_360':
                    $baseDays = 360;
                    break;

                case 'fixed_day':
                $baseDays = 360;
                    break;

                case 'calendar_year':
                default:
                    $year = $disbursementDate->year;
                    $baseDays = Carbon::create($year)->isLeapYear() ? 366 : 365;
                    break;
            }

            $interestAmount = round($amount * ($interestRate / 100) * ($days / $baseDays), 2);

            $data['interest_amount'] = $interestAmount;

            $isPhysical = true;
            $data['income'] = $isPhysical
                ? $interestAmount - round($interestAmount * ($data['tax_rate'] / 100), 2)
                : $interestAmount;

            $data['user_id'] = auth()->id();
            $data['calc_date'] = $data['contract_date'];
            $loanNdm = LoanNdm::create($data);

//            $purpose = Order::NDM_PURPOSE;
//            $filter_type = Order::NDM_FILTER;
//            $type = 'cost_out';
//            $cash = true;
//            $clientId = $data['client_id'];

//            $client = Client::findOrFail($clientId);

//            $partnerName = $client->type == 'legal' ? $client->company_name : $client->name . ' ' . $client->surname;
//
//            $partnerCode = $client->type == 'individual' ? $client->social_card_number :
//                $client->tax_number;

//            Transaction::create([
//                'date'               => $data['contract_date'],
//                'document_number'    => $data['contract_number'],
//                'document_type'      => Transaction::LOAN_NDM_TYPE,
//
////                'debit_account_id'   => 0,
//                'debit_partner_code' => $partnerCode,
//                'debit_partner_name' => $partnerName,
//                'debit_currency_id'  => $data['currency_id'],
//                'disbursement_date'  => $data['disbursement_date'],
//
////                'credit_account_id'   => 0,
////                'credit_partner_code' => $creditPartnerCode,
////                'credit_partner_name' => $creditPartnerName,
////                'credit_currency_id'  => $reminderOrder->currency_id,
//
//                'amount_amd'       => $data['amount'],
//                'amount_currency'  => 0,
//                'amount_currency_id'=> null,
//
//                'comment'   => $data['comment'],
//                'user_id'   => auth()->id(),
//                'is_system' => false,
//            ]);


//            $order_id = $this->getOrder($cash, $type);
//            $this->createOrderAndDeal(
//                $order_id,
//                $type === 'out' ? 'cost_out' : 'in',
//                $name,
//                $amount,
//                $purpose,
//                null,
//                $cash,
//                $filter_type,
//                $interestAmount,
//                $clientId
//            );

            DB::commit();

            return response()->json(['message' => 'Loan ndm created successfully'], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Վարկի ներգրավում
     * @param Request $request
     * @return JsonResponse
     */
    public function attachLoanNdm(Request $request)
    {
        $data = $request->validate([
            'document_journal_id' => 'required|integer|exists:documents_journal,id',
            'date'         => 'required|date',
            'amount'       => 'required|numeric|min:0.01',
            'cash'         => 'required|boolean',
            'account_id'   => 'required|integer|exists:chart_of_accounts,id',
            'comment'      => 'nullable|string|max:500',
            'document_number' => 'nullable|string|max:64',
        ]);

        try {
            return DB::transaction(function () use ($data) {
                $journal = DocumentJournal::with('journalable')
                    ->findOrFail($data['document_journal_id']);

                $loan = $journal->journalable;
                if (!$loan instanceof LoanNdm) {
                    throw new \RuntimeException('Journal is not attached to a LoanNdm');
                }

                //   $loan    = LoanNdm::with(['client','currency','account'])->findOrFail($data['loan_ndm_id']);
                $date    = \Carbon\Carbon::parse($data['date'])->toDateString();
                $amount  = round((float)$data['amount'], 2);
                $docNum  = $data['document_number'] ?? ($loan->contract_number ?? null);

                $acc102101 = ChartOfAccount::idByCode('102101');
              //  $acc33512NV = ChartOfAccount::idByCode('33512NV');
                $loanAccountId = $loan->account_id;
                $partnerId = Client::where('company_name','Diamond Credit')->first()->id;
                $creditPartnerId = $loan->client_id;

                if (!$acc102101 || !$loanAccountId) return 'One of 102101, 33512NV not wxist';

                $journal = $loan->journals()->create([
                    'date'            => $date,
                    'document_number' => $docNum,
                    'document_type'   => DocumentJournal::LOAN_ATTRACTION,
                    'currency_id'     => $loan->currency_id,
                    'amount_amd'      => $amount,
                    'partner_id'      => $partnerId,
                    'credit_partner_id' => $creditPartnerId,
                    'comment'         => $data['comment'] ?? null,
                    'debit_account_id' => $acc102101,
                    'credit_account_id' => $loanAccountId,
                    'user_id'         => auth()->id(),
                ]);

                $journal->transactions()->create([
                    'date'               => $date,
                    'document_number'    => $docNum,
                    'document_type'      => Transaction::LOAN_ATTRACTION,

                    'debit_account_id'   => $acc102101,
                    'debit_partner_id' => $partnerId,
                    'debit_currency_id'  => $loan->currency_id,

                    'credit_account_id'  => $loanAccountId,
                    'credit_currency_id' => $loan->currency_id,
                    'credit_partner_id'  => $creditPartnerId,

                    'amount_amd'         => $amount,

                    'comment'            => $data['comment'] ?? null,
                    'user_id'            => auth()->id(),
                    'is_system'          => false,

                    'disbursement_date'  => $date,
                ]);

                return response()->json([
                    'message'     => 'Վարկի ներգրավումը հաջողությամբ ստեղծվեց',
                ], 201);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Տեղի ունեցավ սխալ',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function calculateInterest(Request $request)
    {
        $data = $request->validate([
            'document_journal_id' => 'required|integer|exists:documents_journal,id',
            'calculation_date' => 'required|date',
            'operation_date'   => 'required|date',
        ]);
        $journal = DocumentJournal::with('journalable')
            ->findOrFail($data['document_journal_id']);

        $loan = LoanNdm::find($journal->journalable_id);

        $from = $loan->calc_date;
        if ($loan) {
            $result = $this->loanNdmInterestService->calculate($loan, $from, $data['calculation_date']);
            return response()->json($result);

        }
        return "Something went wrong";
    }

    public function postInterest(Request $request)
    {
        $data = $request->validate([
            'document_journal_id'       => 'required|integer|exists:documents_journal,id',
            'operation_date'            => 'required|date',
            'calculation_date'          => 'required|date',
            'interest_amount'           => 'required|numeric|min:0',
            'effective_interest_amount' => 'required|numeric|min:0',
            'comment'                   => 'nullable|string',
        ]);

        $baseJournal = DocumentJournal::with('journalable')->findOrFail($data['document_journal_id']);

        /** @var LoanNdm|null $loan */
        $loan = $baseJournal->journalable instanceof LoanNdm
            ? $baseJournal->journalable
            : LoanNdm::find($baseJournal->journalable_id);

        if (!$loan) {
            return response()->json(['message' => 'Related LoanNdm not found for this journal.'], 404);
        }

        if ($data['interest_amount'] == 0 && $data['effective_interest_amount'] == 0) {
            return response()->json(['message' => 'Both interest amounts are zero; nothing to journal.'], 422);
        }
        $acc70315   = ChartOfAccount::idByCode('70315') ?? 1;
        $acc33512   = ChartOfAccount::idByCode('33512') ?? 1;
        $acc33513NI = ChartOfAccount::idByCode('33513NI') ?? 1;
        if(!$acc70315 || !$acc33512 || !$acc33513NI ) return "One of the 70315,acc33512,33513NI accounts not exist";

        DB::beginTransaction();
        try {
            $loan->calc_date = $data['calculation_date'];
            $partnerId = Client::where('company_name','Diamond Credit')->first()->id;
            $creditPartnerId = $loan->client_id;
            $loan->save();

            $common = [
                'date'             => $data['operation_date'],
                'currency_id'      => $baseJournal->currency_id ?? null,
                'user_id'          => Auth::id() ?? $baseJournal->user_id,
                'journalable_type' => $baseJournal->journalable_type,
                'journalable_id'   => $baseJournal->journalable_id,
                'comment'          => $data['comment'] ?? null,
            ];

            $created = [];

            // Journal #1 — Արդյունավետ տոկոսի հաշվարկում, Տոկ
            // Դեբետ 70315 (ծախս), Կրեդիտ 33512 (վարկատու)
            if ($data['effective_interest_amount'] > 0) {
                $created['effective'] = DocumentJournal::create(array_merge($common, [
                    'amount_amd'       => $data['effective_interest_amount'],
                    'debit_account_id' => $acc70315,
                    'credit_account_id'=> $acc33512,
                    'credit_partner_id'=> $partnerId,
                    'debit_partner_id'=> $creditPartnerId,
                    'document_type'    => DocumentJournal::EFFECTIVE_RATE,

                ]));
            }

            if ($data['interest_amount'] > 0) {
                $created['interest'] = DocumentJournal::create(array_merge($common, [
                    'amount_amd'       => $data['interest_amount'],
                    'debit_account_id' => $acc33512,
                    'credit_account_id'=> $acc33513NI,
                    'credit_partner_id'=> $partnerId,
                    'debit_partner_id'=> $creditPartnerId,
                    'document_type'    => DocumentJournal::INTEREST_RATE,
                ]));
            }

            DB::commit();

            return response()->json([
                'status'          => 'ok',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to add journals',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    public function repay(Request $request)
    {
        $data = $request->validate([
            'document_journal_id' => 'required|integer|exists:documents_journal,id',
            'operation_date'      => 'required|date',
            'currency_id'         => 'nullable|integer|exists:currencies,id',

            'principal_amount'    => 'nullable|numeric|min:0',
            'interest_amount'     => 'nullable|numeric|min:0',
            'tax_from_interest'   => 'nullable|numeric|min:0',

            'comment'             => 'nullable|string',
            'cash'                => 'boolean',

            'interest_unused_part'      => 'nullable|numeric|min:0',
            'penalty_overdue_principal' => 'nullable|numeric|min:0',
            'penalty_overdue_interest'  => 'nullable|numeric|min:0',
            'tax_total'                 => 'nullable|numeric|min:0',
            'tax_from_penalty_pr'       => 'nullable|numeric|min:0',
            'tax_from_penalty_int'      => 'nullable|numeric|min:0',
            'total_amount'              => 'nullable|numeric|min:0',
            'account_id'                =>  'nullable|integer|exists:chart_of_accounts,id',
        ]);

        $baseJournal = DocumentJournal::with('journalable')->findOrFail($data['document_journal_id']);

        /** @var LoanNdm|null $loan */
        $loan = $baseJournal->journalable instanceof LoanNdm
            ? $baseJournal->journalable
            : LoanNdm::find($baseJournal->journalable_id);

        if (!$loan) {
            return response()->json(['message' => 'Related LoanNdm not found.'], 404);
        }

        $acc33513NI = ChartOfAccount::idByCode('33513NI');
        $acc33512NV = ChartOfAccount::idByCode('33512NV');
        $acc102101 = ChartOfAccount::idByCode('102101');
        $acc391021 = ChartOfAccount::idByCode('391021');

        if (!$acc33513NI || !$acc102101 || !$acc391021) return "One of the 391021,33513NI,102101 not exist";

        $principal = (float)($data['principal_amount'] ?? 0);
        $interest  = (float)($data['interest_amount'] ?? 0);
        $taxInt    = (float)($data['tax_from_interest'] ?? 0);


        return DB::transaction(function () use ($data, $baseJournal, $loan, $principal, $interest, $taxInt, $acc33513NI, $acc33512NV, $acc102101,$acc391021) {
            $loan->calc_date = $data['operation_date'];
            $loan->save();

            $lombardId = Client::where('company_name','Diamond Credit')->first()->id;
            $clientId = $loan->client_id;
            $loanAccountId = $loan->account_id;

            $documentNumber = (DocumentJournal::max('document_number') ?? 0) + 1;
            $creditAccountId= $loan->account_id;
            $commonJ = [
                'date'             => $data['operation_date'],
                'operation_number' => (DocumentJournal::max('operation_number') ?? 0) + 1,
                'currency_id'      => $data['currency_id'] ?? $baseJournal->currency_id,
                'user_id'          => Auth::id() ?? $baseJournal->user_id,
                'journalable_type' => $baseJournal->journalable_type,
                'journalable_id'   => $baseJournal->journalable_id,
                'cash'             => $data['cash'] ?? true,
                'comment'          => $data['comment'] ?? null,
                'pawnshop_id'      => Auth::user()->pawnshop_id,
            ];

            $detailPayload = [
                'interest_unused_part'      => (float)($data['interest_unused_part']      ?? 0),
                'penalty_overdue_principal' => (float)($data['penalty_overdue_principal'] ?? 0),
                'penalty_overdue_interest'  => (float)($data['penalty_overdue_interest']  ?? 0),
                'tax_total'                 => (float)($data['tax_total']                 ?? 0),
                'tax_from_interest'         => (float)($data['tax_from_interest']         ?? 0),
                'tax_from_penalty_pr'       => (float)($data['tax_from_penalty_pr']       ?? 0),
                'tax_from_penalty_int'      => (float)($data['tax_from_penalty_int']      ?? 0),
                'total_amount'              => (float)($data['total_amount']              ?? 0),
                'account_id' => $data['account_id']
            ];

            /** @var \App\Models\NdmRepaymentDetail $detail */
            $detail = NdmRepaymentDetail::create($detailPayload);

            // foreign key-ը կփակցնենք բոլոր ստեղծվող journal-ներին
            $commonJWithFK = $commonJ + ['ndm_repayment_id' => $detail->id];


            if ($interest > 0) {
                $j = DocumentJournal::create($commonJWithFK + [
                        'document_type'     => 'Տոկոսի մարում',
                        'document_number'   => $documentNumber,
                        'amount_amd'        => $interest,
                        'debit_account_id'  => $acc33513NI,
                        'credit_account_id' => $acc102101,
                        'partner_id' => $clientId,
                        'credit_partner_id' => $lombardId,
                    ]);

                $j->transactions()->create([
                    'date'              => $data['operation_date'],
                    'document_type'     => 'Տոկոսի մարում',
                    'debit_account_id'  => $acc33513NI,
                    'credit_account_id' => $acc102101,
                    'currency_id'       => $commonJ['currency_id'],
                    'amount_amd'        => $interest,
                    'amount_currency'   => $interest,
                    'comment'           => 'Տոկոսի մարում',
                    'debit_partner_id' => $clientId,
                    'credit_partner_id' => $lombardId,
                ]);
                $documentNumber++;
            }

            // ===== Հիմնականի մարում =====
            if ($principal > 0) {
                $j = DocumentJournal::create($commonJWithFK + [
                        'document_type'     => 'Վարկի մարում',
                        'document_number'   => $documentNumber,
                        'amount_amd'        => $principal,
                        'debit_account_id'  => $loanAccountId,
                        'credit_account_id' => $acc102101,
                        'partner_id' => $clientId,
                        'credit_partner_id' => $lombardId,
                    ]);

                $j->transactions()->create([
                    'date'              => $data['operation_date'],
                    'document_type'     => 'Վարկի մարում',
                    'debit_account_id'  => $loanAccountId,
                    'credit_account_id' => $acc102101,
                    'currency_id'       => $commonJ['currency_id'],
                    'amount_amd'        => $principal,
                    'amount_currency'   => $principal,
                    'comment'           => 'Վարկի մարում',
                    'debit_partner_id' => $clientId,
                    'credit_partner_id' => $lombardId,
                ]);
                $documentNumber++;
            }

            // ===== Հարկի գանձում տոկոսի մարումից =====
            if ($taxInt > 0) {
                $j = DocumentJournal::create($commonJWithFK + [
                        'document_type'     => 'Հարկի գանձում տոկոսի մարումից',
                        'document_number'   => $documentNumber,
                        'amount_amd'        => $taxInt,
                        'debit_account_id'  => $acc33513NI,
                        'credit_account_id' => $acc391021,
                        'partner_id' => $clientId,
                        'credit_partner_id' => $lombardId,
                    ]);

                $j->transactions()->create([
                    'date'              => $data['operation_date'],
                    'document_type'     => 'Հարկի գանձում տոկոսի մարումից',
                    'debit_account_id'  => $acc33513NI,
                    'credit_account_id' => $acc391021,
                    'currency_id'       => $commonJ['currency_id'],
                    'amount_amd'        => $taxInt,
                    'amount_currency'   => $taxInt,
                    'comment'           => 'Հարկի գանձում տոկոսի մարումից',
                    'debit_partner_id' => $clientId,
                    'credit_partner_id' => $lombardId,
                ]);
                $documentNumber++;
            }

            return response()->json(['status' => 'ok']);
        });
    }



public function loanNdmJournal(Request $request): JsonResponse
    {
        $from = $request->query('from_date');
        $to   = $request->query('to_date');

        $query = LoanNdm::with([
            'client:id,type,name,surname,company_name,social_card_number,tax_number',
            'currency:id,code',
            'user:id,name,surname',
        ])
            ->when($from && $to, fn($q) => $q->whereBetween('contract_date', [$from, $to]))
            ->when($from && !$to, fn($q) => $q->where('contract_date', '>=', $from))
            ->when(!$from && $to, fn($q) => $q->where('contract_date', '<=', $to))
            ->orderBy('id', 'desc');

        $page = $query->paginate(20);

        $page->getCollection()->transform(function (LoanNdm $ndm) {
            $client = $ndm->client;
            $partnerName = $client
                ? ($client->type === 'legal'
                    ? ($client->company_name ?? '')
                    : trim(($client->name ?? '').' '.($client->surname ?? '')))
                : null;

            $partnerCode = $client
                ? ($client->type === 'individual'
                    ? ($client->social_card_number ?? null)
                    : ($client->tax_number ?? null))
                : null;

            return [
                'id'                  => $ndm->id,
                'date'                => optional($ndm->contract_date)->format('Y-m-d'),
                'document_number'     => $ndm->contract_number,
                'document_type'       => Transaction::LOAN_NDM_TYPE,
                'amount_amd'          => $ndm->amount,
                'amount_currency_id'  => $ndm->currency_id,
                'debit_partner_code'  => $partnerCode,
                'debit_partner_name'  => $partnerName,
                'comment'             => $ndm->comment ?? null,
                'user_id'             => auth()->user()->id ?? null,
                'disbursement_date'   => optional($ndm->disbursement_date)->format('Y-m-d'),

                'amount_currency_relation'            => $ndm->currency ? ['id' => $ndm->currency->id, 'code' => $ndm->currency->code] : null,
//                'user'                => auth()->user() ? ['id' => auth()->user()->id, 'name' => auth()->user()->name, 'surname' => auth()->user()->surname] : null,
                'user' => $ndm->user,

            ];
        });

        return response()->json($page);
    }

    public function exportLoanNdmJournal(Request $request)
    {
        $from = $request->query('from_date');
        $to   = $request->query('to_date');

        return Excel::download(
            new LoanNdmJournalExport($from, $to),
            'ՓաստաթղթերիՄատյան.xlsx'
        );
    }
}
