<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class RecordAssessmentResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies ownership; roster, score bounds and closed periods are checked in RecordAssessmentResults.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'results' => ['required', 'array', 'min:1', 'max:200'],
            'results.*.student_id' => ['required', 'integer', 'distinct'],
            'results.*.raw_score' => ['required', 'numeric', 'min:0'],
            'results.*.comment' => ['nullable', 'string', 'max:1000'],
            'results.*.expected_version' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'results.*.raw_score.required' => 'ქულა სავალდებულოა.',
            'results.*.raw_score.numeric' => 'ქულა რიცხვი უნდა იყოს.',
        ];
    }
}
