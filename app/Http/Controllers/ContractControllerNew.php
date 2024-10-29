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
use App\Models\LumpRate;
use App\Models\Order;
use App\Services\ClientService;
use App\Services\ContractService;
use App\Services\FileService;
use App\Traits\ContractTrait;
use App\Traits\OrderTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
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
    public function show($id)
    {

        $contract = Contract::with([
            'client',
            'payments' => function ($query) {
                $query->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') ASC");},
            'history' => function ($query) {
                $query->with(['type', 'user', 'order'])->orderBy('id', 'DESC');
            },
            'items'
        ])->findOrFail($id);
        $currentPaymentAmount = $this->calculateCurrentPayment($contract);
        $contract->current_payment_amount = $currentPaymentAmount['current_amount'];
        $contract->penalty_amount  = $currentPaymentAmount['penalty_amount'];

        return new ContractDetailResource($contract);
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
            $cash = $contract->provided_amount < 20000 ? "true" : "false";

            $this->createOrderAndHistory($contract, $client_name, $cash,$category_id);

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

    /**
     * Helper method to create order and history entries
     */
    private function createOrderAndHistory($contract, $client_name, $cash,$category_id)
    {
        $historyTypes = HistoryType::whereIn('name', ['opening', 'one_time_payment', 'mother_payment'])->get();
        $lump_rate = LumpRate::getRateByCategoryAndAmount($category_id, $contract->provided_amount);
        $lump_amount = $contract->provided_amount * ($lump_rate->lump_rate / 100);

        $this->createOrderHistoryEntry($contract, $client_name, 'out', 'opening', $contract->provided_amount, $cash, 'վարկ');
        $this->createOrderHistoryEntry($contract, $client_name, 'in', 'one_time_payment', $lump_amount, $cash, 'Միանվագ վճար');
        $this->createOrderHistoryEntry($contract, $client_name, 'out', 'mother_payment', $contract->provided_amount, $cash, 'ՄԳ տրամադրում');
    }

    /**
     * Helper method to create individual order and history entries
     */
    private function createOrderHistoryEntry($contract, $client_name, $type, $historyTypeName, $amount, $cash, $purpose)
    {
        $order_id = $this->getOrder($cash, $type);

        // Create an order
        $order = Order::create([
            'contract_id' => $contract->id,
            'type' => $type,
            'title' => 'Օրդեր',
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $amount,
            'rep_id' => '2211',
            'date' => Carbon::now()->format('d.m.Y'),
            'client_name' => $client_name,
            'purpose' => $purpose,
        ]);

        // Add history for the order
        $historyType = HistoryType::where('name', $historyTypeName)->first();
        History::create([
            'type_id' => $historyType->id,
            'contract_id' => $contract->id,
            'user_id' => auth()->user()->id,
            'order_id' => $order->id,
            'date' => Carbon::parse($contract->created_at)->setTimezone('Asia/Yerevan')->format('Y.m.d'),
            'amount' => $amount,
        ]);
        if ($historyTypeName !== 'opening') {
            // Create a deal for the order
            $this->createDeal($amount, $type, $contract->id, $order->id, $cash, $purpose);
        }
    }

    public function createDeal($amount,$type,$contract_id,$order_id = null,$cash = true,$purpose = null,$receiver = null,$source = null){
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
        ]);
    }

}
