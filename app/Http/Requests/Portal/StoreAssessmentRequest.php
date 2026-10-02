<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the teacher owns the teacher_assignment and every id's tenant.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'teacher_assignment_id' => ['required', 'integer'],
            'assessment_period_id' => ['required', 'integer'],
            'assessment_category_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'assessed_on' => ['required', 'date'],
            'max_score' => ['required', 'numeric', 'min:0.01', 'max:9999'],
        ];
    }
}
