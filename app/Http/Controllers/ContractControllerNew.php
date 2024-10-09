<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Http\Requests\ContractRequest;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ContractDetailResource;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\History;
use App\Models\HistoryType;
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
    public function show($id): ContractDetailResource
    {
        $contract = Contract::with([
            'client',
            'payments' => function ($query) {
                $query->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') ASC");},
            'history' => function ($query) {
                $query->with(['type'])->orderBy('id', 'ASC');},
            'items'
        ])->findOrFail($id);
        return new ContractDetailResource($contract);
    }


    public function store(ClientRequest $clientRequest, ContractRequest $contractRequest,ItemRequest $itemRequest): JsonResponse|JsonResource
    {
        DB::beginTransaction();
        try {
            $client = $this->clientService->storeOrUpdate($clientRequest->validated());
            $deadline = Carbon::now('Asia/Yerevan')->addDays($contractRequest->validated()['deadline'])->format('Y-m-d H:i:s');
            $contract = $this->contractService->createContract($client->id,$contractRequest->validated(),$deadline);

            // $contract = $this->contractService->createContract($client->id, $contractRequest->validated());
            $items = $itemRequest->validated()['items'];

            foreach ($items as $item_data) {
                $this->contractService->storeContractItem($contract->id, $item_data);
            }
            $filesData = $contractRequest->all()['files'];
            if ($filesData) {
                $this->fileService->uploadContractFiles($contract->id, $filesData);
            }
            $this->contractService->createPayment($contract);
            $history_type = HistoryType::where('name','opening')->first();
            $client_name = $client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : '');
            $cash = $contract->provided_amount < 20000 ? "true" : "false";
            $order_id = $this->getOrder($cash, 'out');

            // Create an "out" order
            $outOrder = Order::create([
                'contract_id' => $contract->id,
                'type' => 'out',
                'title' => 'Օրդեր',
                'pawnshop_id' => auth()->user()->pawnshop_id,
                'order' => $order_id,
                'amount' => $contract->provided_amount,
                'rep_id' => '2211',
                'date' => Carbon::now()->format('d.m.Y'),
                'client_name' => $client_name,
                'purpose' => 'վարկ',
            ]);

            // Add history for "out" order
            History::create([
                'type_id' => $history_type->id,
                'contract_id' => $contract->id,
                'user_id' => auth()->user()->id,
                'order_id' => $outOrder->id,
                'date' => Carbon::parse($contract->created_at)->setTimezone('Asia/Yerevan')->format('Y.m.d'),
                'amount' => $contract->provided_amount,
            ]);
            $this->createDeal($contract->provided_amount, 'out', $contract->id, $outOrder->id, $cash, 'Գրավ');


            // Create payments for the contract
            DB::commit();

            return new ContractResource($contract->load(['client', 'items', 'files','payments','history']));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error processing the request',
                'error' => $e->getMessage(),
            ], 500);
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
