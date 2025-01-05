<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Http\Requests\ContractRequest;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ContractDetailResource;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\History;
use App\Models\HistoryType;
use App\Services\ClientService;
use App\Services\ContractService;
use App\Services\FileService;
use App\Traits\ContractTrait;
use App\Traits\OrderTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ContractControllerNew extends Controller
{
    use ContractTrait, OrderTrait;
    protected ClientService $clientService;
    protected ContractService $contractService;
    protected FileService $fileService;
    public function __construct(ClientService $clientService, ContractService $contractService,FileService $fileService)
    {
        $this->clientService = $clientService;
        $this->contractService = $contractService;
        $this->fileService = $fileService;
    }
    public function get(Request $request): JsonResponse
    {
        $status = $request->input('status', 'all');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $num = $request->input('num');
        $query = Contract::where('pawnshop_id', Auth::user()->pawnshop_id)
            ->orderBy('created_at', 'DESC')
            ->with(['payments' => function($payment) {
                $payment->orderBy('date');
            }, 'client' => function($query) {
                $query->withCount('contracts');
            }]);
        // Apply status-based filters
        switch ($status) {
            case 'initial':
                $query->where('status', 'initial');
                break;
            case 'completed':
                $query->where('status', 'completed');
                break;
            case 'executed':
                $query->where('status', 'executed');
                break;
            case 'overdue':
                $query->whereDate('deadline', '<=', today());
                break;
            case 'todays':
                $query->whereHas('payments', function($q) {
                    $q->whereDate('date', today());
                });
                break;
        }
        if ($num) {
            $query->where('num', $num);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }
        // Paginate and process the additional fields
        $contracts = $query->paginate(10);
        foreach ($contracts as $contract) {
            if ($contract->category && $contract->evaluator) {
                $contract->category_title = $contract->category->title;
                $contract->evaluator_title = $contract->evaluator->full_name;
            }
        }

        $totalContracts = Contract::where('pawnshop_id', Auth::user()->pawnshop_id)->count();

        return response()->json([
            'contracts' => $contracts,
            'total' => $totalContracts
        ]);
    }
    public function show($id): ContractDetailResource
    {
        $contract = Contract::with([
            'client',
            'payments' => function ($query) {
                $query->orderBy('date', 'ASC');
            },
            'history' => function ($query) {
                $query->with(['type', 'user', 'order'])->orderBy('id', 'DESC');
            },
            'items',
            'files',
            'deals',

        ])->findOrFail($id);
        $currentPaymentAmount = $this->calculateCurrentPayment($contract);

        $contract->current_payment_amount = $currentPaymentAmount['current_amount'];
        $contract->penalty_amount  = $currentPaymentAmount['penalty_amount'];

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

            $deadline = Carbon::now('Asia/Yerevan')->addDays($contractRequest->validated()['deadline'])->format('Y-m-d H:i:s');
            $contract = $this->contractService->createContract($client->id, $contractRequest->validated(), $deadline);
            // Store contract items
            $items = $itemRequest->validated()['items'];
            foreach ($items as $item_data) {
                $category_id = $item_data['category_id'];
                $this->contractService->storeContractItem($contract->id, $item_data);
            }
            auth()->user()->pawnshop->given = auth()->user()->pawnshop->given + $contractRequest->provided_amount;
            auth()->user()->pawnshop->worth = auth()->user()->pawnshop->worth + $contractRequest->estimated_amount;
            auth()->user()->pawnshop->save();
            // Upload contract files if provided
            $filesData = $contractRequest->all()['files'] ?? null;
            if ($filesData) {
                $this->fileService->uploadContractFiles($contract->id, $filesData);
            }

            // Create contract payments
            $this->contractService->createPayment($contract);

            // Create orders and history entries
            $client_name = $client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : '');
            $cash = $contract->provided_amount < 20000 ? true : false;

            $this->createOrderAndHistory($contract,$client->id, $client_name, $cash,$category_id);

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


    public function createDeal1($amount,$type,$contract_id,$order_id = null,$cash = true,$purpose = null,$receiver = null,$source = null){
        if($type === 'in'){
            if($cash){
                auth()->user()->pawnshop->cashbox = auth()->user()->pawnshop->cashbox + $amount;
            }else{
                auth()->user()->pawnshop->bank_cashbox = auth()->user()->pawnshop->bank_cashbox + $amount;
            }
        }else{
            if($cash){
                auth()->user()->pawnshop->cashbox = auth()->user()->pawnshop->cashbox - $amount;
            }else{
                auth()->user()->pawnshop->bank_cashbox = auth()->user()->pawnshop->bank_cashbox - $amount;
            }

        }
        if($amount < 0){
            $type = $type === 'in' ? 'out' : 'in';
            $amount = -$amount;
        }

        auth()->user()->pawnshop->save();
        Deal::create([
            'type' => $type,
            'amount' => $amount,
            'date' => \Carbon\Carbon::now()->format('Y.m.d'),
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'contract_id' => $contract_id,
            'order_id' => $order_id,
            'cashbox' => auth()->user()->pawnshop->cashbox,
            'bank_cashbox' => auth()->user()->pawnshop->bank_cashbox,
            'worth' => auth()->user()->pawnshop->worth,
            'given' => auth()->user()->pawnshop->given,
            'purpose' => $purpose,
//            'cash' => $cash,
            'receiver' => $receiver,
            'source' => $source,
            'created_by' => auth()->user()->id,
        ]);
    }

}
