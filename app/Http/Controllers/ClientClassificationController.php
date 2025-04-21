<?php

namespace App\Http\Controllers;

use App\Models\ClientClassification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class ClientClassificationController extends Controller
{
    protected function classificationTypes(): array
    {
        return [
            ClientClassification::TYPE_PROBLEMATIC,
            ClientClassification::TYPE_RESPONSIBLE,
        ];
    }

    public function getClassifiedClients(): JsonResponse
    {
        $clients = ClientClassification::with('client')
            ->whereIn('type', $this->classificationTypes())
            ->get()
            ->groupBy('type');

        return response()->json([
            'problematic_clients' => $clients[ClientClassification::TYPE_PROBLEMATIC] ?? [],
            'responsible_clients' => $clients[ClientClassification::TYPE_RESPONSIBLE] ?? [],
        ]);
    }

    public function addClassification(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'   => 'required|exists:clients,id',
            'type'        => 'required|in:' . implode(',', $this->classificationTypes()),
            'description' => 'nullable|string',
            'note'        => 'nullable|string',
        ]);

        $classification = ClientClassification::create([
            'client_id'   => $validated['client_id'],
            'type'        => $validated['type'],
            'description' => $validated['description'] ?? null,
            'note'        => $validated['note'] ?? null,
            'date'        => Carbon::now()->toDateString(),
        ]);

        return response()->json([
            'message' => 'Classification successfully added.',
            'data'    => $classification
        ], 201);
    }

    public function updateClassification(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'type'        => 'required|in:' . implode(',', $this->classificationTypes()),
            'description' => 'nullable|string',
            'note'        => 'nullable|string',
        ]);

        $classification = ClientClassification::findOrFail($id);
        $classification->update($validated);

        return response()->json([
            'message' => 'Classification successfully updated.',
            'data'    => $classification
        ]);
    }

    public function deleteClassification($id): JsonResponse
    {
        $classification = ClientClassification::findOrFail($id);
        $classification->delete();

        return response()->json([
            'message' => 'Classification successfully deleted.'
        ]);
    }
}
