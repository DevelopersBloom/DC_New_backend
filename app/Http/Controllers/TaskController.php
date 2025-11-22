<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;

class TaskController
{
    public function __construct(protected TaskService $taskService) {}

    public function index(): JsonResponse
    {
        $tasks = $this->taskService->getGroupedTasks();

        return response()->json($tasks);
    }
    public function show(int $id): JsonResponse
    {
        $task = $this->taskService->show($id);

        if (!$task) {
            return response()->json([
                'message' => 'Task not found',
            ], 404);
        }

        return response()->json($task);
    }


    public function store(StoreTaskRequest $request): JsonResponse
    {
        $this->taskService->create($request->validated());

        return response()->json([
            'message' => 'Task created successfully',
        ], 201);
    }
    public function update(UpdateTaskRequest $request, int $id): JsonResponse
    {
        $task = $this->taskService->update($id, $request->validated());

        if (!$task) {
            return response()->json([
                'message' => 'Task not found',
            ], 404);
        }

        return response()->json([
            'message' => 'Task updated successfully',
        ]);
    }
    public function destroy(int $id): JsonResponse
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Task not found',
            ], 404);
        }

        $task->delete();

        return response()->json([
            'message' => 'Task deleted successfully',
        ]);
    }
}
