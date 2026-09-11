<?php

namespace App\Http\Requests\Public;

use App\Concerns\PasswordValidationRules;
use App\Domain\Tenancy\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rules differ depending on whether the invited email already has a User:
 * a brand-new invitee sets a name and password; someone who already has an
 * account just confirms joining (no password field — see
 * AcceptTenantInvitation's docblock on why we never reset an existing
 * account's password from an invitation link).
 */
class AcceptInvitationRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return true; // Public flow; the token itself is the credential, checked in the controller.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->invitedUserAlreadyExists()) {
            return [
                'name' => ['nullable', 'string', 'max:255'],
            ];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ];
    }

    private function invitedUserAlreadyExists(): bool
    {
        $token = $this->route('token');

        if (! is_string($token)) {
            return false;
        }

        $invitation = TenantInvitation::query()->where('token', $token)->first();

        return $invitation !== null && User::query()->where('email', $invitation->email)->exists();
    }
}
