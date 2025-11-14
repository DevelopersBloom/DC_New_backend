<?php

namespace App\Http\Controllers;

use App\Exports\ClientsExport;
use App\Http\Requests\ClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\PartnerResource;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\ClientClassification;
use App\Models\ClassificationHistory;
use App\Models\ClientPawnshop;
use App\Models\Contract;
use App\Models\ContractReserveHistory;
use App\Models\DocumentJournal;
use App\Models\Transaction;
use App\Services\ClientClassificationService;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Exception;
use Maatwebsite\Excel\Facades\Excel;

class ClientControllerNew extends Controller
{
    protected ClientService $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function storeClient(ClientRequest $request): JsonResponse
    {
        return $this->storeClientData($request->validated(), true);
    }

    public function storeNonClient(ClientRequest $request): JsonResponse
    {
        return $this->storeClientData($request->validated(), false);
    }
    private function storeClientData(array $data, bool $hasContract): JsonResponse
    {
        $pawnshopId = Auth::user()->pawnshop_id ?? 1;
        $data['has_contract'] = $hasContract;

        $type = $data['type'] ?? 'individual';

        if ($type === 'individual' && !empty($data['passport_series'])) {
            $existing = Client::where('type', 'individual')
                ->where('passport_series', $data['passport_series'])
                ->first();

            if ($existing) {
                $alreadyLinked = $existing->pawnshopClients()
                    ->where('pawnshop_id', $pawnshopId)
                    ->exists();

                if ($alreadyLinked) {
                    return response()->json([
                        'message' => 'A client with this passport already exists in this pawnshop.'
                    ], 422);
                }
            }
        }

        if ($type === 'legal') {
            $existingQuery = Client::where('type', 'legal');

            if (!empty($data['tax_number'])) {
                $existingQuery->where('tax_number', $data['tax_number']);
            } elseif (!empty($data['company_name'])) {
                $existingQuery->where('company_name', $data['company_name']);
            }

            $existing = $existingQuery->first();

            if ($existing) {
                $alreadyLinked = $existing->pawnshopClients()
                    ->where('pawnshop_id', $pawnshopId)
                    ->exists();

                if ($alreadyLinked) {
                    return response()->json([
                        'message' => 'A legal client with the same identifier already exists in this pawnshop.'
                    ], 422);
                }
            }
        }

        DB::beginTransaction();
        try {
            /** @var ClientService $service */
            $service = app(ClientService::class);
            $client = $service->storeOrUpdate($data);

            ClientPawnshop::firstOrCreate([
                'client_id' => $client->id,
                'pawnshop_id' => $pawnshopId,
            ]);

            DB::commit();

            return response()->json([
                'message' => $hasContract ? 'Client added successfully' : 'Non-client added successfully',
                'data' => $client->fresh(),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create client',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
//    public function show(Request $request, int $clientId)
//    {
//
//        $status = $request->query('status', 'initial');
//
//        $clientInfo = $this->clientService->getClientInfo($clientId, $status);
//        return response()->json($clientInfo);
//
//    }
    public function show(Request $request, int $clientId)
    {
        $status = $request->query('status', 'initial');

        $client = Client::with(['contracts' => function ($query) use ($status) {
            $query->where('status', $status);
        },  'classification' ])->find($clientId);

        if (!$client) {
            return response()->json(['error' => 'Client not found'], 404);
        }

        return response()->json([
            'client' => $client,
            'contracts' => $client->contracts,
            'classification_title' => $client->classification?->title,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $pawnshopId = auth()->user()->pawnshop_id;
        $startOfMonth = now()->startOfMonth();
        $status = $request->query('status');

        $clients = Client::select([
            'id',  DB::raw("DATE_FORMAT(date, '%d-%m-%Y') as registration_date"), 'name', 'surname', 'middle_name', DB::raw("DATE_FORMAT(date_of_birth, '%d-%m-%Y') as date_of_birth"), 'country',
            'city', 'street', 'building', 'passport_series', 'passport_validity',
            'passport_issued', 'phone', 'additional_phone', 'email', 'has_contract','is_linked_to_company','is_company_employee'
        ])
        ->whereHas('pawnshopClients', function ($query) use ($pawnshopId) {
            $query->where('pawnshop_id', $pawnshopId);
        })->when($status, function ($query, $statusValue) {
            return $query->whereHas('classification', function ($q) use ($statusValue) {
                $q->where('name', $statusValue);
            });
        })
        ->filterByClient($request->only(['id','name', 'surname', 'patronymic', 'passport_series', 'phone', 'start_date', 'end_date','is_linked_to_company','is_company_employee']))
        ->orderByDesc('date')
        ->paginate(10);
//        ->get();

        $clientStats = Client::whereHas('pawnshopClients', function ($query) use ($pawnshopId) {
            $query->where('pawnshop_id', $pawnshopId);
        })
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN date >= ? THEN 1 ELSE 0 END) as new_clients', [$startOfMonth])
            ->selectRaw('SUM(CASE WHEN EXISTS (SELECT 1 FROM contracts WHERE clients.id = contracts.client_id AND contracts.status = ?) THEN 1 ELSE 0 END) as active_clients', ['initial'])
            ->first();

        return response()->json([
            'message' => 'Clients retrieved successfully',
            'data' => $clients,
            'total' => $clientStats->total ?? 0,
            'active' => $clientStats->active_clients ?? 0,
            'new' => $clientStats->new_clients ?? 0
        ]);
    }


    public function search(Request $request)
    {
        $fullName = $request->query('fullName');
        if (!$fullName) {
            return response()->json(['message' => 'fullName parameter is required'], 400);
        }

        $fullName = str_replace(' ', ' ', $fullName);
        $inputs = preg_split('/\s+/', trim($fullName));
        $firstInput = $inputs[0] ?? null;
        $secondInput = $inputs[1] ?? null;
        $clients = $this->clientService->search($firstInput, $secondInput);

        return ClientResource::collection($clients);
    }
    public function updateClientData(UpdateClientRequest $request, int $client_id): JsonResponse
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $client = Client::findOrFail($client_id);

            $newType = $data['type'] ?? $client->type;

            if ($newType === 'legal') {
                $data = array_merge([
                    'name' => null,
                    'surname' => null,
                    'middle_name' => null,
                    'passport_series' => null,
                    'passport_validity' => null,
                    'passport_issued' => null,
                    'date_of_birth' => null,
                ], $data);
            } else { // individual
                $data = array_merge([
                    'company_name' => null,
                    'legal_form' => null,
                    'tax_number' => null,
                    'state_register_number' => null,
                    'activity_field' => null,
                    'director_name' => null,
                    'accountant_info' => null,
                    'internal_code' => null,
                ], $data);
            }

            $client->fill($data)->save();

            $pawnshopId = Auth::user()->pawnshop_id ?? 1;
            ClientPawnshop::firstOrCreate([
                'client_id'   => $client->id,
                'pawnshop_id' => $pawnshopId,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Client data updated successfully',
                'client'  => $client->fresh(),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Client not found or update failed',
                'details' => $e->getMessage(),
            ], 400);
        }
    }
    /**
     */
    public function exportClients()
    {
        $pawnshopId = auth()->user()->pawnshop_id;

        return Excel::download(new ClientsExport($pawnshopId), 'clients.xlsx');
    }

    public function searchPartner(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $fullName = $request->query('fullName');

        if (!$fullName) {
            return response()->json(['message' => 'fullName parameter is required'], 400);
        }

        $fullName = str_replace(' ', ' ', $fullName);
        $inputs = preg_split('/\s+/', trim($fullName));
        $firstInput = $inputs[0] ?? null;
        $secondInput = $inputs[1] ?? null;
        $clients = $this->clientService->search($firstInput, $secondInput);

        return PartnerResource::collection($clients);
    }
    public function updateClientClassification(Request $request)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'classification' => 'required|string',
        ]);

        $clientId = $request->client_id;
        $client = Client::with(['contracts', 'classification'])->findOrFail($clientId);
        $classification = ClientClassification::where('name', $request->classification)->firstOrFail();
        $newClassificationId = $classification->id;
        $oldOrder = $client->classification?->order ?? 0;
        $newOrder = $classification->order ?? 0;

        if ($newOrder <= $oldOrder) {
            return response()->json(['message' => 'Classification not updated: new order is not higher.'], 200);
        }

        DB::beginTransaction();
        try {
            $oldClassificationName = $client->classification?->name;
            $oldClassificationId = $client->classification?->id;
            $oldClassificationOrder = $client->classification?->order;

            $oldReservePercent = $client->classification?->reserve_percent ?? 0;

            $client->classification_id = $newClassificationId;
            $client->save();
            $client->load('classification');
            $newReservePercent = $client->classification?->reserve_percent ?? 0;
            $newClassificationOrder =  $client->classification?->order;
            $newClassificationName =  $client->classification?->name;
            $clientId = $client->id;
            $diamondId = Client::where('company_name', 'Diamond Credit')->value('id') ?? 1;

            $acc73015 = ChartOfAccount::idByCode('73015') ?? 1;
            $acc16605PC = ChartOfAccount::idByCode('16605PC') ?? 1;
            $acc16605PS = ChartOfAccount::idByCode('16605PS') ?? 1;
            $acc16200NV = ChartOfAccount::idByCode('16200NV') ?? 1;

            $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;

            foreach ($client->contracts as $contract) {
//                $reserveAmount = $contract->provided_amount * $newReservePercent / 100;
//                $oldReserveAmount = $contract->provided_amount * $oldReservePercent / 100;
//                $amount = $reserveAmount - $oldReserveAmount;

                $journal = DocumentJournal::where('journalable_type', Contract::class)
                    ->where('journalable_id', $contract->id)
                    ->first();

                $reserveAmount = 0;

                if ($journal) {
                    $reserveAmount = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('document_type', DocumentJournal::EFFECTIVE_RATE_AMOUNT)
                        ->sum('amount_amd');

                    $amount16605PC = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('document_type', DocumentJournal::RESERVE_GENERAL_AMOUNT)
                        ->where('credit_account_id',$acc16605PC)
                        ->sum('amount_amd');

                }
                if ($newClassificationName != 'standard') {
                    $newReservePercent -= $oldReservePercent;
                }
                $amount = ($contract->provided_amount + $reserveAmount) * $newReservePercent / 100;
                $oldReserveAmount = ($contract->provided_amount+$reserveAmount) * $oldReservePercent / 100;

                if ($amount <= 0 && $client->classification->name !== 'loss') {
                    continue;
                }
                ContractReserveHistory::create([
                    'client_id' => $client->id,
                    'classification_id' => $classification->id,
                    'contract_id' => $contract->id,
                    'risk_weight' => $client->classification?->risk_weight ?? 0,
                    'reserve_percent' => $client->classification?->reserve_percent ?? 0,
                    'reserve_amount' => $amount,
                    'total_reserve_amount' => $amount,
                    'provided_amount' => $contract->provided_amount,
                    'date' => now()->toDateString(),
                    'user_id' => auth()->check() ? auth()->id() : 1,
                    'meta' => [
                        'old_reserve_percent' => $oldReservePercent,
                        'old_reserve_amount' => $oldReserveAmount,
                    ],
                ]);


                $journal = DocumentJournal::where('journalable_type', Contract::class)
                    ->where('journalable_id', $contract->id)
                    ->first();

                if (!$journal) continue;
                if ($amount > 0) {
                    $debetAllocation = $acc73015;
                    $creditAllocation = $client->classification->name === 'standard' ? $acc16605PC : $acc16605PS;

                    $debetClassification = $client->classification->name === 'standard' ? $acc16605PS : $acc16605PC;
                    $creditClassification = $client->classification->name === 'standard' ? $acc16605PC : $acc16605PS;

                    $documentType = $client->classification->name === 'standard'
                        ? DocumentJournal::RESERVE_GENERAL_AMOUNT
                        : DocumentJournal::RESERVE_SPECIAL_AMOUNT;

                    // Journal
                    $docJournal = DocumentJournal::create([
                        'date' => now()->toDateString(),
                        'document_number' => $nextDocNum,
                        'document_type' => $documentType,
                        'amount_amd' => $amount,
                        'partner_id' => $diamondId,
                        'credit_partner_id' => $clientId,
                        'comment' => "Reserve for contract #{$contract->id} due to classification change",
                        'debit_account_id' => $debetAllocation,
                        'credit_account_id' => $creditAllocation,
                        'user_id' => auth()->id() ?? 1,
                        'journalable_type' => DocumentJournal::class,
                        'journalable_id' => $journal->id,
                    ]);
                    // Transaction
                    Transaction::create([
                        'date' => now()->toDateString(),
                        'document_number' => $nextDocNum,
                        'document_type' => $documentType,
                        'debit_account_id' => $debetAllocation,
                        'debit_partner_id' => $diamondId,
                        'debit_currency_id' => 1,
                        'credit_account_id' => $creditAllocation,
                        'credit_currency_id' => 1,
                        'credit_partner_id' => $clientId,
                        'amount_amd' => $amount,
                        'comment' => "Reserve for contract #{$contract->id}",
                        'user_id' => auth()->id() ?? 1,
                        'is_system' => true,
                        'disbursement_date' => now()->toDateString(),
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id' => $docJournal->id,
                    ]);
                    ClassificationHistory::create([
                        'client_id' => $client->id,
                        'classification_id' => $classification->id,
                        'risk_weight' => $client->classification?->risk_weight ?? 0,
                        'reserve_percent' => $client->classification?->reserve_percent ?? 0,
                        'comment' => 'Client classification update manually',
                        'actionable_type' => DocumentJournal::class,
                        'actionable_id' => $docJournal->id,
                        'user_id' => auth()->id() ?? 1,
                        'meta' => [
                            'old_classification_id' => $oldClassificationId,
                            'old_classification_name' => $oldClassificationName,
                            'old_reserve_percent' => $oldReservePercent,
                            'old_reserve_amount' => $oldReserveAmount,
                        ],
                        'date' => now(),
                    ]);

                    $nextDocNum++;
                }
                // --- START replacement for loss handling (inside foreach $contract loop) ---

                if ($client->classification->name === 'loss') {
                    $lossType = DocumentJournal::LOSS_RESERVE_AMOUNT;

                    $amount16605PS = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('credit_account_id', $acc16605PS)
                        ->sum('amount_amd');

                    if ($amount16605PS > 0) {
                        $lossDoc = DocumentJournal::create([
                            'date' => now()->toDateString(),
                            'document_number' => $nextDocNum,
                            'document_type' => $lossType,
                            'amount_amd' => $amount16605PS,
                            'partner_id' => $clientId,
                            'credit_partner_id' => $clientId,
                            'comment' => "Loss client, reserve for contract #{$contract->id}",
                            'debit_account_id' => $acc16605PS,
                            'credit_account_id' => $acc16200NV,
                            'user_id' => auth()->id() ?? 1,
                            'journalable_type' => DocumentJournal::class,
                            'journalable_id' => $journal->id,
                        ]);

                        Transaction::create([
                            'date' => now()->toDateString(),
                            'document_number' => $nextDocNum,
                            'document_type' => $lossType,
                            'debit_account_id' => $acc16605PS,
                            'debit_partner_id' => $clientId,
                            'debit_currency_id' => 1,
                            'credit_account_id' => $acc16200NV,
                            'credit_currency_id' => 1,
                            'credit_partner_id' => $clientId,
                            'amount_amd' => $amount16605PS,
                            'comment' => "Loss client, reserve for contract #{$contract->id}",
                            'user_id' => auth()->id() ?? 1,
                            'is_system' => true,
                            'disbursement_date' => now()->toDateString(),
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id' => $lossDoc->id,
                        ]);

                        $nextDocNum++;
                    }
                    $acc16200 = ChartOfAccount::idByCode('16200')
                    $amount16200Debit = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('debit_account_id', $acc16200)
                        ->sum('amount_amd');
                    $amount16200Credit = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('credit_account_id', $acc16200)
                        ->sum('amount_amd');

                    $net16200 = $amount16200Debit - $amount16200Credit;

                    if ($net16200 > 0) {
                        $lossEffectiveDoc = DocumentJournal::create([
                            'date' => now()->toDateString(),
                            'document_number' => $nextDocNum,
                            'document_type' => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                            'amount_amd' => $net16200,
                            'partner_id' => $clientId,
                            'credit_partner_id' => $clientId,
                            'comment' => "Zeroing 16200 for contract #{$contract->id} due to loss classification",
                            'debit_account_id' => $acc16605PS,
                            'credit_account_id' => $acc16200,
                            'user_id' => auth()->id() ?? 1,
                            'journalable_type' => DocumentJournal::class,
                            'journalable_id' => $journal->id,
                        ]);

                        Transaction::create([
                            'date' => now()->toDateString(),
                            'document_number' => $nextDocNum,
                            'document_type' => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                            'debit_account_id' => $acc16605PS,
                            'debit_partner_id' => $clientId,
                            'debit_currency_id' => 1,
                            'credit_account_id' => $acc16200,
                            'credit_currency_id' => 1,
                            'credit_partner_id' => $clientId,
                            'amount_amd' => $net16200,
                            'comment' => "Zeroing 16200 for contract #{$contract->id}",
                            'user_id' => auth()->id() ?? 1,
                            'is_system' => true,
                            'disbursement_date' => now()->toDateString(),
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id' => $lossEffectiveDoc->id,
                        ]);

                        $nextDocNum++;
                    }

                    $amount16200NVDebit = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('debit_account_id', $acc16200NV)
                        ->sum('amount_amd');
                    $amount16200NVCredit = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('credit_account_id', $acc16200NV)
                        ->sum('amount_amd');

                    $net16200NV = $amount16200NVDebit - $amount16200NVCredit;

                    if ($net16200NV > 0) {
                        $lossInterestDoc = DocumentJournal::create([
                            'date' => now()->toDateString(),
                            'document_number' => $nextDocNum,
                            'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                            'amount_amd' => $net16200NV,
                            'partner_id' => $clientId,
                            'credit_partner_id' => $clientId,
                            'comment' => "Zeroing 16200NV for contract #{$contract->id} due to loss classification",
                            'debit_account_id' => $acc16605PS,
                            'credit_account_id' => $acc16200NV,
                            'user_id' => auth()->id() ?? 1,
                            'journalable_type' => DocumentJournal::class,
                            'journalable_id' => $journal->id,
                        ]);

                        Transaction::create([
                            'date' => now()->toDateString(),
                            'document_number' => $nextDocNum,
                            'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                            'debit_account_id' => $acc16605PS,
                            'debit_partner_id' => $clientId,
                            'debit_currency_id' => 1,
                            'credit_account_id' => $acc16200NV,
                            'credit_currency_id' => 1,
                            'credit_partner_id' => $clientId,
                            'amount_amd' => $net16200NV,
                            'comment' => "Zeroing 16200NV for contract #{$contract->id}",
                            'user_id' => auth()->id() ?? 1,
                            'is_system' => true,
                            'disbursement_date' => now()->toDateString(),
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id' => $lossInterestDoc->id,
                        ]);

                        $nextDocNum++;
                    }
                }

//                if ($client->classification->name === 'loss') {
//                    $lossType = DocumentJournal::LOSS_RESERVE_AMOUNT;
//
//                    $amount16605PS =  DocumentJournal::where('journalable_type', DocumentJournal::class)
//                        ->where('journalable_id', $journal->id)
//                        ->where('credit_account_id',$acc16605PS)
//                        ->sum('amount_amd');
//
//                    $lossDoc = DocumentJournal::create([
//                        'date' => now()->toDateString(),
//                        'document_number' => $nextDocNum,
//                        'document_type' => $lossType,
//                        'amount_amd' => $amount16605PS,
//                        'partner_id' => $clientId,
//                        'credit_partner_id' => $clientId,
//                        'comment' => "Loss client, reserve for contract #{$contract->id}",
//                        'debit_account_id' => $acc16605PS,
//                        'credit_account_id' => $acc16200NV,
//                        'user_id' => auth()->id() ?? 1,
//                        'journalable_type' => DocumentJournal::class,
//                        'journalable_id' => $journal->id,
//                    ]);
//
//                    Transaction::create([
//                        'date' => now()->toDateString(),
//                        'document_number' => $nextDocNum,
//                        'document_type' => $lossType,
//                        'debit_account_id' => $acc16605PS,
//                        'debit_partner_id' => $clientId,
//                        'debit_currency_id' => 1,
//                        'credit_account_id' => $acc16200NV,
//                        'credit_currency_id' => 1,
//                        'credit_partner_id' => $clientId,
//                        'amount_amd' => $amount16605PS,
//                        'comment' => "Loss client, reserve for contract #{$contract->id}",
//                        'user_id' => auth()->id() ?? 1,
//                        'is_system' => true,
//                        'disbursement_date' => now()->toDateString(),
//                        'transactionable_type' => DocumentJournal::class,
//                        'transactionable_id' => $lossDoc->id,
//                    ]);
//
//                    $nextDocNum++;
//
//                    $acc16200 = ChartOfAccount::idByCode('16200');
//
//                    $lossEffectiveType = DocumentJournal::LOSS_RESERVE_EFFECTIVE;
//
//                    $amount16200Debit =  DocumentJournal::where('journalable_type', DocumentJournal::class)
//                        ->where('journalable_id', $journal->id)
//                        ->where('debit_account_id',$acc16200)
//                        ->sum('amount_amd');
//                    $amount16200Credit =  DocumentJournal::where('journalable_type', DocumentJournal::class)
//                        ->where('journalable_id', $journal->id)
//                        ->where('credit_account_id',$acc16200)
//                        ->sum('amount_amd');
//                    $amount16200 = abs($amount16200Debit - $amount16200Credit);
//
//                    $lossEffectiveDoc = DocumentJournal::create([
//                        'date' => now()->toDateString(),
//                        'document_number' => $nextDocNum,
//                        'document_type' => $lossEffectiveType,
//                        'amount_amd' => $amount16200Debit,
//                        'partner_id' => $clientId,
//                        'credit_partner_id' => $clientId,
//                        'comment' => "Loss client, reserve for contract #{$contract->id}",
//                        'debit_account_id' => $acc16605PS,
//                        'credit_account_id' => $acc16200,
//                        'user_id' => auth()->id() ?? 1,
//                        'journalable_type' => DocumentJournal::class,
//                        'journalable_id' => $journal->id,
//                    ]);
//                    Transaction::create([
//                        'date' => now()->toDateString(),
//                        'document_number' => $nextDocNum,
//                        'document_type' => $lossEffectiveType,
//                        'debit_account_id' => $acc16605PS,
//                        'debit_partner_id' => $clientId,
//                        'debit_currency_id' => 1,
//                        'credit_account_id' => $acc16200,
//                        'credit_currency_id' => 1,
//                        'credit_partner_id' => $clientId,
//                        'amount_amd' => $amount16200Debit,
//                        'comment' => "Loss client, reserve for contract #{$contract->id}",
//                        'user_id' => auth()->id() ?? 1,
//                        'is_system' => true,
//                        'disbursement_date' => now()->toDateString(),
//                        'transactionable_type' => DocumentJournal::class,
//                        'transactionable_id' => $lossEffectiveDoc->id,
//                    ]);
//                    $nextDocNum++;
//                    $acc16200NV = ChartOfAccount::idByCode('16200NV');
//
//
//                    $amount16200NVDebit =  DocumentJournal::where('journalable_type', DocumentJournal::class)
//                        ->where('journalable_id', $journal->id)
//                        ->where('debit_account_id',$acc16200NV)
//                        ->sum('amount_amd');
//                    $amount16200NVCredit =  DocumentJournal::where('journalable_type', DocumentJournal::class)
//                        ->where('journalable_id', $journal->id)
//                        ->where('credit_account_id',$acc16200NV)
//                        ->sum('amount_amd');
//                    $amount16200NV = abs($amount16200NVDebit - $amount16200NVCredit);
//
//                    $lossInterestDoc = DocumentJournal::create([
//                        'date' => now()->toDateString(),
//                        'document_number' => $nextDocNum,
//                        'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
//                        'amount_amd' => $amount16200NVDebit,
//                        'partner_id' => $clientId,
//                        'credit_partner_id' => $clientId,
//                        'comment' => "Loss client, reserve for contract #{$contract->id}",
//                        'debit_account_id' => $acc16605PS,
//                        'credit_account_id' => $acc16200NV,
//                        'user_id' => auth()->id() ?? 1,
//                        'journalable_type' => DocumentJournal::class,
//                        'journalable_id' => $journal->id,
//                    ]);
//                    Transaction::create([
//                        'date' => now()->toDateString(),
//                        'document_number' => $nextDocNum,
//                        'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
//                        'debit_account_id' => $acc16605PS,
//                        'debit_partner_id' => $clientId,
//                        'debit_currency_id' => 1,
//                        'credit_account_id' => $acc16200NV,
//                        'credit_currency_id' => 1,
//                        'credit_partner_id' => $clientId,
//                        'amount_amd' => $amount16200NVDebit,
//                        'comment' => "Loss client, reserve for contract #{$contract->id}",
//                        'user_id' => auth()->id() ?? 1,
//                        'is_system' => true,
//                        'disbursement_date' => now()->toDateString(),
//                        'transactionable_type' => DocumentJournal::class,
//                        'transactionable_id' => $lossInterestDoc->id,
//                    ]);
//
//                    $nextDocNum++;
//                }

                if ($amount16605PC > 0 && $oldClassificationName == 'standard') {
                    $classificationType = DocumentJournal::CLASSIFICATION;

                    $classificationDoc = DocumentJournal::create([
                        'date' => now()->toDateString(),
                        'document_number' => $nextDocNum,
                        'document_type' => $classificationType,
                        'amount_amd' => $amount16605PC,
                        'partner_id' => $clientId,
                        'credit_partner_id' => $clientId,
                        'comment' => "Old reserve for contract #{$contract->id} due to classification change",
                        'debit_account_id' => $debetClassification,
                        'credit_account_id' => $creditClassification,
                        'user_id' => auth()->id() ?? 1,
                        'journalable_type' => DocumentJournal::class,
                        'journalable_id' => $journal->id,
                    ]);

                    Transaction::create([
                        'date' => now()->toDateString(),
                        'document_number' => $nextDocNum,
                        'document_type' => $classificationType,
                        'debit_account_id' => $debetClassification,
                        'debit_partner_id' => $clientId,
                        'debit_currency_id' => 1,
                        'credit_account_id' => $creditClassification,
                        'credit_currency_id' => 1,
                        'credit_partner_id' => $clientId,
                        'amount_amd' => $amount16605PC,
                        'comment' => "Old reserve for contract #{$contract->id}",
                        'user_id' => auth()->id() ?? 1,
                        'is_system' => true,
                        'disbursement_date' => now()->toDateString(),
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id' => $classificationDoc->id,
                    ]);

                    $nextDocNum++;
                }
            }

            DB::commit();
            return response()->json(['message' => 'Client classification updated successfully.']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to update classification', 'details' => $e->getMessage()], 500);
        }
    }


}
