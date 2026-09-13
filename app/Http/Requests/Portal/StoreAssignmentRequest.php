<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor owns the teacher_assignment.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'teacher_assignment_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'due_at' => ['nullable', 'date'],
            'max_score' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
