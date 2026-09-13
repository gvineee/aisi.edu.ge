<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies the actor is submitting as their own enrollment.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['nullable', 'file', 'max:20480'],
            'text_response' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasFile('file') && trim((string) $this->input('text_response')) === '') {
                $validator->errors()->add('text_response', 'ატვირთეთ ფაილი ან შეიყვანეთ პასუხის ტექსტი.');
            }
        });
    }
}
