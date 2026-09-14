<?php

namespace App\Domain\PickupConsent\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\PickupConsent\Models\ConsentForm;
use App\Domain\PickupConsent\Models\ConsentResponse;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records a guardian's grant/deny decision on a ConsentForm for one child.
 * `consent_responses` is unique on (consent_form_id, student_id): responding
 * again updates the existing row (new guardian_id/granted/responded_at)
 * rather than creating a second one, per the module brief. The controller
 * must already have verified the caller is this student's own active
 * guardian before calling this.
 */
class RespondToConsent
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(ConsentForm $form, Student $student, User $guardian, bool $granted): ConsentResponse
    {
        return DB::transaction(function () use ($form, $student, $guardian, $granted): ConsentResponse {
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

            $response->guardian_id = $guardian->id;
            $response->granted = $granted;
            $response->responded_at = Carbon::now();
            $response->save();

            $this->auditLogger->record($form->tenant_id, $isNew ? 'consent_response.recorded' : 'consent_response.updated', $response, $guardian->id, [
                'consent_form_id' => $form->id,
                'student_id' => $student->id,
                'granted' => $granted,
            ]);

            return $response;
        });
    }
}
