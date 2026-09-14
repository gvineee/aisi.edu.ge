<?php

namespace App\Domain\Admissions\Actions;

use App\Domain\Admissions\Models\AdmissionAppointment;
use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduleAppointment
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(AdmissionLead $lead, User $actor, Carbon $scheduledAt, ?string $notes): AdmissionAppointment
    {
        return DB::transaction(function () use ($lead, $actor, $scheduledAt, $notes) {
            $appointment = new AdmissionAppointment([
                'admission_lead_id' => $lead->id,
                'scheduled_at' => $scheduledAt,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);
            $appointment->tenant_id = $lead->tenant_id;
            $appointment->save();

            $this->auditLogger->record($lead->tenant_id, 'admission_lead.appointment_scheduled', $lead, $actor->id, [
                'appointment_id' => $appointment->id,
                'scheduled_at' => $scheduledAt->toIso8601String(),
            ]);

            return $appointment;
        });
    }
}
