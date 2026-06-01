<?php

namespace App\Http\Controllers;

use App\Exports\ClientsExport;
use App\Http\Requests\ClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\PartnerResource;
use App\Jobs\CorrectAllClientReservesJob;
use App\Jobs\ProcessDailyBankProvision;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\ClientClassification;
use App\Models\ClassificationHistory;
use App\Models\ClientPawnshop;
use App\Models\Contract;
use App\Models\Modification;
use App\Models\ContractReserveHistory;
use App\Models\DocumentJournal;
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Services\ClientClassificationService;
use App\Services\ClientService;
use App\Traits\CalculatesAccountBalancesTrait;
use App\Traits\CorrectReserveTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Exception;
use Maatwebsite\Excel\Facades\Excel;

class ClientControllerNew extends Controller
{
    use CalculatesAccountBalancesTrait, CorrectReserveTrait;

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
        }, 'classification'])->find($clientId);

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
            'id', DB::raw("DATE_FORMAT(date, '%d-%m-%Y') as registration_date"), 'type', 'name', 'surname', 'middle_name', DB::raw("DATE_FORMAT(date_of_birth, '%d-%m-%Y') as date_of_birth"), 'country',
            'city', 'street', 'building', 'passport_series', 'passport_validity',
            'passport_issued', 'phone', 'additional_phone', 'email', 'has_contract', 'company_name', 'is_linked_to_company', 'is_company_employee'
        ])
            ->whereHas('pawnshopClients', function ($query) use ($pawnshopId) {
                $query->where('pawnshop_id', $pawnshopId);
            })->when($status, function ($query, $statusValue) {
                return $query->whereHas('classification', function ($q) use ($statusValue) {
                    $q->where('name', $statusValue);
                });
            })
            ->filterByClient($request->only(['id', 'name', 'surname', 'patronymic', 'passport_series', 'phone', 'start_date', 'end_date', 'is_linked_to_company', 'is_company_employee']))
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
                'client_id' => $client->id,
                'pawnshop_id' => $pawnshopId,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Client data updated successfully',
                'client' => $client->fresh(),
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

        $client = Client::with(['contracts' => function ($q) {
            $q->where('status', 'initial');
        }, 'classification'])->findOrFail($request->client_id);

        $classification = ClientClassification::where('name', $request->classification)->firstOrFail();

        if (
            ($client->classification_id === $classification->id) ||
            ($client->classification->order > $classification->order)
        ) {
            return response()->json(['message' => 'Classification is already set to this value or a stricter one.']);
        }

        $acc16605PC  = ChartOfAccount::idByCode('16605PC');
        $acc16605PS  = ChartOfAccount::idByCode('16605PS');
        $acc16200NV  = ChartOfAccount::idByCode('16200NV');
        $acc16200    = ChartOfAccount::idByCode('16200');
        $acc16201NI  = ChartOfAccount::idByCode('16201NI');
        $acc86000    = ChartOfAccount::idByCode('86000');
        $acc86001    = ChartOfAccount::idByCode('86001');

        $targetAccountIds = array_filter([
            $acc16200NV,
            $acc16200,
            $acc16201NI,
        ]);

        DB::beginTransaction();
        try {
            // ── Save old values ───────────────────────────────────────────────
            $oldClassificationName  = $client->classification?->name;
            $oldClassificationId    = $client->classification?->id;
            $oldClassificationOrder = $client->classification?->order;
            $oldReservePercent      = $client->classification?->reserve_percent ?? 0;

            // ── Update client classification ──────────────────────────────────
            $client->classification_id = $classification->id;
            $client->save();
            $client->load('classification');

            $newClassificationName  = $client->classification?->name;
            $newReservePercent      = $client->classification?->reserve_percent ?? 0;
            $newRiskWeight          = $client->classification?->risk_weight ?? 0;
            $newClassificationOrder = $client->classification?->order;
            $clientId               = $client->id;

            // ── Modification log ──────────────────────────────────────────────
            $oldRisk = $oldClassificationOrder !== null ? max(0, min(7, (int)$oldClassificationOrder)) : null;
            $newRisk = $newClassificationOrder !== null ? max(0, min(7, (int)$newClassificationOrder)) : 0;

            Modification::create([
                'subject_type'      => Client::class,
                'subject_id'        => $clientId,
                'modification_type' => 'Modificator',
                'field_code'        => 'RISK',
                'element_code'      => 'Risk',
                'old_value'         => $oldRisk !== null ? (string)$oldRisk : null,
                'new_value'         => (string)$newRisk,
                'effective_date'    => now()->toDateString(),
            ]);

            $firstContract = $client->contracts->first();
            $firstJournal  = $firstContract
                ? DocumentJournal::where('journalable_type', Contract::class)
                    ->where('journalable_id', $firstContract->id)
                    ->first()
                : null;

            if ($firstJournal) {
                $this->correctClientReserveBalance(
                    clientId:           $clientId,
                    acc16605PC:         $acc16605PC,
                    acc16605PS:         $acc16605PS,
                    targetAccountIds:   $targetAccountIds,
                    reservePercent:     $client->classification->reserve_percent ?? 0,
                    classificationName: $newClassificationName,
                    journalId:          $firstJournal->id,
                    now:                now()->toDateString(),
                );
            }

            if ($newClassificationName !== 'standard') {
                $newReservePercent -= $oldReservePercent;
            }

            foreach ($client->contracts as $contract) {

                $journal = DocumentJournal::where('journalable_type', Contract::class)
                    ->where('journalable_id', $contract->id)
                    ->first();

                $reserveAmount  = 0;
                $amount16605PC  = 0;
                $amount16605PS  = 0;

                if ($journal) {
                    $reserveAmount = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where(function ($q) {
                            $q->where('document_type', DocumentJournal::RESERVE_SPECIAL_AMOUNT)
                                ->orWhere('document_type', DocumentJournal::RESERVE_GENERAL_AMOUNT)
                                ->orWhere('document_type', DocumentJournal::EFFECTIVE_RATE_AMOUNT);
                        })
                        ->sum('amount_amd');

                    $amount16605PC = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('document_type', DocumentJournal::RESERVE_GENERAL_AMOUNT)
                        ->where('credit_account_id', $acc16605PC)
                        ->sum('amount_amd');

                    $amount16605PS = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('credit_account_id', $acc16605PS)
                        ->sum('amount_amd');
                }

                $amount         = ($contract->provided_amount + $reserveAmount) * ($newReservePercent / 100);
                $oldReserveAmount = ($contract->provided_amount + $reserveAmount) * ($oldReservePercent / 100);

                ContractReserveHistory::create([
                    'client_id'          => $client->id,
                    'classification_id'  => $classification->id,
                    'contract_id'        => $contract->id,
                    'risk_weight'        => $newRiskWeight,
                    'reserve_percent'    => $newReservePercent,
                    'reserve_amount'     => $amount,
                    'total_reserve_amount' => $amount,
                    'provided_amount'    => $contract->provided_amount,
                    'date'               => now()->toDateString(),
                    'user_id'            => auth()->id() ?? 1,
                    'meta'               => [
                        'old_reserve_percent' => $oldReservePercent,
                        'old_reserve_amount'  => $oldReserveAmount,
                    ],
                ]);

                if ($amount <= 0 && $client->classification->name !== 'loss') {
                    continue;
                }

                if ($client->classification->name === 'standard') {
                    $ruleReserve = PostingRule::where('business_event_filter', 'reserve_general_amount')->first();
                    if (!$ruleReserve) {
                        throw new \RuntimeException('Posting rule for reserve_general_amount not found');
                    }
                } else {
                    $ruleReserve = PostingRule::where('business_event_filter', 'reserve_special_amount')->first();
                    if (!$ruleReserve) {
                        throw new \RuntimeException('Posting rule for reserve_special_amount not found');
                    }
                }

                $debitReserve  = $ruleReserve->debit_account_id;
                $creditReserve = $ruleReserve->credit_account_id;

                if ($oldClassificationName === 'standard') {
                    $ruleClassification = PostingRule::where('business_event_filter', 'classification_general_to_special')->firstOrFail();
                } else {
                    $ruleClassification = PostingRule::where('business_event_filter', 'classification_special_to_general')->firstOrFail();
                }
                $debitClassification  = $ruleClassification->debit_account_id;
                $creditClassification = $ruleClassification->credit_account_id;

                $documentType = $client->classification->name === 'standard'
                    ? DocumentJournal::RESERVE_GENERAL_AMOUNT
                    : DocumentJournal::RESERVE_SPECIAL_AMOUNT;

                if (!$journal) {
                    continue;
                }

                $docJournal = null;
                if ($amount > 0) {
                    $nextDocNum = Transaction::getNextDocumentNumber();
                    $docJournal = DocumentJournal::create([
                        'date'             => now()->toDateString(),
                        'document_number'  => $nextDocNum,
                        'document_type'    => $documentType,
                        'amount_amd'       => $amount,
                        'credit_partner_id'=> $clientId,
                        'comment'          => "Reserve for contract #{$contract->id} due to classification change (manual)",
                        'debit_account_id' => $debitReserve,
                        'credit_account_id'=> $creditReserve,
                        'user_id'          => auth()->id() ?? 1,
                        'journalable_type' => DocumentJournal::class,
                        'journalable_id'   => $journal->id,
                    ]);

                    Transaction::create([
                        'date'                 => now()->toDateString(),
                        'document_number'      => $nextDocNum,
                        'document_type'        => $documentType,
                        'debit_account_id'     => $debitReserve,
                        'debit_currency_id'    => 1,
                        'credit_account_id'    => $creditReserve,
                        'credit_currency_id'   => 1,
                        'credit_partner_id'    => $clientId,
                        'amount_amd'           => $amount,
                        'comment'              => "Reserve for contract #{$contract->id} (manual)",
                        'user_id'              => auth()->id() ?? 1,
                        'is_system'            => true,
                        'disbursement_date'    => now()->toDateString(),
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id'   => $docJournal->id,
                    ]);
                }

                ClassificationHistory::create([
                    'client_id'        => $client->id,
                    'classification_id'=> $classification->id,
                    'risk_weight'      => $newRiskWeight,
                    'reserve_percent'  => $client->classification?->reserve_percent ?? 0,
                    'comment'          => 'Client classification update manually',
                    'actionable_type'  => DocumentJournal::class,
                    'actionable_id'    => $docJournal?->id ?? $journal->id,
                    'user_id'          => auth()->id() ?? 1,
                    'meta'             => [
                        'old_classification_id'   => $oldClassificationId,
                        'old_classification_name' => $oldClassificationName,
                        'old_reserve_percent'     => $oldReservePercent,
                        'old_reserve_amount'      => $oldReserveAmount,
                    ],
                    'date' => now(),
                ]);

                if ($client->classification->name === 'loss') {

                    $debit16200  = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('debit_account_id', $acc16200)->sum('amount_amd');
                    $credit16200 = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('credit_account_id', $acc16200)->sum('amount_amd');
                    $net16200 = $debit16200 - $credit16200;

                    $debit16200NV  = DocumentJournal::where('journalable_type', Contract::class)
                        ->where('journalable_id', $contract->id)
                        ->where('debit_account_id', $acc16200NV)->sum('amount_amd');
                    $credit16200NV = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('credit_account_id', $acc16200NV)->sum('amount_amd');
                    $net16200NV = $debit16200NV - $credit16200NV;

                    $debit16201NI  = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('debit_account_id', $acc16201NI)->sum('amount_amd');
                    $credit16201NI = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $journal->id)
                        ->where('credit_account_id', $acc16201NI)->sum('amount_amd');
                    $net16201NI = $debit16201NI - $credit16201NI;

                    // ── Step 1: Net balance transfer ─────────────────────────────
                    // Dr: Net Balance of (16200 + 16200NV + 16201NI) / Cr: 16605PS
                    $totalNet = round($net16200 + $net16200NV + $net16201NI, 2);
                    $currentPS = $this->getClientReserveBalance($clientId, $acc16605PS);
                    // diff > 0 → need to add credit (Dr 73015 / Cr 16605PS)
                    // diff < 0 → need to reduce credit (Dr 16605PS / Cr 73015)
                    $diff = round($totalNet + $currentPS, 2);
                    if (abs($diff) >= 0.01) {
                        $ruleStep1 = PostingRule::where('business_event_filter', 'loss_writeoff_net_transfer')->firstOrFail();
                        $nextDocNum = Transaction::getNextDocumentNumber();
                        $debitAcc  = $diff > 0 ? $ruleStep1->debit_account_id : $ruleStep1->credit_account_id;
                        $creditAcc = $diff > 0 ? $ruleStep1->credit_account_id : $ruleStep1->debit_account_id;
                        $step1Doc = DocumentJournal::create([
                            'date'              => now()->toDateString(),
                            'document_number'   => $nextDocNum,
                            'document_type'     => DocumentJournal::LOSS_WRITEOFF_NET_TRANSFER,
                            'amount_amd'        => abs($diff),
                            'debit_partner_id'  => $clientId,
                            'credit_partner_id' => $clientId,
                            'comment'           => "Loss write-off net balance transfer for contract #{$contract->id}",
                            'debit_account_id'  => $debitAcc,
                            'credit_account_id' => $creditAcc,
                            'user_id'           => auth()->id() ?? 1,
                            'journalable_type'  => DocumentJournal::class,
                            'journalable_id'    => $journal->id,
                        ]);
                        Transaction::create([
                            'date'                 => now()->toDateString(),
                            'document_number'      => $nextDocNum,
                            'document_type'        => DocumentJournal::LOSS_WRITEOFF_NET_TRANSFER,
                            'debit_account_id'     => $debitAcc,
                            'debit_partner_id'     => $clientId,
                            'debit_currency_id'    => 1,
                            'credit_account_id'    => $creditAcc,
                            'credit_currency_id'   => 1,
                            'credit_partner_id'    => $clientId,
                            'amount_amd'           => abs($diff),
                            'comment'              => "Loss write-off net balance transfer for contract #{$contract->id}",
                            'user_id'              => auth()->id() ?? 1,
                            'is_system'            => true,
                            'disbursement_date'    => now()->toDateString(),
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id'   => $step1Doc->id,
                        ]);
                    }

                    // ── Step 2: Individual account transfers ──────────────────────
                    if (abs($net16200) >= 0.01) {
                        $nextDocNum = Transaction::getNextDocumentNumber();
                        $dAcc16200  = $net16200 > 0 ? $acc16605PS : $acc16200;
                        $cAcc16200  = $net16200 > 0 ? $acc16200   : $acc16605PS;
                        $lossEff16200Doc = DocumentJournal::create([
                            'date'              => now()->toDateString(),
                            'document_number'   => $nextDocNum,
                            'document_type'     => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                            'amount_amd'        => abs($net16200),
                            'debit_partner_id'  => $clientId,
                            'credit_partner_id' => $clientId,
                            'comment'           => "Write-off 16200 for contract #{$contract->id} - loss classification (manual)",
                            'debit_account_id'  => $dAcc16200,
                            'credit_account_id' => $cAcc16200,
                            'user_id'           => auth()->id() ?? 1,
                            'journalable_type'  => DocumentJournal::class,
                            'journalable_id'    => $journal->id,
                        ]);
                        Transaction::create([
                            'date'                 => now()->toDateString(),
                            'document_number'      => $nextDocNum,
                            'document_type'        => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                            'debit_account_id'     => $dAcc16200,
                            'debit_partner_id'     => $clientId,
                            'debit_currency_id'    => 1,
                            'credit_account_id'    => $cAcc16200,
                            'credit_currency_id'   => 1,
                            'credit_partner_id'    => $clientId,
                            'amount_amd'           => abs($net16200),
                            'comment'              => "Write-off 16200 for contract #{$contract->id} (manual)",
                            'user_id'              => auth()->id() ?? 1,
                            'is_system'            => true,
                            'disbursement_date'    => now()->toDateString(),
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id'   => $lossEff16200Doc->id,
                        ]);
                    }

                    if (abs($net16200NV) >= 0.01) {
                        $nextDocNum = Transaction::getNextDocumentNumber();
                        $dAcc16200NV = $net16200NV > 0 ? $acc16605PS : $acc16200NV;
                        $cAcc16200NV = $net16200NV > 0 ? $acc16200NV : $acc16605PS;
                        $lossNVDoc = DocumentJournal::create([
                            'date'              => now()->toDateString(),
                            'document_number'   => $nextDocNum,
                            'document_type'     => DocumentJournal::LOSS_RESERVE_AMOUNT,
                            'amount_amd'        => abs($net16200NV),
                            'debit_partner_id'  => $clientId,
                            'credit_partner_id' => $clientId,
                            'comment'           => "Write-off 16200NV for contract #{$contract->id} - loss classification (manual)",
                            'debit_account_id'  => $dAcc16200NV,
                            'credit_account_id' => $cAcc16200NV,
                            'user_id'           => auth()->id() ?? 1,
                            'journalable_type'  => DocumentJournal::class,
                            'journalable_id'    => $journal->id,
                        ]);
                        Transaction::create([
                            'date'                 => now()->toDateString(),
                            'document_number'      => $nextDocNum,
                            'document_type'        => DocumentJournal::LOSS_RESERVE_AMOUNT,
                            'debit_account_id'     => $dAcc16200NV,
                            'debit_partner_id'     => $clientId,
                            'debit_currency_id'    => 1,
                            'credit_account_id'    => $cAcc16200NV,
                            'credit_currency_id'   => 1,
                            'credit_partner_id'    => $clientId,
                            'amount_amd'           => abs($net16200NV),
                            'comment'              => "Write-off 16200NV for contract #{$contract->id} (manual)",
                            'user_id'              => auth()->id() ?? 1,
                            'is_system'            => true,
                            'disbursement_date'    => now()->toDateString(),
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id'   => $lossNVDoc->id,
                        ]);
                    }

                    $amount86000 = $net16200 + $net16200NV;
                    if (abs($amount86000) >= 0.01) {
                        $rule86000 = PostingRule::where('business_event_filter', 'loss_writeoff_principal')->first();
                        if (!$rule86000) {
                            throw new \RuntimeException('Posting rule for loss_writeoff_principal not found');
                        }
                        $nextDocNum = Transaction::getNextDocumentNumber();
                        $dAcc86000 = $amount86000 > 0 ? $acc86000                    : $rule86000->credit_account_id;
                        $cAcc86000 = $amount86000 > 0 ? $rule86000->credit_account_id : $acc86000;
                        $loss86000Doc = DocumentJournal::create([
                            'date'              => now()->toDateString(),
                            'document_number'   => $nextDocNum,
                            'document_type'     => DocumentJournal::LOSS_RESERVE_AMOUNT,
                            'amount_amd'        => abs($amount86000),
                            'debit_partner_id'  => $clientId,
                            'credit_partner_id' => $clientId,
                            'comment'           => "Loss write-off expense 86000 for contract #{$contract->id} (manual)",
                            'debit_account_id'  => $dAcc86000,
                            'credit_account_id' => $cAcc86000,
                            'user_id'           => auth()->id() ?? 1,
                            'journalable_type'  => DocumentJournal::class,
                            'journalable_id'    => $journal->id,
                        ]);
                        Transaction::create([
                            'date'                 => now()->toDateString(),
                            'document_number'      => $nextDocNum,
                            'document_type'        => DocumentJournal::LOSS_RESERVE_AMOUNT,
                            'debit_account_id'     => $dAcc86000,
                            'debit_partner_id'     => $clientId,
                            'debit_currency_id'    => 1,
                            'credit_account_id'    => $cAcc86000,
                            'credit_currency_id'   => 1,
                            'credit_partner_id'    => $clientId,
                            'amount_amd'           => abs($amount86000),
                            'comment'              => "Loss write-off expense 86000 for contract #{$contract->id} (manual)",
                            'user_id'              => auth()->id() ?? 1,
                            'is_system'            => true,
                            'disbursement_date'    => now()->toDateString(),
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id'   => $loss86000Doc->id,
                        ]);
                    }

                    if (abs($net16201NI) >= 0.01) {
                        $nextDocNum = Transaction::getNextDocumentNumber();
                        $dAcc16201NI = $net16201NI > 0 ? $acc16605PS : $acc16201NI;
                        $cAcc16201NI = $net16201NI > 0 ? $acc16201NI : $acc16605PS;
                        $lossNIDoc = DocumentJournal::create([
                            'date'              => now()->toDateString(),
                            'document_number'   => $nextDocNum,
                            'document_type'     => DocumentJournal::LOSS_RESERVE,
                            'amount_amd'        => abs($net16201NI),
                            'debit_partner_id'  => $clientId,
                            'credit_partner_id' => $clientId,
                            'comment'           => "Write-off 16201NI for contract #{$contract->id} - loss classification (manual)",
                            'debit_account_id'  => $dAcc16201NI,
                            'credit_account_id' => $cAcc16201NI,
                            'user_id'           => auth()->id() ?? 1,
                            'journalable_type'  => DocumentJournal::class,
                            'journalable_id'    => $journal->id,
                        ]);
                        Transaction::create([
                            'date'                 => now()->toDateString(),
                            'document_number'      => $nextDocNum,
                            'document_type'        => DocumentJournal::LOSS_RESERVE,
                            'debit_account_id'     => $dAcc16201NI,
                            'debit_partner_id'     => $clientId,
                            'debit_currency_id'    => 1,
                            'credit_account_id'    => $cAcc16201NI,
                            'credit_currency_id'   => 1,
                            'credit_partner_id'    => $clientId,
                            'amount_amd'           => abs($net16201NI),
                            'comment'              => "Write-off 16201NI for contract #{$contract->id} (manual)",
                            'user_id'              => auth()->id() ?? 1,
                            'is_system'            => true,
                            'disbursement_date'    => now()->toDateString(),
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id'   => $lossNIDoc->id,
                        ]);
                    }

                    // Entry 5: Dr 86001 — same amount as 16201NI net
                    if (abs($net16201NI) >= 0.01) {
                        $rule86001 = PostingRule::where('business_event_filter', 'loss_writeoff_interest')->first();
                        if (!$rule86001) {
                            throw new \RuntimeException('Posting rule for loss_writeoff_interest not found');
                        }
                        $nextDocNum = Transaction::getNextDocumentNumber();
                        $dAcc86001 = $net16201NI > 0 ? $acc86001                    : $rule86001->credit_account_id;
                        $cAcc86001 = $net16201NI > 0 ? $rule86001->credit_account_id : $acc86001;
                        $loss86001Doc = DocumentJournal::create([
                            'date'              => now()->toDateString(),
                            'document_number'   => $nextDocNum,
                            'document_type'     => DocumentJournal::LOSS_RESERVE,
                            'amount_amd'        => abs($net16201NI),
                            'debit_partner_id'  => $clientId,
                            'credit_partner_id' => $clientId,
                            'comment'           => "Loss write-off expense 86001 for contract #{$contract->id} (manual)",
                            'debit_account_id'  => $dAcc86001,
                            'credit_account_id' => $cAcc86001,
                            'user_id'           => auth()->id() ?? 1,
                            'journalable_type'  => DocumentJournal::class,
                            'journalable_id'    => $journal->id,
                        ]);
                        Transaction::create([
                            'date'                 => now()->toDateString(),
                            'document_number'      => $nextDocNum,
                            'document_type'        => DocumentJournal::LOSS_RESERVE,
                            'debit_account_id'     => $dAcc86001,
                            'debit_partner_id'     => $clientId,
                            'debit_currency_id'    => 1,
                            'credit_account_id'    => $cAcc86001,
                            'credit_currency_id'   => 1,
                            'credit_partner_id'    => $clientId,
                            'amount_amd'           => abs($net16201NI),
                            'comment'              => "Loss write-off expense 86001 for contract #{$contract->id} (manual)",
                            'user_id'              => auth()->id() ?? 1,
                            'is_system'            => true,
                            'disbursement_date'    => now()->toDateString(),
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id'   => $loss86001Doc->id,
                        ]);
                    }
                }

                if (!empty($amount16605PC) && $oldClassificationName === 'standard') {
                    $nextDocNum = Transaction::getNextDocumentNumber();
                    $classificationDoc = DocumentJournal::create([
                        'date'              => now()->toDateString(),
                        'document_number'   => $nextDocNum,
                        'document_type'     => DocumentJournal::CLASSIFICATION,
                        'amount_amd'        => $amount16605PC,
                        'debit_partner_id'  => $clientId,
                        'credit_partner_id' => $clientId,
                        'comment'           => "Old reserve for contract #{$contract->id} due to classification change (manual)",
                        'debit_account_id'  => $debitClassification,
                        'credit_account_id' => $creditClassification,
                        'user_id'           => auth()->id() ?? 1,
                        'journalable_type'  => DocumentJournal::class,
                        'journalable_id'    => $journal->id,
                    ]);

                    Transaction::create([
                        'date'                 => now()->toDateString(),
                        'document_number'      => $nextDocNum,
                        'document_type'        => DocumentJournal::CLASSIFICATION,
                        'debit_account_id'     => $debitClassification,
                        'debit_partner_id'     => $clientId,
                        'debit_currency_id'    => 1,
                        'credit_account_id'    => $creditClassification,
                        'credit_currency_id'   => 1,
                        'credit_partner_id'    => $clientId,
                        'amount_amd'           => $amount16605PC,
                        'comment'              => "Old reserve for contract #{$contract->id} (manual)",
                        'user_id'              => auth()->id() ?? 1,
                        'is_system'            => true,
                        'disbursement_date'    => now()->toDateString(),
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id'   => $classificationDoc->id,
                    ]);
                }

            }

            DB::commit();
            return response()->json(['message' => 'Client classification updated successfully.']);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'error'   => 'Failed to update classification',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
    public function correctClientReserve(Request $request)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'classification' => 'required|exists:clients_classification,id',
        ]);
        $client = Client::with('classification')->findOrFail($request->client_id);

        $classification = ClientClassification::findOrFail($request->classification);


        if (!$classification) return response()->json(['message' => 'Client classification not found.']);
        $now = now()->format('Y-m-d');

        $acc16605PC = ChartOfAccount::idByCode('16605PC');
        $acc16605PS = ChartOfAccount::idByCode('16605PS');

        $targetAccountIds = array_filter([
            ChartOfAccount::idByCode('16200NV'),
            ChartOfAccount::idByCode('16201NI'),
            ChartOfAccount::idByCode('16200'),
        ]);

        $firstContract = $client->contracts()->where('status', 'initial')->first();
        $hasActiveContracts = $client->contracts()->where('status', 'initial')->exists();

        $journalId = $firstContract
            ? DocumentJournal::where('journalable_type', Contract::class)
                ->where('journalable_id', $firstContract->id)
                ->value('id')
            : $this->resolveReserveJournalIdForClient($client->id);

        DB::beginTransaction();

        try {
            if (!$hasActiveContracts) {
                $this->zeroClientReserveBalances(
                    clientId:    $client->id,
                    acc16605PC:  $acc16605PC,
                    acc16605PS:  $acc16605PS,
                    journalId:   $journalId,
                    date:        $now,
                );
            } else {
                $this->correctClientReserveBalance(
                    clientId:           $client->id,
                    acc16605PC:         $acc16605PC,
                    acc16605PS:         $acc16605PS,
                    targetAccountIds:   $targetAccountIds,
                    reservePercent:     $classification->reserve_percent,
                    classificationName: $classification->name,
                    journalId:          $journalId,
                    now:                $now,
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Reserve corrected successfully'
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function correctAllClientReservesOld(): JsonResponse
    {
        dispatch(new CorrectAllClientReservesJob());

        return response()->json([
            'success' => true,
            'message' => 'Reserve recalculation job dispatched.',
        ]);
    }

    public function correctAllClientReserves(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $from = Carbon::parse($request->date_from)->startOfDay();
        $to = Carbon::parse($request->date_to)->startOfDay();

        $processed = [];
        $failed = [];

        $current = $from->copy();

        while ($current->lte($to)) {

            $dateStr = $current->format('Y-m-d');

            try {
                CorrectAllClientReservesJob::dispatchSync($dateStr);

                $processed[] = $dateStr;

            } catch (\Throwable $e) {

                Log::error("Client reserve failed for {$dateStr}: " . $e->getMessage());

                $failed[] = [
                    'date' => $dateStr,
                    'error' => $e->getMessage(),
                ];
            }

            $current->addDay();
        }

        return response()->json([
            'success' => empty($failed),
            'processed' => $processed,
            'failed' => $failed,
            'message' => empty($failed)
                ? 'Բոլոր օրերի recalculation-ը կատարվեց'
                : 'Մի քանի օրերի recalculation-ը ձախողվեց',
        ]);
    }
    /**
     * Retrieves the list of clients with the upcoming birthdays.
     * It filters clients by the shortest days remaining until their birthday.
     * If multiple clients share the same birthday, all of them are included.
     *
     * @return JsonResponse
     */
    public function getUpcomingBirthdays(): JsonResponse
    {
        $today = Carbon::today();

        $clients = Client::whereNotNull('date_of_birth')->get();

        $clientsWithDiff = $clients->map(function ($client) use ($today) {
            $birthDate = Carbon::parse($client->date_of_birth);

            $nextBirthday = $birthDate->copy()->year($today->year);

            if ($nextBirthday->lt($today)) {
                $nextBirthday->addYear();
            }

            $daysLeft = $today->diffInDays($nextBirthday);

            return [
                'name' => $client->name,
                'surname' => $client->surname,
                'birth_date' => $birthDate->format('Y-m-d'),
                'days_left' => $daysLeft,
            ];
        });

        $sorted = $clientsWithDiff->sortBy('days_left')->values();

        $topDays = $sorted->pluck('days_left')->unique()->take(5);

        $result = $sorted->filter(function ($client) use ($topDays) {
            return $topDays->contains($client['days_left']);
        })->values();

        return response()->json([
            'data' => $result
        ]);
    }
}
