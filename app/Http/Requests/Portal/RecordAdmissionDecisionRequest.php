<?php

namespace App\Http\Requests\Portal;

use App\Domain\Admissions\Models\AdmissionDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordAdmissionDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor's role and the lead's tenant ownership.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(AdmissionDecision::DECISIONS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
