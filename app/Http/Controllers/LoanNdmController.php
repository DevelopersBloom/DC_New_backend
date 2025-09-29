<?php

namespace App\Http\Controllers;

use App\Exports\LoanNdmJournalExport;
use App\Http\Requests\StoreLoanNdmRequest;
use App\Models\Client;
use App\Models\DocumentJournal;
use App\Models\LoanNdm;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\LoanNdmInterestService;
use App\Traits\Journalable;
use App\Traits\OrderTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class LoanNdmController extends Controller
{
//    use OrderTrait;
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
    public function attachLoanNdm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_journal_id' => 'required|integer|exists:document_journal,id',
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
                if (!$loan instanceof \App\Models\LoanNdm) {
                    throw new \RuntimeException('Journal is not attached to a LoanNdm');
                }

                //   $loan    = LoanNdm::with(['client','currency','account'])->findOrFail($data['loan_ndm_id']);
                $date    = \Carbon\Carbon::parse($data['date'])->toDateString();
                $amount  = round((float)$data['amount'], 2);
                $docNum  = $data['document_number'] ?? ($loan->contract_number ?? null);


                $journal = $loan->journals()->create([
                    'date'            => $date,
                    'document_number' => $docNum,
                    'document_type'   => DocumentJournal::LOAN_ATTRACTION,
                    'currency_id'     => $loan->currency_id,
                    'amount_amd'      => $amount,
                    'partner_id'      => $loan->client_id,
                    'comment'         => $data['comment'] ?? null,
                    'user_id'         => auth()->id(),
                ]);

                $journal->transactions()->create([
                    'date'               => $date,
                    'document_number'    => $docNum,
                    'document_type'      => Transaction::LOAN_ATTRACTION,

                    'debit_account_id'   => $data['account_id'],
                    'debit_currency_id'  => $loan->currency_id,

                    'credit_account_id'  => $loan->account_id,
                    'credit_currency_id' => $loan->currency_id,
                    'credit_partner_id'  => $loan->client_id,

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

    public function calculateInterest(Request $request, LoanNdm $loan, LoanNdmInterestService $svc)
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date',
        ]);

        $result = $svc->calculate($loan, $data['from'], $data['to']);

        return response()->json($result);
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
