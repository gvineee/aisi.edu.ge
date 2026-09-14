<?php

namespace App\Http\Requests\Portal;

use App\Domain\Admissions\Models\AdmissionLead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvanceAdmissionLeadStageRequest extends FormRequest
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
            'stage' => ['required', Rule::in(AdmissionLead::STAGES)],
        ];
    }
}
