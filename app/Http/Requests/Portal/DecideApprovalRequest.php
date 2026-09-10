<?php

namespace App\Http\Requests\Portal;

use App\Domain\Documents\Models\ApprovalDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller checks the director role explicitly.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([
                ApprovalDecision::DECISION_APPROVED,
                ApprovalDecision::DECISION_RETURNED,
            ])],
            // The Action itself enforces "required when returned" so the
            // rule can't be bypassed by a client that skips this request
            // class's validation path.
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
