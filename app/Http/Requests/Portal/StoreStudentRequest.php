<?php

namespace App\Http\Requests\Portal;

use App\Domain\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor's role and the school class's tenant ownership.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'school_class_id' => ['required', 'integer'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'national_id' => [
                'nullable', 'string', 'max:255',
                Rule::unique('students')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
        ];
    }
}
