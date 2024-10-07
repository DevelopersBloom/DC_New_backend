<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Http\Requests\ContractRequest;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ContractResource;
use App\Services\ClientService;
use App\Services\ContractService;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContractControllerNew extends Controller
{
    protected ClientService $clientService;
    protected ContractService $contractService;
    protected FileService $fileService;
    public function __construct(ClientService $clientService, ContractService $contractService,FileService $fileService)
    {
        $this->clientService = $clientService;
        $this->contractService = $contractService;
        $this->fileService = $fileService;
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

            // If the provided amount is more than 20000, update the client with bank info
            if ($contract->provided_amount > 20000) {
                $this->clientService->updateBankInfo(
                    $client->id,
                    $clientData['bank_name'] ?? 'ameria',
                    $clientData['card_number'] ?? '55448556151521',
                    $clientData['account_number'] ?? null,
                    $clientData['iban'] ?? null
                );
            }
            // Create payments for the contract
            DB::commit();
            return new ContractResource($contract->load(['client', 'items', 'files']));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error processing the request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}
