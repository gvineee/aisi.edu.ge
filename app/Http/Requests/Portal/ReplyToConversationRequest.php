<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class ReplyToConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller verifies sender is an active participant.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
