<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class GradeSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the reviewer owns the assignment; the max-score bound is checked in GradeSubmission.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'score' => ['required', 'integer', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
