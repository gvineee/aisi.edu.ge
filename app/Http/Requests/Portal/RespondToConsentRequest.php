<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class RespondToConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor is this student's own active guardian.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer'],
            'granted' => ['required', 'boolean'],
        ];
    }
}
