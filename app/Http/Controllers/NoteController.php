<?php

namespace App\Http\Controllers;

use App\Http\Resources\NoteResource;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    /**
     * Display a listing of notes for a specific contract
     *
     * @param int $contract_id
     * @return JsonResponse
     */

    public function index(int $contract_id): JsonResponse
    {
        $notes = Note::where('contract_id', $contract_id)->get();

        return response()->json([
            'notes' => NoteResource::collection($notes),
        ]);
    }
    /**
     * Store a newly created note for a specific contract
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_id' => 'required|exists:contracts,id',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string'
        ]);

        $note = Note::create($validated);

        return response()->json([
            'message' => 'Note created successfully',
            'note'    => new NoteResource($note)
        ],201);
    }

    /**
     * Update the specified note
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function update(Request $request,$id): JsonResponse
    {
        $note = Note::findOrFail($id);

        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $note->update($validated);

        return response()->json([
            'message' => 'Note updated successfully',
            'note'    => new NoteResource($note),
        ]);
    }

    /**
     * Remove the specified note
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $note = Note::findOrFail($id);

        $note->delete();

        return response()->json([
            'message' => 'Note deleted successfully'
        ]);

    }
}

