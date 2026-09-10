<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The initial public interest form only collects what docs/02 section 5.1
 * allows: guardian name, one contact method, desired grade, and explicit
 * consent. It must never ask for a child's personal ID or medical history —
 * that belongs to the separate, protected full application flow.
 */
class StoreAdmissionLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'guardian_name' => ['required', 'string', 'max:120'],
            'contact_method' => ['required', Rule::in(['email', 'phone'])],
            'contact_value' => ['required', 'string', 'max:190'],
            'desired_grade' => ['nullable', 'string', 'max:60'],
            'preferred_date' => ['nullable', 'date'],
            'consent_given' => ['required', 'accepted'],
        ];
    }
}
