<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class PublishConsentFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor holds an admin/director role.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'requires_signature' => ['nullable', 'boolean'],
        ];
    }
}
