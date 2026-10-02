<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class ReopenAssessmentPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor's role for this tenant.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
