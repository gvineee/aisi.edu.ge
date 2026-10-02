<?php

namespace App\Http\Requests\Portal;

use App\Domain\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssessmentCategoryRequest extends FormRequest
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
                Rule::unique('assessment_categories')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
        ];
    }
}
