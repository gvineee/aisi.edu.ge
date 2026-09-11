<?php

namespace App\Http\Requests\Portal;

use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantInvitationRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in([
                TenantMembership::ROLE_STUDENT,
                TenantMembership::ROLE_GUARDIAN,
                TenantMembership::ROLE_TEACHER,
                TenantMembership::ROLE_ACADEMIC_MANAGER,
                TenantMembership::ROLE_ACCOUNTANT,
                TenantMembership::ROLE_EDITOR,
                TenantMembership::ROLE_ADMIN,
                TenantMembership::ROLE_DIRECTOR,
            ])],
            'student_id' => ['required_if:role,'.TenantMembership::ROLE_GUARDIAN, 'nullable', 'integer'],
            'school_class_id' => ['required_if:role,'.TenantMembership::ROLE_TEACHER, 'nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:255'],
            'can_view_academic' => ['boolean'],
            'can_view_financial' => ['boolean'],
            'can_pickup' => ['boolean'],
            'can_receive_notifications' => ['boolean'],
        ];
    }
}
