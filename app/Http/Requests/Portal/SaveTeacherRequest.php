<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class SaveTeacherRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'photo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ];
    }
}
