<?php

namespace App\Http\Controllers;

use App\Models\BusinessEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessEventController extends Controller
{
    /**
     * Display a listing of the business events.
     */
    public function index(): JsonResponse
    {
        $events = BusinessEvent::select('id', 'name', 'filter')
            ->orderBy('created_at', 'desc')
            ->get();        return response()->json($events);
    }

    /**,
     * Store a newly created business event in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filter' => 'nullable|string',
            'name' => 'required|string|max:255',
        ]);

        $event = BusinessEvent::create($validated);

        return response()->json($event, 201);
    }

    /**
     * Display the specified business event.
     */
    public function show($id): JsonResponse
    {
        $event = BusinessEvent::findOrFail($id);
        return response()->json($event);
    }

    /**
     * Update the specified business event in storage.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $event = BusinessEvent::findOrFail($id);

        $validated = $request->validate([
            'filter' => 'nullable|string',
            'name' => 'required|string|max:255',
        ]);

        $event->update($validated);

        return response()->json($event);
    }

    /**
     * Remove the specified business event from storage.
     */
    public function destroy($id)
    {
        $event = BusinessEvent::findOrFail($id);
        $event->delete();

        return response()->json(['message' => 'Business event deleted successfully']);
    }
}
