<?php

namespace App\Http\Controllers;

use App\Exports\ContractsCalcExport;
use App\Exports\ContractsExport;
use App\Exports\DailyExport;
use App\Http\Requests\ClientRequest;
use App\Http\Requests\ContractRequest;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ContractDetailResource;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\ClientClassification;
use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\ContractReserveHistory;
use App\Models\Deal;
use App\Models\DocumentJournal;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\ClientClassificationService;
use App\Services\ClientService;
use App\Services\ContractCalculationService;
use App\Services\ContractService;
use App\Services\EffectiveRateService;
use App\Services\FileService;
use App\Traits\ContractTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ContractControllerNew extends Controller
{
    use ContractTrait;
    protected ClientService $clientService;
    protected EffectiveRateService $effectiveRateService;
    protected ContractService $contractService;
    protected FileService $fileService;
    protected ClientClassificationService $clientClassificationService;
    protected ContractCalculationService $contractCalculationService;
    public function __construct(ClientService $clientService, ContractService $contractService,FileService $fileService,
                                EffectiveRateService $effectiveRateService,
                                ClientClassificationService $clientClassificationService,ContractCalculationService $contractCalculationService
    )
    {
        $this->clientService = $clientService;
        $this->contractService = $contractService;
        $this->fileService = $fileService;
        $this->effectiveRateService = $effectiveRateService;
        $this->clientClassificationService = $clientClassificationService;
        $this->contractCalculationService = $contractCalculationService;
    }
    public function get(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status', 'date_from', 'date_to', 'num',
            'provided_amount_from', 'provided_amount_to',
            'estimated_amount_from', 'estimated_amount_to',
            'name', 'surname', 'patronymic','passport','phone',
            'type','subspecies','model','delay'

        ]);

        $data = $this->contractService->getContracts($filters);

        return response()->json([
            'contracts' => $data['contracts'],
            'total' => $data['totalContracts'],
            'active' => $data['activeContracts'],
            'executed' => $data['executedContracts'],
        ]);
    }

    public function show($id, Request $request)
    {
        $calculationDateInput = $request->input('calculation_date');
        $calcToday = $calculationDateInput
            ? Carbon::parse($calculationDateInput, 'Asia/Yerevan')->startOfDay()
            : Carbon::now('Asia/Yerevan')->startOfDay();


        $contract = Contract::with([
            'client',
            'payments' => function ($query) { $query->orderBy('to_date', 'ASC'); },
            'history' => function ($query) {
                $query->whereDoesntHave('order', function ($q) {
                    $q->where('filter', Order::REFUND_LUMP_FILTER);
                })
                    ->with(['type', 'user', 'order'])
                    ->orderBy('id', 'DESC');
            },
            'items',
            'files',
            'deals',
        ])
            ->withMax('payments', 'to_date')
            ->withMin('payments', 'to_date')
            ->findOrFail($id);

        $currentPaymentAmount = $this->calculateCurrentPayment($contract);

        $contract->current_payment_amount = $currentPaymentAmount['current_amount'];
        $contract->penalty_amount         = $currentPaymentAmount['penalty_amount'];

        $this->contractCalculationService->calculateAllMetrics($contract, $calcToday);

        $contract->written_off_amount = null;

        if (($contract->client?->classification?->name) === 'loss') {
            $contract->written_off_amount = $contract->overdue_interest + $contract->unearned_interest;
        }

        return new ContractDetailResource($contract);
    }
    public function getHistoryDetails(int $id)
    {
        $history = History::with('user', 'order','contract')->find($id);
        if (!$history) {
            return response()->json(['message' => 'History record not found'], 404);
        }
        $details = [];
        switch ($history->type->name) {
            case HistoryType::REGULAR_PAYMENT:
                $details = [
                    'order_id'        => $history->order->order,
                    'interest_amount' => $history->interest_amount,
                    'penalty'         => $history->penalty,
                    'discount'        => $history->discount,
                    'date'            => $history->date,
                    'delay_days'      => $history->delay_days,
                    'total'           => $history->amount,
                ];
                break;
            case HistoryType::PARTIAL_PAYMENT:
                $details = [
                    'order_id' => $history->order->order,
                    'amount'   => $history->amount,
                    'date'     => $history->date,
                    'total'    => $history->total,
                ];
                break;
            case HistoryType::ONE_TIME_PAYMENT:
                $details = [
                    'order_id'         => $history->order->order,
                    'one_time_payment' => $history->amount,
                    'date'             => $history->date,
                    'total'            => $history->amount,
                ];
                break;
            case HistoryType::FULL_PAYMENT:
                $details = [
                    'order_id'        => $history->order->order,
                    'interest_amount' => $history->interest_amount,
                    'penalty'         => $history->penalty,
                    'mother_amount'   => $history->mother,
                    'returned_amount' => $history->amount - $history->interest_amount - $history->contract->mother,
                    'discount'        => $history->discount,
                    'date'            => $history->date,
                    'delay_days'      => $history->delay_days,
                    'total'           => $history->amount
                ];
                break;
            case HistoryType::MOTHER_PAYMENT:
                $details = [
                    'order_id' => $history->order->order,
                    'provided' => $history->amount,
                    'date'     => $history->date,
                    'total'    => $history->amount,
                ];
                break;
        }
        return response()->json([
            'details' => $details
        ]);
    }

    public function store(ClientRequest $clientRequest, ContractRequest $contractRequest, ItemRequest $itemRequest): JsonResponse|JsonResource
    {
        DB::beginTransaction();
        try {
            $client = $this->clientService->storeOrUpdate($clientRequest->validated());
            $pawnshop_id = \auth()->user()->pawnshop_id;
            $date = Carbon::now();
            $deadline = Carbon::now('Asia/Yerevan')->addMonths($contractRequest->validated()['deadline'])->format('Y-m-d');
            $contract = $this->contractService->createContract($client->id, $contractRequest->validated(), $deadline);
            $category_id = null;
            $items = $itemRequest->validated()['items'];
            foreach ($items as $item_data) {
                $category_id = $item_data['category_id'];
                $this->contractService->storeContractItem($contract->id, $item_data);
            }
            $contract->category_id = $category_id;
            $contract->save();
            $filesData = $contractRequest->all()['files'] ?? null;
            if ($filesData) {
                $this->fileService->uploadContractFiles($contract->id, $filesData);
            }

            $this->contractService->createPayment($contract);

            $client_name = $client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : '');
            $cash = $contract->provided_amount < 20000 ? true : false;

            $this->createOrderHistoryEntry($contract,$client->id, $client_name, 'out', 'opening', $contract->provided_amount, $cash, Contract::CONTRACT_OPENING,$contract->num,$pawnshop_id,$date);

            ContractAmountHistory::create([
                'contract_id' => $contract->id,
                'amount' => $contract->estimated_amount,
                'amount_type' => 'estimated_amount',
                'type' => 'in',
                'date' => $contract->date,
                'deal_id' => null,
                'category_id' => $category_id,
                'pawnshop_id' => auth()->user()->pawnshop_id ?? 1
            ]);
            DB::commit();

            return response()->json([
                'contract_id' => $contract->id,
                'message' => 'Contract created successfully.',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error processing the request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function payContractAmount(Request $request)
    {
        $validatedData = $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
        ]);

        DB::beginTransaction();
        try {
            $contract = Contract::findOrFail($validatedData['contract_id']);
            $contract->payments()->forcedelete();
            $client = $contract->client;
            $client_name = $client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : '');
            $cash = $contract->provided_amount < 20000;
            $category_id = $contract->category_id;
          //  $contract->deadline = Carbon::now('Asia/Yerevan')->addDays($contract->deadline_days)->format('Y-m-d H:i:s');
            $contract->deadline = Carbon::now('Asia/Yerevan')
                ->addMonths((int) $contract->deadline_days)
                ->format('Y-m-d');
            $contract->date = Carbon::now();
            $contract->save();
            $transactionDocumentNumber = (Transaction::max('document_number') ?? 0) + 1;
            $classification = $client->classification;
            if (!$classification) {
                $defaultClassification = ClientClassification::where('name', 'standard')->first();
                if ($defaultClassification) {
                    $client->classification_id = $defaultClassification->id;
                    $client->save();
                    $client->load('classification');
                    $classification = $client->classification;
                } else {
                    throw new \Exception("Default classification 'standard' not found!");
                }
            }
            $this->contractService->createPayment($contract);

            $deal_id = $this->createOrderAndHistory($contract, $client->id, $client_name, $cash, $category_id);
            $effectiveRates = (new \App\Services\EffectiveRateService())->calculateEffectiveRate($contract);
            $contract->effective_annual_rate = round($effectiveRates['annual'],10); // 24.00 (%)
            $contract->effective_daily_rate = round($effectiveRates['daily'],10);   // 0.064321 (%)
            $contract->save();

            ContractAmountHistory::create([
                'contract_id' => $contract->id,
                'amount' => $contract->provided_amount,
                'amount_type' => 'provided_amount',
                'type' => 'in',
                'date' => $contract->date,
                'deal_id' => $deal_id,
                'category_id' => $category_id,
                'pawnshop_id' => auth()->user()->pawnshop_id ?? 1
            ]);

            $acc16200NV = ChartOfAccount::idByCode('16200NV') ?? 1;
            $acc10210 = ChartOfAccount::idByCode('10210') ?? 1;

            $acc73015 = ChartOfAccount::idByCode('73015') ?? 1;
            $reserveCreditAccount = $client->classification->name == 'standard' ? ChartOfAccount::idByCode('16605PC') : ChartOfAccount::idByCode('16605PS');

            $clientId = Client::where('company_name','Diamond Credit')->first()->id ?? 1;
            $diamondId = $contract->client_id;

            $client->loadMissing('classification');


            $nextDocNum = (int) (Transaction::max('document_number') ?? 0) + 1;
            $document_type = DocumentJournal::PROVIDE_CONTRACT_AMOUNT;

            $journalDoc = DocumentJournal::create([
                'date'               => $contract->date,
                'document_number'    => $nextDocNum,
                'document_type'      => $document_type,
                'amount_amd'         => $contract->provided_amount,
                'partner_id'         => $clientId,
                'credit_partner_id'  => $diamondId,
                'comment'            => 'contract_payment',
                'debit_account_id'   => $acc16200NV,
                'credit_account_id'  => $acc10210,
                'user_id'            => auth()->id(),
                'journalable_type'   => Contract::class,
                'journalable_id'     => $contract->id,
            ]);

            Transaction::create([
                'date'               => $contract->date,
                'document_number'    => $nextDocNum,
                'document_type'      => $document_type,

                'debit_account_id'   => $acc16200NV,
                'debit_partner_id'   => $clientId,
                'debit_currency_id'  => 1,

                'credit_account_id'  => $acc10210,
                'credit_currency_id' => 1,
                'credit_partner_id'  => $diamondId,

                'amount_amd'         => $contract->provided_amount,

                'comment'            => 'contract_payment',
                'user_id'            => auth()->id(),
                'is_system'          => false,

                'disbursement_date'    =>  $contract->date,
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id'   => $journalDoc->id,
            ]);
            $reservePercent = $classification->reserve_percent ?? 0;
            $reserveAmount = $reservePercent/100 * $contract->provided_amount;
            dd($contract->payment_type);

            if ($reserveAmount > 0)
            {
                $nextDocNum++;

                $reserveDocumentType = $classification->name == 'standard' ?
                    DocumentJournal::RESERVE_GENERAL_AMOUNT: DocumentJournal::RESERVE_SPECIAL_AMOUNT;

                $reserveJournal = DocumentJournal::create([
                        'date'               => $contract->date,
                        'document_number'    => $nextDocNum,
                        'document_type'      => $reserveDocumentType,
                        'amount_amd'         => $reserveAmount,
                        'partner_id'         => $diamondId,
                        'credit_partner_id'  => $clientId,
                        'comment'            => "General reserve for contract #{$contract->id} on disbursement",
                        'debit_account_id'   => $acc73015,
                        'credit_account_id'  => $reserveCreditAccount,
                        'user_id'            => auth()->id(),
                        'journalable_type'   => DocumentJournal::class,
                        'journalable_id'     => $journalDoc->id,
                    ]);

                Transaction::create([
                    'date'               => $contract->date,
                    'document_number'    => $nextDocNum,
                    'document_type'      => $reserveDocumentType,

                    'debit_account_id'   => $acc73015,
                    'debit_partner_id'   => $diamondId,
                    'debit_currency_id'  => 1,

                    'credit_account_id'  => $reserveCreditAccount,
                    'credit_currency_id' => 1,
                    'credit_partner_id'  => $clientId,

                    'amount_amd'         => $reserveAmount,

                    'comment'            => "General reserve for contract #{$contract->id}",
                    'user_id'            => auth()->id(),
                    'is_system'          => true,

                    'disbursement_date'    => $contract->date->toDateString(),
                    'transactionable_type' => DocumentJournal::class,
                    'transactionable_id'   => $reserveJournal->id,
                ]);

            }


            auth()->user()->pawnshop->given = auth()->user()->pawnshop->given + $contract->provided_amount;
            auth()->user()->pawnshop->worth = auth()->user()->pawnshop->worth + $contract->estimated_amount;
            auth()->user()->pawnshop->save();
            DB::commit();
            return response()->json([
                'message' => 'Contract amount paid successfully',
                'contract_id' => $contract->id,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error processing payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function updateContractNumber(Request $request, $id): JsonResponse
    {
        $validatedData = $request->validate([
            'contract_number' => 'required|integer']);

        $contract = Contract::findOrFail($id);

        if (Contract::where('num', $validatedData['contract_number'])->where('id', '!=', $id)->exists()) {
            return response()->json([
                'message' => 'Contract number already exists.',
            ], 422);
        }

        $contract->num = $validatedData['contract_number'];
        $contract->save();

        return response()->json([
            'message' => 'Contract number updated successfully.',
            'contract' => $contract,
        ]);
    }
    public function updateContractItems(Request $request)
    {
        $validatedData = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:items,id',
            'items.*.category_id' => 'required|exists:categories,id',
            'items.*.description' => 'nullable|string',
            'items.*.subcategory' => 'nullable|string',
            'items.*.model' => 'nullable|string',
            'items.*.serialNumber' => 'nullable|string',
            'items.*.imei' => 'nullable|string',
            'items.*.weight' => 'nullable|numeric',
            'items.*.clear_weight' => 'nullable|numeric',
            'items.*.hallmark' => 'nullable|string',
            'items.*.car_make' => 'nullable|string',
            'items.*.manufacture' => 'nullable|integer',
            'items.*.power' => 'nullable|string',
            'items.*.license_plate' => 'nullable|string',
            'items.*.color' => 'nullable|string',
            'items.*.registration_certificate' => 'nullable|string',
            'items.*.identification_number' => 'nullable|string',
            'items.*.ownership_certificate' => 'nullable|string',
            'items.*.issued_by' => 'nullable|string',
            'items.*.date_of_issuance' => 'nullable|date',
            'items.*.rated' => 'nullable|numeric',
        ]);

        return $this->contractService->updateContractItems($validatedData['items']);
    }
    public function exportContracts1(Request $request)
    {
        $date = $request->input('date') ?? now()->toDateString();

        return Excel::download(new DailyExport(), 'contracts_export_' . $date . '.xlsx');
    }
    public function exportContracts()
    {
        return Excel::download(new ContractsExport(), 'contracts_export.xlsx');
    }

    public function calculateContractInterest(Request $request)
    {
        $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
            'calc_date' => 'required|date',
        ]);

        $contract = Contract::findOrFail($request->contract_id);
        $calcToday = Carbon::parse($request->calc_date, 'Asia/Yerevan')->startOfDay();

        $this->contractCalculationService->calculateInterestRates($contract, $calcToday);

        return response()->json([
            'calculated_interest' => $contract->calculatedInterest,
            'calculated_effective_interest' => $contract->calculatedEffectiveInterest,
        ]);
    }

    public function confirmCalculatedInterest(Request $request)
    {
        $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
            'calculated_interest' => 'required|numeric|min:0',
            'calculated_effective_interest' => 'required|numeric|min:0',
            'calc_date' => 'required|date',
        ]);

        $contract = Contract::findOrFail($request->contract_id);

        $creditPartnerId = Client::where('company_name', 'Diamond Credit')->first()->id ?? 1;
        $acc16200 = ChartOfAccount::idByCode('16200') ?? 1;
        $acc60120 = ChartOfAccount::idByCode('60120') ?? 1;
        $acc16201NI = ChartOfAccount::idByCode('16201NI') ?? 1;

        $documentTypeInterest = DocumentJournal::INTEREST_RATE_AMOUNT;
        $documentTypeEffective = DocumentJournal::EFFECTIVE_RATE_AMOUNT;

        $debetPartnerId = $contract->client_id;
        $date = Carbon::parse($request->calc_date, 'Asia/Yerevan')->startOfDay();;
        $systemUserId = auth()->check() ? auth()->id() : 1;
        $journal = DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->first();
        DB::beginTransaction();
        try {
            $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;

            if ($request->calculated_interest > 0) {
                $journalInterest = DocumentJournal::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentTypeInterest,
                    'amount_amd' => $request->calculated_interest,
                    'partner_id' => $debetPartnerId,
                    'credit_partner_id' => $debetPartnerId,
                    'comment' => 'Confirmed interest for contract #' . $contract->id,
                    'debit_account_id' => $acc16201NI,
                    'credit_account_id' => $acc16200,
                    'user_id' => $systemUserId,
                    'journalable_type' => DocumentJournal::class,
                    'journalable_id' => $journal->id,
                ]);

                Transaction::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentTypeInterest,
                    'debit_account_id' => $acc16201NI,
                    'debit_partner_id' => $debetPartnerId,
                    'debit_currency_id' => 1,
                    'credit_account_id' => $acc16200,
                    'credit_currency_id' => 1,
                    'credit_partner_id' => $debetPartnerId,
                    'amount_amd' => $request->calculated_interest,
                    'comment' => 'Confirmed interest for contract #' . $contract->id,
                    'user_id' => $systemUserId,
                    'is_system' => true,
                    'disbursement_date' => $date,
                    'transactionable_type' => DocumentJournal::class,
                    'transactionable_id' => $journalInterest->id,
                ]);

                $nextDocNum++;
            }

            if ($request->calculated_effective_interest > 0) {
                $journalEffective = DocumentJournal::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentTypeEffective,
                    'amount_amd' => $request->calculated_effective_interest,
                    'partner_id' => $debetPartnerId,
                    'credit_partner_id' => $creditPartnerId,
                    'comment' => 'Confirmed effective interest for contract #' . $contract->id,
                    'debit_account_id' => $acc16200,
                    'credit_account_id' => $acc60120,
                    'user_id' => $systemUserId,
                    'journalable_type' => DocumentJournal::class,
                    'journalable_id' => $journal->id,
                ]);

                Transaction::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentTypeEffective,
                    'debit_account_id' => $acc16200,
                    'debit_partner_id' => $debetPartnerId,
                    'debit_currency_id' => 1,
                    'credit_account_id' => $acc60120,
                    'credit_currency_id' => 1,
                    'credit_partner_id' => $creditPartnerId,
                    'amount_amd' => $request->calculated_effective_interest,
                    'comment' => 'Confirmed effective interest for contract #' . $contract->id,
                    'user_id' => $systemUserId,
                    'is_system' => true,
                    'disbursement_date' => $date,
                    'transactionable_type' => DocumentJournal::class,
                    'transactionable_id' => $journalEffective->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Interest confirmed and saved successfully.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to confirm interest for contract #' . $contract->id . ': ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm interest.'
            ], 500);
        }
    }

    public function exportContractsCalc(Request $request)
    {
        $calculationDateInput = $request->input('calculation_date');
        $calcToday = $calculationDateInput
            ? Carbon::parse($calculationDateInput, 'Asia/Yerevan')->startOfDay()
            : Carbon::now('Asia/Yerevan')->startOfDay();

        $contracts = Contract::with([
            'client.classification',
            'payments',
        ])->get();

        $contracts->each(function (Contract $contract) use ($calcToday) {
            $this->contractCalculationService->calculateAllMetrics($contract, $calcToday);

            $providedAmountSum = Deal::where('contract_id', $contract->id)
                ->where('purpose', 'ՄԳ տրամադրում')
                ->whereDate('date', '<=', $calcToday)
                ->sum('amount');

            $paymentAmountSum = Deal::where('contract_id', $contract->id)
                ->whereIn('purpose', ['Մասնակի վճարում', 'Ամբողջական վճարում'])
                ->whereDate('date', '<=', $calcToday)
                ->sum('amount');

            $dynamicProvidedAmount = $providedAmountSum - $paymentAmountSum;
            $contract->setAttribute('provided_amount', $dynamicProvidedAmount);

            $startDate = $contract->date ? Carbon::parse($contract->date)->startOfDay() : null;
            $deadlineDate = $contract->deadline ? Carbon::parse($contract->deadline)->startOfDay() : null;
            $totalDays = 0;
            if ($startDate && $deadlineDate && $deadlineDate->greaterThanOrEqualTo($startDate)) {
                $totalDays = $deadlineDate->diffInDays($startDate) + 1;
            }
            $contract->setAttribute('total_days_provided', $totalDays);
            $contract->setAttribute('calc_date', $calcToday);
        });

        $fileName = 'Contracts_Export_' . Carbon::now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new ContractsCalcExport($contracts), $fileName);
    }

}
