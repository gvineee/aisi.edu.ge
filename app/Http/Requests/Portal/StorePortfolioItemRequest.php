<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class StorePortfolioItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor is this student.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'subject_id' => ['nullable', 'integer'],
            'file' => ['nullable', 'file'],
        ];
    }
}
