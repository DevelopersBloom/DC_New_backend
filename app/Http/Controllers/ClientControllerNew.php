<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Http\Resources\ClientResource;
use App\Services\ClientService;
use Illuminate\Http\Request;

class ClientControllerNew extends Controller
{
    protected ClientService $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    /**
     * @param ClientRequest $request
     * @return ClientResource
     */
    public function storeOrUpdate(ClientRequest $request)
    {
        $client = $this->clientService->storeOrUpdate($request->validated());
        return new ClientResource($client);
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
    public function updateClientData(Request $request, int $client_id)
    {
        $validatedData = $request->validate([
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
}
