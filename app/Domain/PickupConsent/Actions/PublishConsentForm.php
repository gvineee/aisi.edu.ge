<?php

namespace App\Domain\PickupConsent\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\PickupConsent\Models\ConsentForm;
use App\Models\User;

/**
 * Publishes a new consent form (admin/director only — checked by the
 * controller via TenantMembership::userHasAnyActiveRole). Forms are
 * immutable once created: a policy change is a new form, per the module
 * brief ("text change creates a new request"), so there is no update action
 * here.
 */
class PublishConsentForm
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(int $tenantId, User $publisher, string $title, string $body, bool $requiresSignature): ConsentForm
    {
        $form = new ConsentForm([
            'title' => $title,
            'body' => $body,
            'requires_signature' => $requiresSignature,
            'created_by' => $publisher->id,
        ]);
        $form->tenant_id = $tenantId;
        $form->save();

        $this->auditLogger->record($tenantId, 'consent_form.published', $form, $publisher->id, [
            'title' => $title,
        ]);

        return $form;
    }
}
