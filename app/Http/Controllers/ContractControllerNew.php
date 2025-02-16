<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Http\Requests\ContractRequest;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ContractDetailResource;
use App\Models\Contract;
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
        $filters = $request->only([
            'status', 'date_from', 'date_to', 'num',
            'provided_amount_from', 'provided_amount_to',
            'estimated_amount_from', 'estimated_amount_to',
            'name', 'surname', 'patronymic','passport','phone',
            'type','subspecies','model','delay'

        ]);
//        $contracts = $this->contractService->getContracts($filters);
//
//        return response()->json([
//            'contracts' => $contracts,
//            'total' => $contracts->total()
//        ]);
        $data = $this->contractService->getContracts($filters);

        return response()->json([
            'contracts' => $data['contracts'],
            'total' => $data['totalContracts'],
            'active' => $data['activeContracts'],
            'executed' => $data['executedContracts'],
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
            $pawnshop_id = \auth()->user()->pawnshop_id;
            $date = Carbon::now();
            $deadline = Carbon::now('Asia/Yerevan')->addDays($contractRequest->validated()['deadline'])->format('Y-m-d H:i:s');
            $contract = $this->contractService->createContract($client->id, $contractRequest->validated(), $deadline);
            // Store contract items
            $category_id = null;
            $items = $itemRequest->validated()['items'];
            foreach ($items as $item_data) {
                $category_id = $item_data['category_id'];
                $this->contractService->storeContractItem($contract->id, $item_data);
            }
            $contract->category_id = $category_id;
            $contract->save();
//            auth()->user()->pawnshop->given = auth()->user()->pawnshop->given + $contractRequest->provided_amount;
//            auth()->user()->pawnshop->worth = auth()->user()->pawnshop->worth + $contractRequest->estimated_amount;
//            auth()->user()->pawnshop->save();
            // Upload contract files if provided
            $filesData = $contractRequest->all()['files'] ?? null;
            if ($filesData) {
                $this->fileService->uploadContractFiles($contract->id, $filesData);
            }
            // Create contract payments
//            $this->contractService->createPayment($contract);

            // Create orders and history entries
            $client_name = $client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : '');
            $cash = $contract->provided_amount < 20000 ? true : false;
//            $this->createOrderAndHistory($contract,$client->id, $client_name, $cash,$category_id);
            $this->createOrderHistoryEntry($contract,$client->id, $client_name, 'out', 'opening', $contract->provided_amount, $cash, Contract::CONTRACT_OPENING,$contract->num,$pawnshop_id,$date);
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
        // Validate request data
        $validatedData = $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
        ]);

        DB::beginTransaction();
        try {
            $contract = Contract::findOrFail($validatedData['contract_id']);
            $client = $contract->client;
            $client_name = $client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : '');
            $cash = $contract->provided_amount < 20000;
            $category_id = $contract->category_id;
            // Update contract deadline and date
            $contract->deadline = Carbon::now('Asia/Yerevan')->addDays($contract->deadline_days)->format('Y-m-d H:i:s');
            $contract->date = Carbon::now();
            $contract->save();

            // Create contract payments
            $this->contractService->createPayment($contract);
            // Create order history
            $this->createOrderAndHistory($contract, $client->id, $client_name, $cash, $category_id);

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

}
