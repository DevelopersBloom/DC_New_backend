<?php

namespace App\Services;

use App\Models\TaskComment;

class TaskCommentService
{
    public function addComment(array $data)
    {
        return TaskComment::create([
            'task_id' => $data['task_id'],
            'user_id' => auth()->id(),
            'comment' => $data['comment'],
        ]);
    }

    public function getComments($taskId)
    {
        return TaskComment::where('task_id', $taskId)
            ->with('user:id,name,surname')
            ->latest()
            ->get();
    }

    public function deleteComment($id)
    {
        $comment = TaskComment::find($id);

        if (!$comment) {
            return false;
        }

        return $comment->delete();
    }

}
