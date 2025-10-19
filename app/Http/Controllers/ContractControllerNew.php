<?php

namespace App\Http\Controllers;

use App\Exports\ContractsExport;
use App\Exports\DailyExport;
use App\Http\Requests\ClientRequest;
use App\Http\Requests\ContractRequest;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ContractDetailResource;
use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\Deal;
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
//    public function show($id)
//    {
//        $contract = Contract::with([
//            'client',
//            'payments' => function ($query) {
//                $query->orderBy('to_date', 'ASC');
//            },
//            'history' => function ($query) {
//                $query->whereDoesntHave('order', function ($q) {
//                    $q->where('filter', Order::REFUND_LUMP_FILTER);
//                })
//                    ->with(['type', 'user', 'order'])
//                    ->orderBy('id', 'DESC');
//            },
//            'items',
//            'files',
//            'deals',
//
//        ])->withMax('payments', 'to_date')
//            ->withMin('payments', 'to_date')
//            ->findOrFail($id);
//        $currentPaymentAmount = $this->calculateCurrentPayment($contract);
//        $contract->current_payment_amount = $currentPaymentAmount['current_amount'];
//        $contract->penalty_amount  = $currentPaymentAmount['penalty_amount'];
//        $contract->effectiveRate = 0;
//        if ($contract->payment_type == 'amortized') {
//            $contract->effectiveRate = $this->effectiveRateService->calculateEffectiveRate($contract);
//        }
//        $unearnedInterest = 0;
//        if ($contract->payment_type === 'amortized') {
//            $unearnedInterest = $contract->payments
//                ->where('status', 'initial')
//                ->sum(function ($p) {
//                    return max(0, (float)($p->interest_payment ?? 0));
//                });
//        } else {
//            $unearnedInterest = $contract->payments
//                ->sum(function ($p) {
//                    return max(0, (float)($p->amount ?? 0));
//                });
//        }
//        $contract->unearned_interest = round($unearnedInterest, 2);
//        $writtenOff = null;
//        if (($contract->client->classification ?? null) === 'loss') {
//            if ($contract->payment_type === 'amortized') {
//                $row = $contract->payments
//                    ->where('status', 'initial')
//                    ->sortBy('to_date')
//                    ->first();
//
//                if (!$row) {
//                    $row = $contract->payments->sortByDesc('to_date')->first();
//                }
//
//                $writtenOff = max(0, (float)($row->remaining ?? 0));
//            } else {
//                $row = $contract->payments->firstWhere('last_payment', 1);
//
//                $writtenOff = max(0, (float)($row->mother ?? 0));
//            }
//        }
//        $contract->written_off_amount = $writtenOff !== null ? round($writtenOff, 2) : null;
//
//        return new ContractDetailResource($contract);
//    }

//    public function show1($id,Request $request)
//    {
//        $contract = Contract::with([
//            'client',
//            'payments' => function ($query) { $query->orderBy('to_date', 'ASC'); },
//            'history' => function ($query) {
//                $query->whereDoesntHave('order', function ($q) {
//                    $q->where('filter', Order::REFUND_LUMP_FILTER);
//                })
//                    ->with(['type', 'user', 'order'])
//                    ->orderBy('id', 'DESC');
//            },
//            'items',
//            'files',
//            'deals',
//        ])
//            ->withMax('payments', 'to_date')
//            ->withMin('payments', 'to_date')
//            ->findOrFail($id);
//
//        $currentPaymentAmount = $this->calculateCurrentPayment($contract);
//
//        $contract->current_payment_amount = $currentPaymentAmount['current_amount'];
//        $contract->penalty_amount         = $currentPaymentAmount['penalty_amount'];
//
//        //effective-interest amount
//        $calculationDate = $request->input('calculation_date');
//
//        $startDate = Carbon::parse($contract->date, 'Asia/Yerevan')->startOfDay();
//
//        $endDate = $calculationDate
//            ? Carbon::parse($calculationDate, 'Asia/Yerevan')->startOfDay()
//            : Carbon::now('Asia/Yerevan')->startOfDay();
//
//        $days = $endDate->diffInDays($startDate);
//
//        $contract->effectiveRate = 0;
//        if ($contract->payment_type == 'amortized') {
//            $contract->effectiveRate = $this->effectiveRateService->calculateEffectiveRate($contract);
//        }
//        $calculatedInterest = null;
//        $calculatedEffectiveInterest = null;
//        if (!empty($contract->provided_amount) && !empty($contract->interest_rate)) {
//            $calculatedInterest = $this->contractService->calcAmount(
//                $contract->provided_amount,
//                $days,
//                $contract->interest_rate
//            );
//            $calculatedEffectiveInterest = $this->contractService->calcAmount(
//                $contract->provided_amount,
//                $days,
//                $contract->effectiveRate
//            );
//        }
//        $contract->calculatedInterest = $calculatedInterest;
//        $contract->calculatedEffectiveInterest = $calculatedEffectiveInterest;
//
//        $unearnedInterest = 0; //Չվաստակած տոկոս
//        if ($contract->payment_type === 'amortized') {
//            $unearnedInterest = $contract->payments
//                ->where('status', 'initial')
//                ->sum(fn($p) => max(0, (float)($p->interest_payment ?? 0)));
//        } else {
//            $unearnedInterest = $contract->payments
//                ->sum(fn($p) => max(0, (float)($p->amount ?? 0)));
//        }
//        $contract->unearned_interest = round($unearnedInterest, 2);
//
//        $writtenOff = null; //Դուրս գրված գումար
//        if (($contract->client->classification ?? null) === 'loss') {
//            $writtenOff = max(0, (float)($contract->provided_amount ?? 0));
//        }
//        $contract->written_off_amount = $writtenOff !== null ? round($writtenOff, 2) : null;
//
//        // ԴՈՒՐՍ ԳՐՎԱԾ ՏՈԿՈՍ
//        $writtenOffInterest = null;
//        if (!empty($contract->written_off_amount) && !empty($contract->interest_rate)) {
//
//            $writeOffDate =Carbon::parse($contract->date, 'Asia/Yerevan')->startOfDay();
//            $deadline = $contract->deadline ? Carbon::parse($contract->deadline, 'Asia/Yerevan')->startOfDay() : null;
//            $days = 0;
//            if ($deadline) {
//                $days = $writeOffDate->lt($deadline) ? $writeOffDate->diffInDays($deadline) : 0;
//            }
//            $writtenOffInterest = $this->calcAmount(
//                $contract->written_off_amount,
//                $days,
//                $contract->interest_rate
//            );
//        }
//        $contract->written_off_interest = $writtenOffInterest;
//
//        $overdueAmount = 0.0; //Ժամկետանց գումար;
//        $today = Carbon::now('Asia/Yerevan')->startOfDay();
//
//        if ($contract->payment_type === 'amortized') {
//            $overdueAmount = $contract->payments
//                ->where('status', 'initial')
//                ->filter(fn($p) =>
//                Carbon::parse($p->to_date)->startOfDay()->lt($today)
//                )
//                ->sum(fn($p) => max(0, (float)($p->principal_payment ?? 0)));
//
//        } elseif ($contract->payment_type === 'classic') {
//
//            $overduePayment = $contract->payments
//                ->where('status', 'initial')
//                ->where('last_payment', 1)
//                ->first();
//
//            if ($overduePayment) {
//                $dueDate = Carbon::parse($overduePayment->to_date)->startOfDay();
//
//                if ($dueDate->lt($today)) {
//                    $overdueAmount = max(0, (float)($overduePayment->mother ?? 0));
//                }
//            }
//        }
//        $contract->overdue_amount = round($overdueAmount, 2);
//        $classificationData = $this->clientClassificationService->getClassificationData($contract);
//
//        $contract->reserve     = $classificationData['reserve'];
//        $contract->risk_weight = $classificationData['riskWeight'];
//
//        return new ContractDetailResource($contract);
//        //Ժամկետանց Տոկոս
//        $overdueInterest = 0;
//
//        if ($contract->payment_type === 'amortized') {
//            $overdueInterest = $contract->payments
//                ->where('status', 'initial')
//                ->filter(fn($p) =>
//                Carbon::parse($p->to_date)->startOfDay()->lt($today)
//                )
//                ->sum(fn($p) => max(0, (float)($p->interest_payment ?? 0)));
//
//        } elseif ($contract->payment_type === 'classic') {
//            $overdueInterest = $contract->payments
//                ->where('status', 'initial')
//                ->filter(fn($p) =>
//                Carbon::parse($p->to_date)->startOfDay()->lt($today)
//                )
//                ->sum(fn($p) => max(0, (float)($p->amount ?? 0)));
//        }
//
//        $contract->overdue_interest = round($overdueInterest, 2);
//
//        // Ժամկետանց Գումարի Տոկոս
//        $overdueAmountInterest = null;
//
//        if (!empty($contract->overdue_amount) && !empty($contract->interest_rate)) {
//            $today = Carbon::now('Asia/Yerevan')->startOfDay();
//
//            $lastDue = null;
//            if ($contract->payment_type === 'classic') {
//                $lastRow = $contract->payments->firstWhere('last_payment', 1)
//                    ?: $contract->payments->sortByDesc('to_date')->first();
//                $lastDue = $lastRow?->to_date;
//            } else {
//                $lastDue = $contract->payments_max_to_date ?: $contract->payments->max('to_date');
//            }
//
//            $days = 0;
//            if ($lastDue) {
//                $due = Carbon::parse($lastDue, 'Asia/Yerevan')->startOfDay();
//
//                if ($today->gt($due)) {
//                    $days = $due->diffInDays($today);
//                }
//            }
//
//            $overdueAmountInterest = $this->calcAmount(
//                $contract->overdue_amount,
//                $days,
//                $contract->interest_rate
//            );
//        }
//
//        $contract->overdue_amount_interest = $overdueAmountInterest;
//        return new ContractDetailResource($contract);
//    }

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
            $deadline = Carbon::now('Asia/Yerevan')->addDays($contractRequest->validated()['deadline'])->format('Y-m-d H:i:s');
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
            $contract->deadline = Carbon::now('Asia/Yerevan')->addDays($contract->deadline_days)->format('Y-m-d H:i:s');
            $contract->date = Carbon::now();
            $contract->save();
            $transactionDocumentNumber = (Transaction::max('document_number') ?? 0) + 1;

            $this->contractService->createPayment($contract);
            $deal_id = $this->createOrderAndHistory($contract, $client->id, $client_name, $cash, $category_id);
            $acc1100 = ChartOfAccount::idByCode('1100');
            $acc2220 = ChartOfAccount::idByCode('2220');
            Transaction::create([
                'date'              => $contract->date,
                'document_type'     => Transaction::CONTRACT_PAYMENT,
                'document_number'   => $transactionDocumentNumber,
                'debit_account_id'  => $acc1100,
                'credit_account_id' => $acc2220,
                'currency_id'       => 1, //testing
                'amount_amd'        => $contract->provided_amount,
                'comment'           => 'contract_payment',
                'debit_partner_id' => 2,//testing
                'credit_partner_id' => $contract->client_id,
                'transactionable_id' => $deal_id,
                'transactionable_type' => Deal::class,
            ]);

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


}
