<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Auth;

class TaskService
{
    public function getGroupedTasks()
    {
        $tasks = Task::select('id', 'title', 'status', 'deadline', 'created_by', 'assigned_to')
            ->withCount(['attachments', 'comments'])
            ->with([
                'assignedTo:id,name,surname',
                'creator:id,name,surname',
            ])
            ->orderBy('id', 'desc')
            ->get();

        return [
            'new' => $tasks->where('status', 'new')->values(),
            'in_progress' => $tasks->where('status', 'in_progress')->values(),
            'completed' => $tasks->where('status', 'completed')->values(),
        ];
    }
    public function show(int $id)
    {
        return Task::select('id', 'title', 'description', 'status', 'deadline', 'start_date', 'priority')
            ->with([
                'attachments:id,task_id,original_name,file_path,mime_type,size',
                'comments:id,task_id,comment',
                'assignedTo:id,name,surname',
                'creator:id,name,surname',
            ])
            ->find($id);
    }

    public function create(array $data): Task
    {

        $task = Task::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => 'new',
            'priority' => $data['priority'] ?? 'low',

            'created_by' => auth()->user()->id,
            'assigned_to' => $data['assigned_to'] ?? null,
            'pawnshop_id' => auth()->user()->pawnshop_id ?? null,

            'deadline' => $data['deadline'] ?? null,
            'start_date' => $data['start_date'] ?? null,
        ]);

        if (!empty($data['attachments'])) {
            foreach ($data['attachments'] as $file) {

                $path = $file->store("uploads/tasks/{$task->id}", 'public');

                TaskAttachment::create([
                    'task_id' => $task->id,
                    'uploaded_by' => Auth::id(),
                    'original_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);
            }
        }

        return $task;
    }
    public function update(int $id, array $data): ?Task
    {
        $task = Task::findOrFail($id);

        $task->update([
            'title' => $data['title'] ?? $task->title,
            'description' => $data['description'] ?? $task->description,
            'status' => $data['status'] ?? $task->status,
            'priority' => $data['priority'] ?? $task->priority,
            'assigned_to' => $data['assigned_to'] ?? $task->assigned_to,
            'deadline' => $data['deadline'] ?? $task->deadline,
            'start_date' => $data['start_date'] ?? $task->start_date,
        ]);

        if (!empty($data['attachments'])) {
            foreach ($data['attachments'] as $file) {
                $path = $file->store("uploads/tasks/{$task->id}", 'public');

                TaskAttachment::create([
                    'task_id' => $task->id,
                    'uploaded_by' => auth()->id(),
                    'original_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);
            }
        }
        return $task;
    }
}
