<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Http\Resources\ClientResource;
use App\Services\ClientService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

    /**
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function search(Request $request)
    {
        $request->validate([
            'inputString' => 'nullable|string|max:255',
        ]);

        $inputString = $request->input('search');

        $inputs = preg_split('/\s+/', trim($inputString));

        $firstInput = $inputs[0] ?? null;
        $secondInput = $inputs[1] ?? null;

        $clients = $this->clientService->search($firstInput, $secondInput);

        return ClientResource::collection($clients);
    }
}
