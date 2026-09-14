<?php

namespace App\Http\Requests\Portal;

use App\Domain\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSchoolClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor's role and the academic year's tenant ownership.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        $academicYearId = $this->integer('academic_year_id');

        return [
            'academic_year_id' => ['required', 'integer'],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('school_classes')->where(
                    fn ($query) => $query->where('tenant_id', $tenantId)->where('academic_year_id', $academicYearId)
                ),
            ],
        ];
    }
}
