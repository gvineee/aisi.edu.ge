<?php

namespace App\Http\Requests\Portal;

use App\Domain\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicYearRequest extends FormRequest
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
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('academic_years')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'is_current' => ['boolean'],
        ];
    }
}
