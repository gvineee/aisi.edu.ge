<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class AssignSubstituteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor's role and every id's tenant ownership.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lesson_id' => ['required', 'integer'],
            'substitute_teacher_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'absence_id' => ['nullable', 'integer'],
        ];
    }
}
