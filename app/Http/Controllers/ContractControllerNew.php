<?php

namespace App\Http\Controllers;

use App\Exports\ContractsCalcExport;
use App\Exports\ContractsExport;
use App\Http\Requests\ClientRequest;
use App\Http\Requests\ContractRequest;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ContractDetailResource;
use App\Models\ChartOfAccount;
use App\Models\ClassificationHistory;
use App\Models\Client;
use App\Models\ClientClassification;
use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\Deal;
use App\Models\DealAction;
use App\Models\DocumentJournal;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\IdempotencyKey;
use App\Models\Modification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Services\ActivityService;
use App\Services\ClientClassificationService;
use App\Services\ClientService;
use App\Services\ContractCalculationService;
use App\Services\ContractService;
use App\Services\EffectiveRateService;
use App\Services\FileService;
use App\Traits\CalculatesAccountBalancesTrait;
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
    use ContractTrait, CalculatesAccountBalancesTrait;
    protected ClientService $clientService;
    protected EffectiveRateService $effectiveRateService;
    protected ContractService $contractService;
    protected FileService $fileService;
    protected ClientClassificationService $clientClassificationService;
    protected ContractCalculationService $contractCalculationService;
    protected ActivityService $activityService;
    public function __construct(ClientService $clientService, ContractService $contractService,FileService $fileService,
                                EffectiveRateService $effectiveRateService,
                                ClientClassificationService $clientClassificationService,ContractCalculationService $contractCalculationService,
                                ActivityService $activityService

    )
    {
        $this->clientService = $clientService;
        $this->contractService = $contractService;
        $this->fileService = $fileService;
        $this->effectiveRateService = $effectiveRateService;
        $this->clientClassificationService = $clientClassificationService;
        $this->contractCalculationService = $contractCalculationService;
        $this->activityService = $activityService;
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

        $this->activityService->log(
            'view_contracts_list',
            'User viewed contracts',
            Contract::class
        );

        return response()->json([
            'contracts' => $data['contracts'],
            'total' => $data['totalContracts'],
            'active' => $data['activeContracts'],
            'executed' => $data['executedContracts'],
        ]);
    }
    public function getForAdmin(): JsonResponse
    {
        $contracts = Contract::with('client:id,name,surname')
            ->get(['id', 'num', 'client_id', 'provided_amount', 'estimated_amount','date']);

        $result = $contracts->map(fn($c) => [
            'id' => $c->id,
            'num' => $c->num,
            'client_name' => $c->client->name ?? null,
            'client_surname' => $c->client->surname ?? null,
            'provided_amount' => $c->provided_amount,
            'estimated_amount' => $c->estimated_amount,
            'date' => $c->date,
        ]);

        return response()->json([
            'contracts' => $result,
            'total' => $contracts->count(),
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
            'seller',
            'guarantors',
            'payments' => function ($query) { $query->orderBy('to_date', 'ASC'); },
            'history' => function ($query) {
                $query->whereDoesntHave('order', function ($q) {
                    $q->where('filter', Order::REFUND_LUMP_FILTER);
                })
                    ->with(['type', 'user', 'order'])
                    ->orderBy('id', 'DESC');
            },
            'items',
            'items.realEstate',
            'items.category',
            'files',
            'deals',
        ])
            ->withMax('payments', 'to_date')
            ->withMin('payments', 'to_date')
            ->findOrFail($id);

        $currentPaymentAmount = $this->calculateCurrentPayment($contract);
        $contract->interest_amount = $currentPaymentAmount['interest_amount'];
        $contract->current_payment_amount = $currentPaymentAmount['current_amount'];
        $contract->penalty_amount         = $currentPaymentAmount['penalty_amount'];
        $contract->future_interest_discount = $currentPaymentAmount['future_interest_discount'] ?? 0;

        $this->contractCalculationService->calculateAllMetrics($contract, $calcToday);

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
        $this->activityService->log(
            'view_history_details',
            "Viewed contract history details",
            History::class,
            $id
        );
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
            $validatedContract = $contractRequest->validated();
            $date = !empty($validatedContract['contract_created_date'])
                ? Carbon::parse($validatedContract['contract_created_date'], 'Asia/Yerevan')
                : Carbon::now('Asia/Yerevan');
            $deadline = $date->copy()->addMonths($contractRequest->validated()['deadline'])->format('Y-m-d');
            $contract = $this->contractService->createContract($client->id, $contractRequest->validated(), $deadline);
            $category_id = null;
            $items = $itemRequest->validated()['items'];
            foreach ($items as $item_data) {
                $category_id = $item_data['category_id'];
                $this->contractService->storeContractItem($contract->id, $item_data);
            }
            $contract->category_id = $category_id;

            $contract->save();
            $guarantors = $contractRequest->validated()['guarantors'] ?? [];

            if (!empty($guarantors)) {
                $guarantorIds = collect($guarantors)->pluck('id')->unique()->toArray();

                $guarantorIds = array_filter($guarantorIds, fn($id) => $id != $client->id);

                $contract->guarantors()->sync($guarantorIds);
            }

            if ($contract->category->name == 'car')
            {
                $contract->kasko_amount = $contractRequest->kasko_amount;
                $contract->save();
            }

            $filesData = $contractRequest->all()['files'] ?? null;
            if ($filesData) {
                $this->fileService->uploadContractFiles($contract->id, $filesData);
            }

            $this->contractService->createPayment($contract);
            $contract->load('payments');

            $effectiveRates = (new \App\Services\EffectiveRateService())->calculateEffectiveRate($contract);

            $contract->update([
                'effective_annual_rate'      => $effectiveRates['annual'],
                'effective_daily_rate'       => $effectiveRates['daily'],
                'effective_rate_kasko'       => $effectiveRates['kasko_daily'],
                'effective_rate_annual_kasko' => $effectiveRates['kasko_annual'],
            ]);
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
            $this->activityService->log(
                'create_contract',
                "Created contract #{$contract->num}",
                Contract::class,
                $contract->id
            );
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
            'contract_created_date' => 'nullable|date',
        ]);

        DB::beginTransaction();
        try {
            $contract = Contract::findOrFail($validatedData['contract_id']);
            $contract->payments()->forcedelete();
            $client = $contract->client;
            $client_name = $client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : '');
            $cash = $contract->provided_amount < 20000;
            $category_id = $contract->category_id;
            $now = !empty($validatedData['contract_created_date'])
                ? Carbon::parse($validatedData['contract_created_date'], 'Asia/Yerevan')
                : Carbon::now('Asia/Yerevan');
            $contract->deadline = $now->copy()
                ->addMonths((int) $contract->deadline_days)
                ->format('Y-m-d');
            $contract->date = $now;
            $contract->provided_at = $now;
            $contract->save();
            $oldClassification = $client->classification;

            if (!$oldClassification) {
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

            $ruleContractAmount = PostingRule::where('business_event_filter', 'provide_contract_amount')
                ->first();

            if (!$ruleContractAmount) {
                throw new \RuntimeException('Posting rule for provide_contract_amount not found');
            }

            $debitContractAmount = $ruleContractAmount->debit_account_id;
            $creditContractAmount =  $ruleContractAmount->credit_account_id;

            $clientId = $contract->client_id;

            $client->loadMissing('classification');


            $nextDocNum = Transaction::getNextDocumentNumber();
            $document_type = DocumentJournal::PROVIDE_CONTRACT_AMOUNT;

            $journalDoc = DocumentJournal::create([
                'date'               => $contract->date,
                'document_number'    => $nextDocNum,
                'document_type'      => $document_type,
                'amount_amd'         => $contract->provided_amount,
                'debit_partner_id'   => $ruleContractAmount->resolveDebitPartnerId($contract) ?? $clientId,
                'credit_partner_id'  => $ruleContractAmount->resolveCreditPartnerId($contract) ?? null,
                'comment'            => 'contract_payment',
                'debit_account_id'   => $debitContractAmount,
                'credit_account_id'  => $creditContractAmount,
                'user_id'            => auth()->id(),
                'journalable_type'   => Contract::class,
                'journalable_id'     => $contract->id,
                'deal_id'            => $deal_id,
                'contract_id'        => $contract->id,
            ]);

            Transaction::create([
                'date'               => $contract->date,
                'document_number'    => $nextDocNum,
                'document_type'      => $document_type,

                'debit_account_id'   => $debitContractAmount,
                'debit_partner_id'   => $ruleContractAmount->resolveDebitPartnerId($contract) ?? $clientId,
                'debit_currency_id'  => 1,

                'credit_account_id'  => $creditContractAmount,
                'credit_partner_id'  => $ruleContractAmount->resolveCreditPartnerId($contract) ?? null,
                'credit_currency_id' => 1,

                'amount_amd'         => $contract->provided_amount,

                'comment'            => 'contract_payment',
                'user_id'            => auth()->id(),
                'is_system'          => false,

                'disbursement_date'    =>  $contract->date,
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id'   => $journalDoc->id,
                'contract_id'          => $contract->id,
            ]);
            $reservePercent = $client->classification->reserve_percent;
            $reserveAmount = $reservePercent/100 * $contract->provided_amount;

            if ($reserveAmount > 0)
            {
                $nextDocNum++;

                $reserveDocumentType = $client->classification->name == 'standard' ?
                    DocumentJournal::RESERVE_GENERAL_AMOUNT: DocumentJournal::RESERVE_SPECIAL_AMOUNT;

                if ($contract->client->classification->name == 'standard') {

                    $ruleReserve = PostingRule::where('business_event_filter', 'reserve_general_amount')
                        ->first();

                    if (!$ruleReserve) {
                        throw new \RuntimeException('Posting rule for reserve_general_amount not found');
                    }

                    $debitReserve = $ruleReserve->debit_account_id;
                    $creditReserve = $ruleReserve->credit_account_id;
                } else {
                    $ruleReserve = PostingRule::where('business_event_filter', 'reserve_special_amount')
                        ->first();

                    if (!$ruleReserve) {
                        throw new \RuntimeException('Posting rule for reserve_special_amount not found');
                    }

                    $debitReserve = $ruleReserve->debit_account_id;
                    $creditReserve = $ruleReserve->credit_account_id;
                }
                $reserveJournal = DocumentJournal::create([
                        'date'               => $contract->date,
                        'document_number'    => $nextDocNum,
                        'document_type'      => $reserveDocumentType,
                        'amount_amd'         => $reserveAmount,
                        'debit_partner_id'   => $ruleReserve->resolveDebitPartnerId($contract) ?? null,
                        'credit_partner_id'  => $ruleReserve->resolveCreditPartnerId($contract) ?? $clientId,
                        'comment'            => "General reserve for contract #{$contract->id} on disbursement",
                        'debit_account_id'   => $debitReserve,
                        'credit_account_id'  => $creditReserve,
                        'user_id'            => auth()->id(),
                        'journalable_type'   => DocumentJournal::class,
                        'journalable_id'     => $journalDoc->id,
                        'contract_id'        => $contract->id,
                    ]);

                Transaction::create([
                    'date'               => $contract->date,
                    'document_number'    => $nextDocNum,
                    'document_type'      => $reserveDocumentType,

                    'debit_account_id'   => $debitReserve,
                    'debit_partner_id'   => $ruleReserve->resolveDebitPartnerId($contract) ?? null,
                    'debit_currency_id'  => 1,

                    'credit_account_id'  => $creditReserve,
                    'credit_currency_id' => 1,
                    'credit_partner_id'  => $ruleReserve->resolveCreditPartnerId($contract) ?? $clientId,

                    'amount_amd'         => $reserveAmount,

                    'comment'            => "General reserve for contract #{$contract->id}",
                    'user_id'            => auth()->id(),
                    'is_system'          => true,

                    'disbursement_date'    => $contract->date->toDateString(),
                    'transactionable_type' => DocumentJournal::class,
                    'transactionable_id'   => $reserveJournal->id,
                    'contract_id'          => $contract->id,
                ]);
                if (!$oldClassification) {
                    ClassificationHistory::create([
                        'client_id' => $client->id,
                        'classification_id' => $classification->id,
                        'risk_weight' => $client->classification?->risk_weight ?? 0,
                        'reserve_percent' => $client->classification?->reserve_percent ?? 0,
                        'comment' => 'Client classification update when contract is created',
                        'actionable_type' => DocumentJournal::class,
                        'actionable_id' => $reserveJournal->id,
                        'user_id' => auth()->id() ?? 1,
                        'date' => now(),
                    ]);

                }

            }

            if ($client->classification->name === 'loss') {
                $acc16200NV = ChartOfAccount::idByCode('16200NV');
                $acc16200   = ChartOfAccount::idByCode('16200');
                $acc16201NI = ChartOfAccount::idByCode('16201NI');
                $acc16605PS = ChartOfAccount::idByCode('16605PS');
                $acc86000   = ChartOfAccount::idByCode('86000');
                $acc86001   = ChartOfAccount::idByCode('86001');

                $debit16200NV  = DocumentJournal::where('journalable_type', Contract::class)
                    ->where('journalable_id', $contract->id)
                    ->where('debit_account_id', $acc16200NV)->sum('amount_amd');
                $credit16200NV = DocumentJournal::where('journalable_type', DocumentJournal::class)
                    ->where('journalable_id', $journalDoc->id)
                    ->where('credit_account_id', $acc16200NV)->sum('amount_amd');
                $net16200NV = $debit16200NV - $credit16200NV;

                $debit16200  = DocumentJournal::where('journalable_type', DocumentJournal::class)
                    ->where('journalable_id', $journalDoc->id)
                    ->where('debit_account_id', $acc16200)->sum('amount_amd');
                $credit16200 = DocumentJournal::where('journalable_type', DocumentJournal::class)
                    ->where('journalable_id', $journalDoc->id)
                    ->where('credit_account_id', $acc16200)->sum('amount_amd');
                $net16200 = $debit16200 - $credit16200;

                $debit16201NI  = DocumentJournal::where('journalable_type', DocumentJournal::class)
                    ->where('journalable_id', $journalDoc->id)
                    ->where('debit_account_id', $acc16201NI)->sum('amount_amd');
                $credit16201NI = DocumentJournal::where('journalable_type', DocumentJournal::class)
                    ->where('journalable_id', $journalDoc->id)
                    ->where('credit_account_id', $acc16201NI)->sum('amount_amd');
                $net16201NI = $debit16201NI - $credit16201NI;

                // ── Step 1: Net balance transfer ─────────────────────────────
                // Dr: Net Balance of (16200 + 16200NV + 16201NI) / Cr: 16605PS
                $totalNet = round($net16200 + $net16200NV + $net16201NI, 2);
                $currentPS = $this->getClientReserveBalance($clientId, $acc16605PS);
                $diff = round($totalNet + $currentPS, 2);
                if (abs($diff) >= 0.01) {
                    $ruleStep1 = PostingRule::where('business_event_filter', 'loss_writeoff_net_transfer')->firstOrFail();
                    $nextDocNum = Transaction::getNextDocumentNumber();
                    $debitAcc  = $diff > 0 ? $ruleStep1->debit_account_id : $acc16605PS;
                    $creditAcc = $diff > 0 ? $acc16605PS : $ruleStep1->debit_account_id;
                    $step1Doc = DocumentJournal::create([
                        'date'              => $contract->date,
                        'document_number'   => $nextDocNum,
                        'document_type'     => DocumentJournal::LOSS_WRITEOFF_NET_TRANSFER,
                        'amount_amd'        => abs($diff),
                        'debit_partner_id'  => $ruleStep1->resolveDebitPartnerId($contract) ?? $clientId,
                        'credit_partner_id' => $ruleStep1->resolveCreditPartnerId($contract) ?? $clientId,
                        'comment'           => "Loss write-off net balance transfer for contract #{$contract->id}",
                        'debit_account_id'  => $debitAcc,
                        'credit_account_id' => $creditAcc,
                        'user_id'           => auth()->id(),
                        'journalable_type'  => DocumentJournal::class,
                        'journalable_id'    => $journalDoc->id,
                        'contract_id'       => $contract->id,
                    ]);
                    Transaction::create([
                        'date'                 => $contract->date,
                        'document_number'      => $nextDocNum,
                        'document_type'        => DocumentJournal::LOSS_WRITEOFF_NET_TRANSFER,
                        'debit_account_id'     => $debitAcc,
                        'debit_partner_id'     => $ruleStep1->resolveDebitPartnerId($contract) ?? $clientId,
                        'debit_currency_id'    => 1,
                        'credit_account_id'    => $creditAcc,
                        'credit_currency_id'   => 1,
                        'credit_partner_id'    => $ruleStep1->resolveCreditPartnerId($contract) ?? $clientId,
                        'amount_amd'           => abs($diff),
                        'comment'              => "Loss write-off net balance transfer for contract #{$contract->id}",
                        'user_id'              => auth()->id(),
                        'is_system'            => true,
                        'disbursement_date'    => $contract->date->toDateString(),
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id'   => $step1Doc->id,
                        'contract_id'          => $contract->id,
                    ]);
                }

                // ── Step 2: Individual account transfers ─────────────────────
                if (abs($net16200) >= 0.01) {
                    $nextDocNum = Transaction::getNextDocumentNumber();
                    $dAcc16200  = $net16200 > 0 ? $acc16605PS : $acc16200;
                    $cAcc16200  = $net16200 > 0 ? $acc16200   : $acc16605PS;
                    $lossEff16200Doc = DocumentJournal::create([
                        'date'              => $contract->date,
                        'document_number'   => $nextDocNum,
                        'document_type'     => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                        'amount_amd'        => abs($net16200),
                        'debit_partner_id'  => $clientId,
                        'credit_partner_id' => $clientId,
                        'comment'           => "Write-off 16200 for contract #{$contract->id} - loss client disbursement",
                        'debit_account_id'  => $dAcc16200,
                        'credit_account_id' => $cAcc16200,
                        'user_id'           => auth()->id(),
                        'journalable_type'  => DocumentJournal::class,
                        'journalable_id'    => $journalDoc->id,
                        'contract_id'       => $contract->id,
                    ]);
                    Transaction::create([
                        'date'                 => $contract->date,
                        'document_number'      => $nextDocNum,
                        'document_type'        => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                        'debit_account_id'     => $dAcc16200,
                        'debit_partner_id'     => $clientId,
                        'debit_currency_id'    => 1,
                        'credit_account_id'    => $cAcc16200,
                        'credit_currency_id'   => 1,
                        'credit_partner_id'    => $clientId,
                        'amount_amd'           => abs($net16200),
                        'comment'              => "Write-off 16200 for contract #{$contract->id} - loss client disbursement",
                        'user_id'              => auth()->id(),
                        'is_system'            => true,
                        'disbursement_date'    => $contract->date->toDateString(),
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id'   => $lossEff16200Doc->id,
                        'contract_id'          => $contract->id,
                    ]);
                }

                if (abs($net16200NV) >= 0.01) {
                    $nextDocNum = Transaction::getNextDocumentNumber();
                    $dAcc16200NV = $net16200NV > 0 ? $acc16605PS : $acc16200NV;
                    $cAcc16200NV = $net16200NV > 0 ? $acc16200NV : $acc16605PS;
                    $lossNVDoc = DocumentJournal::create([
                        'date'              => $contract->date,
                        'document_number'   => $nextDocNum,
                        'document_type'     => DocumentJournal::LOSS_RESERVE_AMOUNT,
                        'amount_amd'        => abs($net16200NV),
                        'debit_partner_id'  => $clientId,
                        'credit_partner_id' => $clientId,
                        'comment'           => "Write-off 16200NV for contract #{$contract->id} - loss client disbursement",
                        'debit_account_id'  => $dAcc16200NV,
                        'credit_account_id' => $cAcc16200NV,
                        'user_id'           => auth()->id(),
                        'journalable_type'  => DocumentJournal::class,
                        'journalable_id'    => $journalDoc->id,
                        'contract_id'       => $contract->id,
                    ]);
                    Transaction::create([
                        'date'                 => $contract->date,
                        'document_number'      => $nextDocNum,
                        'document_type'        => DocumentJournal::LOSS_RESERVE_AMOUNT,
                        'debit_account_id'     => $dAcc16200NV,
                        'debit_partner_id'     => $clientId,
                        'debit_currency_id'    => 1,
                        'credit_account_id'    => $cAcc16200NV,
                        'credit_currency_id'   => 1,
                        'credit_partner_id'    => $clientId,
                        'amount_amd'           => abs($net16200NV),
                        'comment'              => "Write-off 16200NV for contract #{$contract->id} - loss client disbursement",
                        'user_id'              => auth()->id(),
                        'is_system'            => true,
                        'disbursement_date'    => $contract->date->toDateString(),
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id'   => $lossNVDoc->id,
                        'contract_id'          => $contract->id,
                    ]);
                }

                $amount86000 = $net16200 + $net16200NV;
                if (abs($amount86000) >= 0.01) {
                    $rule86000 = PostingRule::where('business_event_filter', 'loss_writeoff_principal')->firstOrFail();
                    $nextDocNum = Transaction::getNextDocumentNumber();
                    $dAcc86000 = $amount86000 > 0 ? $rule86000->debit_account_id : $rule86000->credit_account_id;
                    $cAcc86000 = $amount86000 > 0 ? $rule86000->credit_account_id : $rule86000->debit_account_id;
                    $loss86000Doc = DocumentJournal::create([
                        'date'              => $contract->date,
                        'document_number'   => $nextDocNum,
                        'document_type'     => DocumentJournal::LOSS_RESERVE_AMOUNT,
                        'amount_amd'        => abs($amount86000),
                        'debit_partner_id'  => $rule86000->resolveDebitPartnerId($contract) ?? $clientId,
                        'credit_partner_id' => $rule86000->resolveCreditPartnerId($contract) ?? $clientId,
                        'comment'           => "Loss write-off expense 86000 for contract #{$contract->id} - loss client disbursement",
                        'debit_account_id'  => $dAcc86000,
                        'credit_account_id' => $cAcc86000,
                        'user_id'           => auth()->id(),
                        'journalable_type'  => DocumentJournal::class,
                        'journalable_id'    => $journalDoc->id,
                        'contract_id'       => $contract->id,
                    ]);
                    Transaction::create([
                        'date'                 => $contract->date,
                        'document_number'      => $nextDocNum,
                        'document_type'        => DocumentJournal::LOSS_RESERVE_AMOUNT,
                        'debit_account_id'     => $dAcc86000,
                        'debit_partner_id'     => $rule86000->resolveDebitPartnerId($contract) ?? $clientId,
                        'debit_currency_id'    => 1,
                        'credit_account_id'    => $cAcc86000,
                        'credit_currency_id'   => 1,
                        'credit_partner_id'    => $rule86000->resolveCreditPartnerId($contract) ?? $clientId,
                        'amount_amd'           => abs($amount86000),
                        'comment'              => "Loss write-off expense 86000 for contract #{$contract->id} - loss client disbursement",
                        'user_id'              => auth()->id(),
                        'is_system'            => true,
                        'disbursement_date'    => $contract->date->toDateString(),
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id'   => $loss86000Doc->id,
                        'contract_id'          => $contract->id,
                    ]);
                }

                if (abs($net16201NI) >= 0.01) {
                    $nextDocNum = Transaction::getNextDocumentNumber();
                    $dAcc16201NI = $net16201NI > 0 ? $acc16605PS : $acc16201NI;
                    $cAcc16201NI = $net16201NI > 0 ? $acc16201NI : $acc16605PS;
                    $lossNIDoc = DocumentJournal::create([
                        'date'              => $contract->date,
                        'document_number'   => $nextDocNum,
                        'document_type'     => DocumentJournal::LOSS_RESERVE,
                        'amount_amd'        => abs($net16201NI),
                        'debit_partner_id'  => $clientId,
                        'credit_partner_id' => $clientId,
                        'comment'           => "Write-off 16201NI for contract #{$contract->id} - loss client disbursement",
                        'debit_account_id'  => $dAcc16201NI,
                        'credit_account_id' => $cAcc16201NI,
                        'user_id'           => auth()->id(),
                        'journalable_type'  => DocumentJournal::class,
                        'journalable_id'    => $journalDoc->id,
                        'contract_id'       => $contract->id,
                    ]);
                    Transaction::create([
                        'date'                 => $contract->date,
                        'document_number'      => $nextDocNum,
                        'document_type'        => DocumentJournal::LOSS_RESERVE,
                        'debit_account_id'     => $dAcc16201NI,
                        'debit_partner_id'     => $clientId,
                        'debit_currency_id'    => 1,
                        'credit_account_id'    => $cAcc16201NI,
                        'credit_currency_id'   => 1,
                        'credit_partner_id'    => $clientId,
                        'amount_amd'           => abs($net16201NI),
                        'comment'              => "Write-off 16201NI for contract #{$contract->id} - loss client disbursement",
                        'user_id'              => auth()->id(),
                        'is_system'            => true,
                        'disbursement_date'    => $contract->date->toDateString(),
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id'   => $lossNIDoc->id,
                        'contract_id'          => $contract->id,
                    ]);

                    $rule86001 = PostingRule::where('business_event_filter', 'loss_writeoff_interest')->firstOrFail();
                    $nextDocNum = Transaction::getNextDocumentNumber();
                    $dAcc86001 = $net16201NI > 0 ? $rule86001->debit_account_id : $rule86001->credit_account_id;
                    $cAcc86001 = $net16201NI > 0 ? $rule86001->credit_account_id : $rule86001->debit_account_id;
                    $loss86001Doc = DocumentJournal::create([
                        'date'              => $contract->date,
                        'document_number'   => $nextDocNum,
                        'document_type'     => DocumentJournal::LOSS_RESERVE,
                        'amount_amd'        => abs($net16201NI),
                        'debit_partner_id'  => $rule86001->resolveDebitPartnerId($contract) ?? $clientId,
                        'credit_partner_id' => $rule86001->resolveCreditPartnerId($contract) ?? $clientId,
                        'comment'           => "Loss write-off expense 86001 for contract #{$contract->id} - loss client disbursement",
                        'debit_account_id'  => $dAcc86001,
                        'credit_account_id' => $cAcc86001,
                        'user_id'           => auth()->id(),
                        'journalable_type'  => DocumentJournal::class,
                        'journalable_id'    => $journalDoc->id,
                        'contract_id'       => $contract->id,
                    ]);
                    Transaction::create([
                        'date'                 => $contract->date,
                        'document_number'      => $nextDocNum,
                        'document_type'        => DocumentJournal::LOSS_RESERVE,
                        'debit_account_id'     => $dAcc86001,
                        'debit_partner_id'     => $rule86001->resolveDebitPartnerId($contract) ?? $clientId,
                        'debit_currency_id'    => 1,
                        'credit_account_id'    => $cAcc86001,
                        'credit_currency_id'   => 1,
                        'credit_partner_id'    => $rule86001->resolveCreditPartnerId($contract) ?? $clientId,
                        'amount_amd'           => abs($net16201NI),
                        'comment'              => "Loss write-off expense 86001 for contract #{$contract->id} - loss client disbursement",
                        'user_id'              => auth()->id(),
                        'is_system'            => true,
                        'disbursement_date'    => $contract->date->toDateString(),
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id'   => $loss86001Doc->id,
                        'contract_id'          => $contract->id,
                    ]);
                }
            }

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

    public function reprovideContractAmount(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'contract_id'     => 'required|integer|exists:contracts,id',
            'amount'          => 'nullable|numeric|min:0.01',
            'reprovide_date'  => 'required|date',
            'months'          => 'nullable|integer|min:1|max:360',
        ]);

        $contract = Contract::findOrFail($validatedData['contract_id']);
        $client = $contract->client;
        $client->loadMissing('classification');

        if (!$contract->provided_at) {
            return response()->json(['message' => 'Initial disbursement has not occurred yet.'], 422);
        }

        if ($contract->status === Contract::STATUS_EXECUTED) {
            return response()->json(['message' => 'Cannot re-provide on an executed contract.'], 422);
        }

        if ($contract->contract_amount === null) {
            return response()->json(['message' => 'Contract amount ceiling is not defined.'], 422);
        }

        $providedAmount = (float) $contract->provided_amount;
        $contractAmount = (float) $contract->contract_amount;

        if ($providedAmount >= $contractAmount) {
            return response()->json(['message' => 'Provided amount already equals contract amount.'], 422);
        }

        $statusAllowed = $contract->status === Contract::STATUS_INITIAL
            || ($contract->status === Contract::STATUS_COMPLETED && $providedAmount < $contractAmount);

        if (!$statusAllowed) {
            return response()->json(['message' => 'Contract status does not allow re-provide.'], 422);
        }

        $maxReprovide = $contractAmount - $providedAmount;
        $delta = isset($validatedData['amount'])
            ? (float) $validatedData['amount']
            : $maxReprovide;

        if ($delta <= 0 || $delta > $maxReprovide + 0.001) {
            return response()->json([
                'message' => "Re-provide amount must be between 0 and {$maxReprovide}.",
            ], 422);
        }

        $reprovideDate = Carbon::parse($validatedData['reprovide_date'], 'Asia/Yerevan')->format('Y-m-d');
        $contractStart = Carbon::parse($contract->date, 'Asia/Yerevan')->startOfDay();
        $deadline = Carbon::parse($contract->deadline, 'Asia/Yerevan')->startOfDay();
        $today = Carbon::now('Asia/Yerevan')->startOfDay();
        $maxDate = $deadline->lt($today) ? $deadline : $today;

        $lastCompletedDate = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', Contract::STATUS_COMPLETED)
            ->max('date');

        $minDate = $contractStart;
        if ($lastCompletedDate) {
            $lastCompleted = Carbon::parse($lastCompletedDate, 'Asia/Yerevan')->startOfDay();
            if ($lastCompleted->gt($minDate)) {
                $minDate = $lastCompleted;
            }
        }

        $reprovideCarbon = Carbon::parse($reprovideDate, 'Asia/Yerevan')->startOfDay();
        if ($reprovideCarbon->lt($minDate) || $reprovideCarbon->gt($maxDate)) {
            return response()->json([
                'message' => 'Re-provide date is outside the allowed range.',
            ], 422);
        }

        // Outstanding interest check (must pass before touching the DB)
        $overpaidBeforeReprovide = $this->contractCalculationService
            ->calculatePaidVsAccruedInterestDifference($contract, Carbon::parse($reprovideDate, 'Asia/Yerevan'));
        $outstandingDebt = max(0.0, -$overpaidBeforeReprovide);

        dd($outstandingDebt,$overpaidBeforeReprovide);
        if ($outstandingDebt > 20000) {
            return response()->json([
                'message' => 'Outstanding interest (' . number_format($outstandingDebt, 2) . ' AMD) exceeds the 20,000 AMD limit for re-provide.',
            ], 422);
        }

        if ($overpaidBeforeReprovide > 20000) {
            return response()->json([
                'message' => 'Overpaid interest (' . number_format($overpaidBeforeReprovide, 2) . ' AMD) exceeds the 20,000 AMD limit for re-provide.',
            ], 422);
        }

        $penaltyResult = $this->countPenalty($contract->id, $reprovideDate);
        if ($penaltyResult['penalty_amount'] > 0) {
            return response()->json([
                'message' => 'Contract has outstanding penalties. Please clear them before re-providing.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $client_name = $client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : '');
            $oldProvidedAmount = $providedAmount;
            $category_id = $contract->category_id;

            $deal_id = $this->createReprovideOrderAndHistory(
                $contract,
                $delta,
                $client->id,
                $client_name,
                $category_id,
                $reprovideDate
            );

            $contract->historyContext = [
                'deal_id'     => $deal_id,
                'date'        => $reprovideDate,
                'pawnshop_id' => auth()->user()->pawnshop_id ?? 1,
            ];
            $contract->provided_amount = $providedAmount + $delta;
            $contract->mother = (float) $contract->mother + $delta;
            $contract->left = (float) $contract->left + $delta;

            if ($contract->status === Contract::STATUS_COMPLETED) {
                $contract->status = Contract::STATUS_INITIAL;
                $contract->closed_at = null;
            }

            if (!empty($validatedData['months'])) {
                $newMonths = (int) $validatedData['months'];
                $contract->deadline_days = (string) $newMonths;
                $contract->deadline = Carbon::parse($reprovideDate, 'Asia/Yerevan')
                    ->addMonths($newMonths)
                    ->format('Y-m-d');
            }

            $contract->save();

            $journalDoc = $this->postReprovideDisbursementJournal(
                $contract,
                $client,
                $delta,
                $reprovideDate,
                $deal_id
            );

            $this->postReprovideReserveJournal($contract, $client, $journalDoc, $delta, $reprovideDate);

            if ($client->classification && $client->classification->name === 'loss') {
                $this->processLossClientDisbursement($contract, $client, $journalDoc, $reprovideDate);
            }

            $freshContract = $contract->fresh();
            $overpaid = $this->contractCalculationService
                ->calculatePaidVsAccruedInterestDifference($freshContract, Carbon::parse($reprovideDate));
            $this->contractService->rebuildScheduleFromDate($freshContract, $reprovideDate, $deal_id, $overpaid, $outstandingDebt);
            Modification::create([
                'subject_type'      => Contract::class,
                'subject_id'        => $contract->id,
                'modification_type' => 'Modificator',
                'field_code'        => 'PrincipalAmount',
                'element_code'      => 'Amount',
                'old_value'         => (string) $oldProvidedAmount,
                'new_value'         => (string) ($oldProvidedAmount + $delta),
                'effective_date'    => $reprovideDate,
            ]);

            DealAction::create([
                'deal_id'         => $deal_id,
                'actionable_id'   => $contract->id,
                'actionable_type' => Contract::class,
                'amount'          => $delta,
                'type'            => 'reprovide',
                'description'     => 'Reprovide contract amount',
                'date'            => $reprovideDate,
            ]);

            $this->activityService->log(
                'reprovide_contract_amount',
                "Re-provide: {$delta} AMD for contract #{$contract->id} (deal #{$deal_id})",
                Contract::class,
                $contract->id
            );

            DB::commit();

            $successPayload = [
                'message'     => 'Contract amount re-provided successfully',
                'contract_id' => $contract->id,
                'delta'       => $delta,
            ];

            $idempotencyKey = $request->header('Idempotency-Key');
            if ($idempotencyKey) {
                IdempotencyKey::where('key', $idempotencyKey)->update([
                    'status_code' => 200,
                    'response'    => json_encode($successPayload),
                    'locked_at'   => null,
                ]);
            }

            return response()->json($successPayload, 200);
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error processing re-provide',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function getContractAccountNet(int $contractId, int $accountId): float
    {
        $debits = (float) DocumentJournal::where('contract_id', $contractId)
            ->where('debit_account_id', $accountId)
            ->sum('amount_amd');
        $credits = (float) DocumentJournal::where('contract_id', $contractId)
            ->where('credit_account_id', $accountId)
            ->sum('amount_amd');

        if ($debits == 0.0 && $credits == 0.0) {
            $debits = (float) DocumentJournal::where('journalable_type', Contract::class)
                ->where('journalable_id', $contractId)
                ->where('debit_account_id', $accountId)
                ->sum('amount_amd');
            $provideJournalIds = DocumentJournal::where('journalable_type', Contract::class)
                ->where('journalable_id', $contractId)
                ->pluck('id');
            if ($provideJournalIds->isNotEmpty()) {
                $credits = (float) DocumentJournal::whereIn('journalable_id', $provideJournalIds)
                    ->where('journalable_type', DocumentJournal::class)
                    ->where('credit_account_id', $accountId)
                    ->sum('amount_amd');
            }
        }

        return $debits - $credits;
    }

    private function postReprovideDisbursementJournal(
        Contract $contract,
        Client $client,
        float $amount,
        string $date,
        int $deal_id
    ): DocumentJournal {
        $ruleContractAmount = PostingRule::where('business_event_filter', 'provide_contract_amount')
            ->firstOrFail();

        $nextDocNum = Transaction::getNextDocumentNumber();
        $document_type = DocumentJournal::PROVIDE_CONTRACT_AMOUNT;
        $clientId = $contract->client_id;

        $journalDoc = DocumentJournal::create([
            'date'               => $date,
            'document_number'    => $nextDocNum,
            'document_type'      => $document_type,
            'amount_amd'         => $amount,
            'debit_partner_id'   => $ruleContractAmount->resolveDebitPartnerId($contract) ?? $clientId,
            'credit_partner_id'  => $ruleContractAmount->resolveCreditPartnerId($contract) ?? null,
            'comment'            => 'contract_reprovide',
            'debit_account_id'   => $ruleContractAmount->debit_account_id,
            'credit_account_id'  => $ruleContractAmount->credit_account_id,
            'user_id'            => auth()->id(),
            'journalable_type'   => Contract::class,
            'journalable_id'     => $contract->id,
            'deal_id'            => $deal_id,
            'contract_id'        => $contract->id,
        ]);

        Transaction::create([
            'date'                 => $date,
            'document_number'      => $nextDocNum,
            'document_type'        => $document_type,
            'debit_account_id'     => $ruleContractAmount->debit_account_id,
            'debit_partner_id'     => $ruleContractAmount->resolveDebitPartnerId($contract) ?? $clientId,
            'debit_currency_id'    => 1,
            'credit_account_id'    => $ruleContractAmount->credit_account_id,
            'credit_partner_id'    => $ruleContractAmount->resolveCreditPartnerId($contract) ?? null,
            'credit_currency_id'   => 1,
            'amount_amd'           => $amount,
            'comment'              => 'contract_reprovide',
            'user_id'              => auth()->id(),
            'is_system'            => false,
            'disbursement_date'    => $date,
            'transactionable_type' => DocumentJournal::class,
            'transactionable_id'   => $journalDoc->id,
            'contract_id'          => $contract->id,
        ]);

        return $journalDoc;
    }

    private function postReprovideReserveJournal(
        Contract $contract,
        Client $client,
        DocumentJournal $journalDoc,
        float $delta,
        string $date
    ): void {
        if (!$client->classification) {
            return;
        }

        $reservePercent = $client->classification->reserve_percent;
        $reserveAmount = $reservePercent / 100 * $delta;

        if ($reserveAmount <= 0) {
            return;
        }

        $nextDocNum = Transaction::getNextDocumentNumber();
        $clientId = $contract->client_id;

        $reserveDocumentType = $client->classification->name === 'standard'
            ? DocumentJournal::RESERVE_GENERAL_AMOUNT
            : DocumentJournal::RESERVE_SPECIAL_AMOUNT;

        $ruleFilter = $client->classification->name === 'standard'
            ? 'reserve_general_amount'
            : 'reserve_special_amount';

        $ruleReserve = PostingRule::where('business_event_filter', $ruleFilter)->firstOrFail();

        $reserveJournal = DocumentJournal::create([
            'date'               => $date,
            'document_number'    => $nextDocNum,
            'document_type'      => $reserveDocumentType,
            'amount_amd'         => $reserveAmount,
            'debit_partner_id'   => $ruleReserve->resolveDebitPartnerId($contract) ?? null,
            'credit_partner_id'  => $ruleReserve->resolveCreditPartnerId($contract) ?? $clientId,
            'comment'            => "Reserve for contract #{$contract->id} on re-provide",
            'debit_account_id'   => $ruleReserve->debit_account_id,
            'credit_account_id'  => $ruleReserve->credit_account_id,
            'user_id'            => auth()->id(),
            'journalable_type'   => DocumentJournal::class,
            'journalable_id'     => $journalDoc->id,
            'contract_id'        => $contract->id,
        ]);

        Transaction::create([
            'date'                 => $date,
            'document_number'      => $nextDocNum,
            'document_type'        => $reserveDocumentType,
            'debit_account_id'     => $ruleReserve->debit_account_id,
            'debit_partner_id'     => $ruleReserve->resolveDebitPartnerId($contract) ?? null,
            'debit_currency_id'    => 1,
            'credit_account_id'    => $ruleReserve->credit_account_id,
            'credit_currency_id'   => 1,
            'credit_partner_id'    => $ruleReserve->resolveCreditPartnerId($contract) ?? $clientId,
            'amount_amd'           => $reserveAmount,
            'comment'              => "Reserve for contract #{$contract->id} on re-provide",
            'user_id'              => auth()->id(),
            'is_system'            => true,
            'disbursement_date'    => $date,
            'transactionable_type' => DocumentJournal::class,
            'transactionable_id'   => $reserveJournal->id,
            'contract_id'          => $contract->id,
        ]);
    }

    private function processLossClientDisbursement(
        Contract $contract,
        Client $client,
        DocumentJournal $journalDoc,
        string $date
    ): void {
        $clientId = $contract->client_id;

        $acc16200NV = ChartOfAccount::idByCode('16200NV');
        $acc16200   = ChartOfAccount::idByCode('16200');
        $acc16201NI = ChartOfAccount::idByCode('16201NI');
        $acc16605PS = ChartOfAccount::idByCode('16605PS');

        $net16200NV = $this->getContractAccountNet($contract->id, $acc16200NV);
        $net16200   = $this->getContractAccountNet($contract->id, $acc16200);
        $net16201NI = $this->getContractAccountNet($contract->id, $acc16201NI);

        $totalNet = round($net16200 + $net16200NV + $net16201NI, 2);
        $currentPS = $this->getClientReserveBalance($clientId, $acc16605PS);
        $diff = round($totalNet + $currentPS, 2);

        if (abs($diff) >= 0.01) {
            $ruleStep1 = PostingRule::where('business_event_filter', 'loss_writeoff_net_transfer')->firstOrFail();
            $nextDocNum = Transaction::getNextDocumentNumber();
            $debitAcc  = $diff > 0 ? $ruleStep1->debit_account_id : $acc16605PS;
            $creditAcc = $diff > 0 ? $acc16605PS : $ruleStep1->debit_account_id;
            $step1Doc = DocumentJournal::create([
                'date'              => $date,
                'document_number'   => $nextDocNum,
                'document_type'     => DocumentJournal::LOSS_WRITEOFF_NET_TRANSFER,
                'amount_amd'        => abs($diff),
                'debit_partner_id'  => $ruleStep1->resolveDebitPartnerId($contract) ?? $clientId,
                'credit_partner_id' => $ruleStep1->resolveCreditPartnerId($contract) ?? $clientId,
                'comment'           => "Loss write-off net balance transfer for contract #{$contract->id} (re-provide)",
                'debit_account_id'  => $debitAcc,
                'credit_account_id' => $creditAcc,
                'user_id'           => auth()->id(),
                'journalable_type'  => DocumentJournal::class,
                'journalable_id'    => $journalDoc->id,
                'contract_id'       => $contract->id,
            ]);
            Transaction::create([
                'date'                 => $date,
                'document_number'      => $nextDocNum,
                'document_type'        => DocumentJournal::LOSS_WRITEOFF_NET_TRANSFER,
                'debit_account_id'     => $debitAcc,
                'debit_partner_id'     => $ruleStep1->resolveDebitPartnerId($contract) ?? $clientId,
                'debit_currency_id'    => 1,
                'credit_account_id'    => $creditAcc,
                'credit_currency_id'   => 1,
                'credit_partner_id'    => $ruleStep1->resolveCreditPartnerId($contract) ?? $clientId,
                'amount_amd'           => abs($diff),
                'comment'              => "Loss write-off net balance transfer for contract #{$contract->id} (re-provide)",
                'user_id'              => auth()->id(),
                'is_system'            => true,
                'disbursement_date'    => $date,
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id'   => $step1Doc->id,
                'contract_id'          => $contract->id,
            ]);
        }

        if (abs($net16200) >= 0.01) {
            $nextDocNum = Transaction::getNextDocumentNumber();
            $dAcc16200  = $net16200 > 0 ? $acc16605PS : $acc16200;
            $cAcc16200  = $net16200 > 0 ? $acc16200   : $acc16605PS;
            $lossEff16200Doc = DocumentJournal::create([
                'date'              => $date,
                'document_number'   => $nextDocNum,
                'document_type'     => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                'amount_amd'        => abs($net16200),
                'debit_partner_id'  => $clientId,
                'credit_partner_id' => $clientId,
                'comment'           => "Write-off 16200 for contract #{$contract->id} - loss client re-provide",
                'debit_account_id'  => $dAcc16200,
                'credit_account_id' => $cAcc16200,
                'user_id'           => auth()->id(),
                'journalable_type'  => DocumentJournal::class,
                'journalable_id'    => $journalDoc->id,
                'contract_id'       => $contract->id,
            ]);
            Transaction::create([
                'date'                 => $date,
                'document_number'      => $nextDocNum,
                'document_type'        => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                'debit_account_id'     => $dAcc16200,
                'debit_partner_id'     => $clientId,
                'debit_currency_id'    => 1,
                'credit_account_id'    => $cAcc16200,
                'credit_currency_id'   => 1,
                'credit_partner_id'    => $clientId,
                'amount_amd'           => abs($net16200),
                'comment'              => "Write-off 16200 for contract #{$contract->id} - loss client re-provide",
                'user_id'              => auth()->id(),
                'is_system'            => true,
                'disbursement_date'    => $date,
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id'   => $lossEff16200Doc->id,
                'contract_id'          => $contract->id,
            ]);
        }

        if (abs($net16200NV) >= 0.01) {
            $nextDocNum = Transaction::getNextDocumentNumber();
            $dAcc16200NV = $net16200NV > 0 ? $acc16605PS : $acc16200NV;
            $cAcc16200NV = $net16200NV > 0 ? $acc16200NV : $acc16605PS;
            $lossNVDoc = DocumentJournal::create([
                'date'              => $date,
                'document_number'   => $nextDocNum,
                'document_type'     => DocumentJournal::LOSS_RESERVE_AMOUNT,
                'amount_amd'        => abs($net16200NV),
                'debit_partner_id'  => $clientId,
                'credit_partner_id' => $clientId,
                'comment'           => "Write-off 16200NV for contract #{$contract->id} - loss client re-provide",
                'debit_account_id'  => $dAcc16200NV,
                'credit_account_id' => $cAcc16200NV,
                'user_id'           => auth()->id(),
                'journalable_type'  => DocumentJournal::class,
                'journalable_id'    => $journalDoc->id,
                'contract_id'       => $contract->id,
            ]);
            Transaction::create([
                'date'                 => $date,
                'document_number'      => $nextDocNum,
                'document_type'        => DocumentJournal::LOSS_RESERVE_AMOUNT,
                'debit_account_id'     => $dAcc16200NV,
                'debit_partner_id'     => $clientId,
                'debit_currency_id'    => 1,
                'credit_account_id'    => $cAcc16200NV,
                'credit_currency_id'   => 1,
                'credit_partner_id'    => $clientId,
                'amount_amd'           => abs($net16200NV),
                'comment'              => "Write-off 16200NV for contract #{$contract->id} - loss client re-provide",
                'user_id'              => auth()->id(),
                'is_system'            => true,
                'disbursement_date'    => $date,
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id'   => $lossNVDoc->id,
                'contract_id'          => $contract->id,
            ]);
        }

        $amount86000 = $net16200 + $net16200NV;
        if (abs($amount86000) >= 0.01) {
            $rule86000 = PostingRule::where('business_event_filter', 'loss_writeoff_principal')->firstOrFail();
            $nextDocNum = Transaction::getNextDocumentNumber();
            $dAcc86000 = $amount86000 > 0 ? $rule86000->debit_account_id : $rule86000->credit_account_id;
            $cAcc86000 = $amount86000 > 0 ? $rule86000->credit_account_id : $rule86000->debit_account_id;
            $loss86000Doc = DocumentJournal::create([
                'date'              => $date,
                'document_number'   => $nextDocNum,
                'document_type'     => DocumentJournal::LOSS_RESERVE_AMOUNT,
                'amount_amd'        => abs($amount86000),
                'debit_partner_id'  => $rule86000->resolveDebitPartnerId($contract) ?? $clientId,
                'credit_partner_id' => $rule86000->resolveCreditPartnerId($contract) ?? $clientId,
                'comment'           => "Loss write-off expense 86000 for contract #{$contract->id} - loss client re-provide",
                'debit_account_id'  => $dAcc86000,
                'credit_account_id' => $cAcc86000,
                'user_id'           => auth()->id(),
                'journalable_type'  => DocumentJournal::class,
                'journalable_id'    => $journalDoc->id,
                'contract_id'       => $contract->id,
            ]);
            Transaction::create([
                'date'                 => $date,
                'document_number'      => $nextDocNum,
                'document_type'        => DocumentJournal::LOSS_RESERVE_AMOUNT,
                'debit_account_id'     => $dAcc86000,
                'debit_partner_id'     => $rule86000->resolveDebitPartnerId($contract) ?? $clientId,
                'debit_currency_id'    => 1,
                'credit_account_id'    => $cAcc86000,
                'credit_currency_id'   => 1,
                'credit_partner_id'    => $rule86000->resolveCreditPartnerId($contract) ?? $clientId,
                'amount_amd'           => abs($amount86000),
                'comment'              => "Loss write-off expense 86000 for contract #{$contract->id} - loss client re-provide",
                'user_id'              => auth()->id(),
                'is_system'            => true,
                'disbursement_date'    => $date,
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id'   => $loss86000Doc->id,
                'contract_id'          => $contract->id,
            ]);
        }

        if (abs($net16201NI) >= 0.01) {
            $nextDocNum = Transaction::getNextDocumentNumber();
            $dAcc16201NI = $net16201NI > 0 ? $acc16605PS : $acc16201NI;
            $cAcc16201NI = $net16201NI > 0 ? $acc16201NI : $acc16605PS;
            $lossNIDoc = DocumentJournal::create([
                'date'              => $date,
                'document_number'   => $nextDocNum,
                'document_type'     => DocumentJournal::LOSS_RESERVE,
                'amount_amd'        => abs($net16201NI),
                'debit_partner_id'  => $clientId,
                'credit_partner_id' => $clientId,
                'comment'           => "Write-off 16201NI for contract #{$contract->id} - loss client re-provide",
                'debit_account_id'  => $dAcc16201NI,
                'credit_account_id' => $cAcc16201NI,
                'user_id'           => auth()->id(),
                'journalable_type'  => DocumentJournal::class,
                'journalable_id'    => $journalDoc->id,
                'contract_id'       => $contract->id,
            ]);
            Transaction::create([
                'date'                 => $date,
                'document_number'      => $nextDocNum,
                'document_type'        => DocumentJournal::LOSS_RESERVE,
                'debit_account_id'     => $dAcc16201NI,
                'debit_partner_id'     => $clientId,
                'debit_currency_id'    => 1,
                'credit_account_id'    => $cAcc16201NI,
                'credit_currency_id'   => 1,
                'credit_partner_id'    => $clientId,
                'amount_amd'           => abs($net16201NI),
                'comment'              => "Write-off 16201NI for contract #{$contract->id} - loss client re-provide",
                'user_id'              => auth()->id(),
                'is_system'            => true,
                'disbursement_date'    => $date,
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id'   => $lossNIDoc->id,
                'contract_id'          => $contract->id,
            ]);

            $rule86001 = PostingRule::where('business_event_filter', 'loss_writeoff_interest')->firstOrFail();
            $nextDocNum = Transaction::getNextDocumentNumber();
            $dAcc86001 = $net16201NI > 0 ? $rule86001->debit_account_id : $rule86001->credit_account_id;
            $cAcc86001 = $net16201NI > 0 ? $rule86001->credit_account_id : $rule86001->debit_account_id;
            $loss86001Doc = DocumentJournal::create([
                'date'              => $date,
                'document_number'   => $nextDocNum,
                'document_type'     => DocumentJournal::LOSS_RESERVE,
                'amount_amd'        => abs($net16201NI),
                'debit_partner_id'  => $rule86001->resolveDebitPartnerId($contract) ?? $clientId,
                'credit_partner_id' => $rule86001->resolveCreditPartnerId($contract) ?? $clientId,
                'comment'           => "Loss write-off expense 86001 for contract #{$contract->id} - loss client re-provide",
                'debit_account_id'  => $dAcc86001,
                'credit_account_id' => $cAcc86001,
                'user_id'           => auth()->id(),
                'journalable_type'  => DocumentJournal::class,
                'journalable_id'    => $journalDoc->id,
                'contract_id'       => $contract->id,
            ]);
            Transaction::create([
                'date'                 => $date,
                'document_number'      => $nextDocNum,
                'document_type'        => DocumentJournal::LOSS_RESERVE,
                'debit_account_id'     => $dAcc86001,
                'debit_partner_id'     => $rule86001->resolveDebitPartnerId($contract) ?? $clientId,
                'debit_currency_id'    => 1,
                'credit_account_id'    => $cAcc86001,
                'credit_currency_id'   => 1,
                'credit_partner_id'    => $rule86001->resolveCreditPartnerId($contract) ?? $clientId,
                'amount_amd'           => abs($net16201NI),
                'comment'              => "Loss write-off expense 86001 for contract #{$contract->id} - loss client re-provide",
                'user_id'              => auth()->id(),
                'is_system'            => true,
                'disbursement_date'    => $date,
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id'   => $loss86001Doc->id,
                'contract_id'          => $contract->id,
            ]);
        }
    }

    public function regenerateSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
            'start_date'  => 'nullable|date',
        ]);

        DB::beginTransaction();
        try {
            $contract = Contract::findOrFail($validated['contract_id']);

            if ($contract->status === Contract::STATUS_EXECUTED) {
                return response()->json([
                    'message' => 'Cannot regenerate schedule for an executed contract.',
                ], 422);
            }

            $startDate = !empty($validated['start_date'])
                ? Carbon::parse($validated['start_date'], 'Asia/Yerevan')->format('Y-m-d')
                : Carbon::now('Asia/Yerevan')->format('Y-m-d');

            $months = $this->contractService->regenerateSchedule($contract, $startDate);

            DB::commit();

            return response()->json([
                'message'     => 'Schedule regenerated successfully.',
                'contract_id' => $contract->id,
                'start_date'  => $startDate,
                'months'      => $months,
            ]);
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error regenerating schedule',
                'error'   => $e->getMessage(),
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

        $this->activityService->log(
            'update_contract_number',
            "Updated contract number to {$validatedData['contract_number']}",
            Contract::class,
            $contract->id
        );

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

    public function exportContracts()
    {
        $this->activityService->log(
            'export_contracts',
            "Export all contracts",
        );

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
        $this->activityService->log(
            'calculate_interest',
            "Calculated interest {$contract->calculatedInterest}, calculated effective {$contract->calculatedEffectiveInterest} for contract #{$contract->id}",
            Contract::class,
            $contract->id
        );
        return response()->json([
            'calculated_interest' => $contract->calculatedInterest,
            'calculated_effective_interest' => $contract->calculatedEffectiveInterest,
        ]);
    }

    public function getInterestBalance(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
            'date'        => 'required|date',
        ]);

        $contract = Contract::findOrFail($request->contract_id);
        $date     = Carbon::parse($request->date, 'Asia/Yerevan')->startOfDay();

        $difference = $this->contractCalculationService
            ->calculatePaidVsAccruedInterestDifference($contract, $date);

        return response()->json([
            'interest_balance' => $difference,
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


        $documentTypeInterest = DocumentJournal::INTEREST_RATE_AMOUNT;
        $documentTypeEffective = DocumentJournal::EFFECTIVE_RATE_AMOUNT;
        $ruleEffective = PostingRule::where('business_event_filter', 'effective_rate_amount')
            ->first();

        if (!$ruleEffective) {
            throw new \RuntimeException('Posting rule for effective_rate_amount not found');
        }

        $debitEffective = $ruleEffective->debit_account_id;
        $creditEffective =  $ruleEffective->credit_account_id;

        $ruleInterest = PostingRule::where('business_event_filter', 'interest_rate_amount')
            ->first();

        if (!$ruleInterest) {
            throw new \RuntimeException('Posting rule for interest_rate_amount not found');
        }

        $debitInterest = $ruleInterest->debit_account_id;
        $creditInterest =  $ruleInterest->credit_account_id;

        $debetPartnerId = $contract->client_id;
        $date = Carbon::parse($request->calc_date, 'Asia/Yerevan')->startOfDay();;
        $systemUserId = auth()->check() ? auth()->id() : 1;
        $journal = DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->first();
        DB::beginTransaction();
        try {
            $nextDocNum = Transaction::getNextDocumentNumber();

            if ($request->calculated_interest > 0) {
                $journalInterest = DocumentJournal::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentTypeInterest,
                    'amount_amd' => $request->calculated_interest,
                    'debit_partner_id' => $ruleInterest->resolveDebitPartnerId($contract) ?? $debetPartnerId,
                    'credit_partner_id' => $ruleInterest->resolveCreditPartnerId($contract) ?? $debetPartnerId,
                    'comment' => 'Confirmed interest for contract #' . $contract->id,
                    'debit_account_id' => $debitInterest,
                    'credit_account_id' => $creditInterest,
                    'user_id' => $systemUserId,
                    'journalable_type' => DocumentJournal::class,
                    'journalable_id' => $journal->id,
                    'contract_id' => $contract->id,
                ]);

                Transaction::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentTypeInterest,
                    'debit_account_id' => $debitInterest,
                    'debit_partner_id' => $ruleInterest->resolveDebitPartnerId($contract) ?? $debetPartnerId,
                    'debit_currency_id' => 1,
                    'credit_account_id' => $creditInterest,
                    'credit_currency_id' => 1,
                    'credit_partner_id' => $ruleInterest->resolveCreditPartnerId($contract) ?? $debetPartnerId,
                    'amount_amd' => $request->calculated_interest,
                    'comment' => 'Confirmed interest for contract #' . $contract->id,
                    'user_id' => $systemUserId,
                    'is_system' => true,
                    'disbursement_date' => $date,
                    'transactionable_type' => DocumentJournal::class,
                    'transactionable_id' => $journalInterest->id,
                    'contract_id' => $contract->id,
                ]);

                $nextDocNum++;
            }

            if ($request->calculated_effective_interest > 0) {
                $journalEffective = DocumentJournal::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentTypeEffective,
                    'amount_amd' => $request->calculated_effective_interest,
                    'debit_partner_id' => $ruleEffective->resolveDebitPartnerId($contract) ?? $debetPartnerId,
                    'credit_partner_id' => $ruleEffective->resolveCreditPartnerId($contract) ?? $creditPartnerId,
                    'comment' => 'Confirmed effective interest for contract #' . $contract->id,
                    'debit_account_id' => $debitEffective,
                    'credit_account_id' => $creditEffective,
                    'user_id' => $systemUserId,
                    'journalable_type' => DocumentJournal::class,
                    'journalable_id' => $journal->id,
                    'contract_id' => $contract->id,
                ]);

                Transaction::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentTypeEffective,
                    'debit_account_id' => $debitEffective,
                    'debit_partner_id' => $ruleEffective->resolveDebitPartnerId($contract) ?? $debetPartnerId,
                    'debit_currency_id' => 1,
                    'credit_account_id' => $creditEffective,
                    'credit_currency_id' => 1,
                    'credit_partner_id' => $ruleEffective->resolveCreditPartnerId($contract) ?? $creditPartnerId,
                    'amount_amd' => $request->calculated_effective_interest,
                    'comment' => 'Confirmed effective interest for contract #' . $contract->id,
                    'user_id' => $systemUserId,
                    'is_system' => true,
                    'disbursement_date' => $date,
                    'transactionable_type' => DocumentJournal::class,
                    'transactionable_id' => $journalEffective->id,
                    'contract_id' => $contract->id,
                ]);

                $nextDocNum++;
                $effectiveInterest = 0.0;
                $contractJournal = DocumentJournal::where('journalable_type', Contract::class)
                    ->where('journalable_id', $contract->id)
                    ->first();

                if ($contractJournal) {
                    $effectiveInterest = (float) DocumentJournal::where('document_type', DocumentJournal::EFFECTIVE_RATE_AMOUNT)
                        ->where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $contractJournal->id)
                        ->sum('amount_amd');
                }

                $reserveAmount = $request->calculated_effective_interest * $contract->client->classification->reserve_percent / 100;
                $clientId = $contract->client_id;

                $documentTypeReserve = $contract->client->classification->name == 'standard' ? DocumentJournal::RESERVE_GENERAL_AMOUNT : DocumentJournal::RESERVE_SPECIAL_AMOUNT;

                if ($contract->client->classification->name == 'standard') {

                    $ruleReserve = PostingRule::where('business_event_filter', 'reserve_general_amount')
                        ->first();

                    if (!$ruleReserve) {
                        throw new \RuntimeException('Posting rule for reserve_general_amount not found');
                    }

                    $debitReserve = $ruleReserve->debit_account_id;
                    $creditReserve = $ruleReserve->credit_account_id;
                } else {
                    $ruleReserve = PostingRule::where('business_event_filter', 'reserve_special_amount')
                        ->first();

                    if (!$ruleReserve) {
                        throw new \RuntimeException('Posting rule for reserve_special_amount not found');
                    }

                    $debitReserve = $ruleReserve->debit_account_id;
                    $creditReserve = $ruleReserve->credit_account_id;
                }
                $journalDoc = DocumentJournal::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentTypeReserve,
                    'amount_amd' => $reserveAmount,
                    'debit_partner_id' => $ruleReserve->resolveDebitPartnerId($contract) ?? null,
                    'credit_partner_id' => $ruleReserve->resolveCreditPartnerId($contract) ?? $clientId,
                    'comment' => "Old reserve for contract #{$contract->id} due to classification change",
                    'debit_account_id' => $debitReserve,
                    'credit_account_id' => $creditReserve,
                    'user_id' => auth()->check() ? auth()->id() : 1,
                    'journalable_type' => DocumentJournal::class,
                    'journalable_id' => $journal?->id,
                    'contract_id' => $contract->id,
                ]);

                Transaction::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentTypeReserve,
                    'debit_account_id' => $debitReserve,
                    'debit_partner_id' => $ruleReserve->resolveDebitPartnerId($contract) ?? null,
                    'debit_currency_id' => 1,
                    'credit_account_id' => $creditReserve,
                    'credit_currency_id' => 1,
                    'credit_partner_id' => $ruleReserve->resolveCreditPartnerId($contract) ?? $clientId,
                    'amount_amd' => $reserveAmount,
                    'comment' => "Old reserve for contract #{$contract->id}",
                    'user_id' => auth()->check() ? auth()->id() : 1,
                    'is_system' => true,
                    'disbursement_date' => now()->toDateString(),
                    'transactionable_type' => DocumentJournal::class,
                    'transactionable_id' => $journalDoc->id,
                    'contract_id' => $contract->id,
                ]);

                $nextDocNum++;
            }

            $this->activityService->log(
                'confirm_interest',
                "Confirmed interest for contract #{$contract->id} (interest: {$request->calculated_interest}, effective: {$request->calculated_effective_interest})",
                Contract::class,
                $contract->id
            );
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
        $this->activityService->log(
            'summary_contracts',
            "Summary contracts to {$calcToday}"
        );
        $contracts = Contract::with([
            'client.classification',
            'payments',
        ])
            ->whereNotNull('provided_at')
            ->get();

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

            $contract->setAttribute('provided_amount', $contract->provided_amount);

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
