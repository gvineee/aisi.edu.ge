<?php

namespace App\Domain\PickupConsent\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\PickupConsent\Models\ConsentForm;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Publishes a new consent form (admin/director only — checked by the
 * controller via TenantMembership::userHasAnyActiveRole). Forms are
 * immutable once created: a policy change is a new form, per the module
 * brief ("text change creates a new request"), so there is no update action
 * here. The checksum of the exact text is stored so every answer can be
 * tied to the version it agreed to.
 */
class PublishConsentForm
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(int $tenantId, User $publisher, string $title, string $body, bool $requiresSignature, ?Carbon $responsesCloseAt = null): ConsentForm
    {
        if ($responsesCloseAt !== null && $responsesCloseAt->isPast()) {
            throw ValidationException::withMessages(['responses_close_at' => 'პასუხის ბოლო ვადა მომავალში უნდა იყოს.']);
        }

        $form = new ConsentForm([
            'title' => $title,
            'body' => $body,
            'body_checksum' => ConsentForm::checksumFor($title, $body),
            'requires_signature' => $requiresSignature,
            'responses_close_at' => $responsesCloseAt,
            'created_by' => $publisher->id,
        ]);
        $form->tenant_id = $tenantId;
        $form->save();

        $this->auditLogger->record($tenantId, 'consent_form.published', $form, $publisher->id, [
            'title' => $title,
            'checksum' => $form->body_checksum,
        ]);

        return $form;
    }
}
