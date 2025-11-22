<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'status' => 'sometimes|string|in:new,in_progress,completed',
            'priority' => 'sometimes|string|in:low,medium,high',
            'assigned_to' => 'sometimes|nullable|integer|exists:users,id',
            'deadline' => 'sometimes|nullable|date',
            'start_date' => 'sometimes|nullable|date',
            'attachments.*' => 'sometimes|file|max:10240'
        ];
    }
}
