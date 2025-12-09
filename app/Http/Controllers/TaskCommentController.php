<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskCommentRequest;
use App\Services\TaskCommentService;
use Illuminate\Http\JsonResponse;

class TaskCommentController extends Controller
{
    public function __construct(
        protected TaskCommentService $commentService
    ) {}

    /**
     * Store a new comment for a task
     */
    public function store(StoreTaskCommentRequest $request): JsonResponse
    {
        $comment = $this->commentService->addComment($request->validated());

        return response()->json([
            'message' => 'Comment added successfully',
        ], 201);
    }

    /**
     * Get all comments for a task
     */
    public function index(int $taskId): JsonResponse
    {
        $comments = $this->commentService->getComments($taskId);

        return response()->json($comments);
    }

    /**
     * Delete a comment
     */
    public function destroy(int $commentId): JsonResponse
    {
        $deleted = $this->commentService->deleteComment($commentId);

        if (!$deleted) {
            return response()->json([
                'message' => 'Comment not found',
            ], 404);
        }

        return response()->json([
            'message' => 'Comment deleted successfully'
        ]);
    }
}
