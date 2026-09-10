<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class DecidePortfolioItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the reviewer teaches this student's class.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:publish,return'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
