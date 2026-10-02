<?php

namespace App\Domain\PickupConsent\Actions;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\PickupConsent\Models\ConsentForm;
use App\Domain\PickupConsent\Models\ConsentResponse;
use App\Domain\PickupConsent\Models\ConsentResponseEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a guardian's grant/decline decision on a ConsentForm for one
 * child. `consent_responses` holds the current answer per child (unique on
 * form + student); every decision — including a change of mind or a
 * revocation of an earlier grant — is also appended to
 * `consent_response_events` with the checksum of the exact text answered,
 * so the history is never overwritten. Declining is a full, valid answer.
 * After the form's cutoff no answer can be given or changed. The
 * controller must already have verified the caller's active guardian link.
 */
class RespondToConsent
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(ConsentForm $form, Student $student, User $guardian, bool $granted, ?GuardianLink $link = null): ConsentResponse
    {
        if ($form->isClosed()) {
            throw ValidationException::withMessages(['granted' => 'ამ თანხმობაზე პასუხის ვადა ამოიწურა.']);
        }

        return DB::transaction(function () use ($form, $student, $guardian, $granted, $link): ConsentResponse {
            /** @var ConsentResponse $response */
            $response = ConsentResponse::query()
                ->where('consent_form_id', $form->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first() ?? new ConsentResponse([
                    'consent_form_id' => $form->id,
                    'student_id' => $student->id,
                ]);

            $isNew = ! $response->exists;
            if ($isNew) {
                $response->tenant_id = $form->tenant_id;
            }

            $decision = $granted
                ? ConsentResponseEvent::GRANTED
                : ($response->granted === true ? ConsentResponseEvent::REVOKED : ConsentResponseEvent::DECLINED);

            $response->guardian_id = $guardian->id;
            $response->granted = $granted;
            $response->responded_at = Carbon::now();
            $response->save();

            $event = new ConsentResponseEvent([
                'consent_form_id' => $form->id,
                'student_id' => $student->id,
                'guardian_id' => $guardian->id,
                'guardian_link_id' => $link?->id,
                'decision' => $decision,
                'form_checksum' => $form->body_checksum ?? ConsentForm::checksumFor($form->title, $form->body),
            ]);
            $event->tenant_id = $form->tenant_id;
            $event->save();

            $this->auditLogger->record($form->tenant_id, $isNew ? 'consent_response.recorded' : 'consent_response.updated', $response, $guardian->id, [
                'consent_form_id' => $form->id,
                'student_id' => $student->id,
                'decision' => $decision,
            ]);

            return $response;
        });
    }
}
