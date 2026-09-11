<?php

namespace App\Http\Requests\Portal;

use App\Domain\Tenancy\Models\EnrollmentVerificationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnrollmentVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Any authenticated user may claim an identity for themselves; nothing here is tenant/role-privileged.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'requested_role' => ['required', Rule::in([
                EnrollmentVerificationRequest::ROLE_STUDENT,
                EnrollmentVerificationRequest::ROLE_GUARDIAN,
            ])],
            'national_id' => ['nullable', 'string', 'max:32'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
        ];
    }
}
