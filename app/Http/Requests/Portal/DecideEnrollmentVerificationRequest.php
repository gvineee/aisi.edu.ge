<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideEnrollmentVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor is admin/director for this tenant.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'student_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
