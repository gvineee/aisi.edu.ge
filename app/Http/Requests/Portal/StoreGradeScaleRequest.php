<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class StoreGradeScaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor's role; band consistency is checked in CreateGradeScale.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'bands' => ['required', 'array', 'min:1', 'max:20'],
            'bands.*.min_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'bands.*.label' => ['required', 'string', 'max:32'],
        ];
    }
}
