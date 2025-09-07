<?php

namespace App\Http\Controllers;

use App\Exports\ClientsExport;
use App\Http\Requests\ClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\ClientPawnshop;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    private function storeClientData1(array $data, bool $hasContract): JsonResponse
    {
        // Determine the current pawnshop id (assumes an authenticated user)
        $pawnshopId = auth()->user()->pawnshop_id ?? 1;

        // Check if a client with the given passport_series exists
        $existingClient = Client::where('passport_series', $data['passport_series'])->first();

        if ($existingClient) {
            // Check if the client already belongs to the current pawnshop
            if ($existingClient->pawnshopClients()->where('pawnshop_id', $pawnshopId)->exists()) {
                return response()->json([
                    'message' => 'A client with this passport already exists in this pawnshop.'
                ], 422);
            }
        }
        $data['has_contract'] = $hasContract;
        $this->clientService->storeOrUpdate($data);

        return response()->json([
            'message' => $hasContract ? 'Client added successfully' : 'Non-client added successfully'
        ], 201);
    }
    public function show(Request $request, int $clientId)
    {
        $contractStatus = $request->query('status', 'initial');

        $clientInfo = $this->clientService->getClientInfo($clientId, $contractStatus);

        return response()->json($clientInfo);
    }
    public function index(Request $request): JsonResponse
    {
        $pawnshopId = auth()->user()->pawnshop_id;
        $startOfMonth = now()->startOfMonth();

        $clients = Client::select([
            'id',  DB::raw("DATE_FORMAT(date, '%d-%m-%Y') as registration_date"), 'name', 'surname', 'middle_name', DB::raw("DATE_FORMAT(date_of_birth, '%d-%m-%Y') as date_of_birth"), 'country',
            'city', 'street', 'building', 'passport_series', 'passport_validity',
            'passport_issued', 'phone', 'additional_phone', 'email', 'has_contract'
        ])
        ->whereHas('pawnshopClients', function ($query) use ($pawnshopId) {
            $query->where('pawnshop_id', $pawnshopId);
        })
        ->filterByClient($request->only(['id','name', 'surname', 'patronymic', 'passport_series', 'phone', 'start_date', 'end_date']))
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
    public function updateClientData1(Request $request, int $client_id)
    {
        $validatedData = $request->validate([
            'name' => 'nullable|string|max:255',
            'surname' => 'nullable|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'passport_series' => 'nullable|string|max:255',
            'passport_validity' => 'nullable|date',
            'passport_issued' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'street' => 'nullable|string|max:255',
            'building' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'additional_phone' => 'nullable|string|max:20',
        ]);

        try {
            $client = $this->clientService->updateClientData($client_id, $validatedData);
            return response()->json(['message' => 'Client data updated successfully', 'client' => $client], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Client not found or update failed'], 400);
        }
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
}
