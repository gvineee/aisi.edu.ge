<?php

namespace App\Domain\Health\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\Health\HealthAccess;
use App\Domain\Health\Models\CarePlanDocument;
use App\Domain\Health\Models\EmergencyContact;
use App\Domain\Health\Models\HealthCondition;
use App\Domain\Health\Models\HealthIncident;
use App\Domain\Health\Models\Medication;
use App\Domain\Health\Models\StudentHealthProfile;
use App\Domain\Uploads\ScanStatus;
use App\Jobs\ScanUploadedFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Every write to a student's health record. Who may call which method is
 * decided by HealthController (health.manage, or health.incident.create
 * for incidents). Each write is recorded twice, both without health
 * content: a health_access_event (for the record's own access history) and
 * an audit event with ids only.
 */
class UpdateHealthRecord
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly HealthAccess $access,
    ) {}

    public function saveProfile(int $tenantId, Student $student, User $actor, ?string $familyDoctor, ?string $notes): StudentHealthProfile
    {
        $profile = StudentHealthProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('student_id', $student->id)
            ->first() ?? new StudentHealthProfile(['student_id' => $student->id]);

        $profile->tenant_id = $tenantId;
        $profile->family_doctor = $this->clean($familyDoctor);
        $profile->notes = $this->clean($notes);
        $profile->updated_by = $actor->id;
        $profile->save();

        return $this->recorded($tenantId, $student, $actor, 'profile_updated', $profile);
    }

    public function addCondition(int $tenantId, Student $student, User $actor, string $name, string $severity, ?string $actionPlan, bool $showAlert, ?string $staffAlert): HealthCondition
    {
        if (! in_array($severity, HealthCondition::SEVERITIES, true)) {
            throw ValidationException::withMessages(['severity' => 'მნიშვნელობა არასწორია.']);
        }

        if ($showAlert && $this->clean($staffAlert) === null) {
            throw ValidationException::withMessages(['staff_alert' => 'მასწავლებლებისთვის ხილული გაფრთხილების ტექსტი სავალდებულოა.']);
        }

        return $this->create($tenantId, $student, $actor, 'condition_added', new HealthCondition([
            'student_id' => $student->id,
            'name' => trim($name),
            'severity' => $severity,
            'action_plan' => $this->clean($actionPlan),
            'show_alert_to_staff' => $showAlert,
            'staff_alert' => $showAlert ? $this->clean($staffAlert) : null,
            'updated_by' => $actor->id,
        ]));
    }

    public function addMedication(int $tenantId, Student $student, User $actor, string $name, ?string $instructions): Medication
    {
        return $this->create($tenantId, $student, $actor, 'medication_added', new Medication([
            'student_id' => $student->id,
            'name' => trim($name),
            'instructions' => $this->clean($instructions),
            'updated_by' => $actor->id,
        ]));
    }

    public function addContact(int $tenantId, Student $student, User $actor, string $name, ?string $relationship, string $phone, int $priority): EmergencyContact
    {
        return $this->create($tenantId, $student, $actor, 'contact_added', new EmergencyContact([
            'student_id' => $student->id,
            'name' => trim($name),
            'relationship' => $this->clean($relationship),
            'phone' => trim($phone),
            'priority' => $priority,
            'updated_by' => $actor->id,
        ]));
    }

    public function recordIncident(int $tenantId, Student $student, User $actor, Carbon $occurredAt, string $summary, ?string $actionTaken, ?Carbon $guardianNotifiedAt): HealthIncident
    {
        if ($occurredAt->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => 'ინციდენტის დრო მომავალში ვერ იქნება.']);
        }

        return $this->create($tenantId, $student, $actor, 'incident_recorded', new HealthIncident([
            'student_id' => $student->id,
            'occurred_at' => $occurredAt,
            'summary' => trim($summary),
            'action_taken' => $this->clean($actionTaken),
            'guardian_notified_at' => $guardianNotifiedAt,
            'reported_by' => $actor->id,
        ]));
    }

    public function addCarePlan(int $tenantId, Student $student, User $actor, string $title, UploadedFile $file): CarePlanDocument
    {
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, (array) config('health.care_plan_mime_types'), true)) {
            throw ValidationException::withMessages(['file' => 'დაშვებულია მხოლოდ PDF, PNG ან JPEG.']);
        }

        if (($file->getSize() ?: 0) > (int) config('health.care_plan_max_bytes')) {
            throw ValidationException::withMessages(['file' => 'ფაილი ძალიან დიდია (მაქს. 10MB).']);
        }

        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            default => 'jpg',
        };
        // Random file name: neither the student nor the condition appears in the path.
        $path = "health/{$tenantId}/".bin2hex(random_bytes(16)).".{$extension}";
        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        $document = $this->create($tenantId, $student, $actor, 'care_plan_uploaded', new CarePlanDocument([
            'student_id' => $student->id,
            'title' => trim($title),
            'storage_path' => $path,
            'mime' => $mime,
            'size' => $file->getSize() ?: 0,
            'checksum' => (string) hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $actor->id,
            'scan_status' => ScanStatus::PENDING,
        ]));

        ScanUploadedFile::dispatch('care_plan_documents', $document->id, $path);

        return $document;
    }

    /**
     * Removes a condition, medication, contact or care plan (the latter
     * with its file). Kept as a hard delete of the item, with the removal
     * itself recorded in both logs.
     */
    public function remove(int $tenantId, Student $student, User $actor, Model $item): void
    {
        if ((int) $item->getAttribute('tenant_id') !== $tenantId || (int) $item->getAttribute('student_id') !== $student->id) {
            abort(404);
        }

        if ($item instanceof CarePlanDocument) {
            Storage::disk('local')->delete($item->storage_path);
        }

        $type = class_basename($item);
        $id = $item->getKey();
        $item->delete();

        $this->access->log($tenantId, $student, $actor, 'item_removed', HealthAccess::BASIS_FULL);
        $this->auditLogger->record($tenantId, 'health.item_removed', $student, $actor->id, ['type' => $type, 'id' => $id]);
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    private function create(int $tenantId, Student $student, User $actor, string $action, Model $model): Model
    {
        $model->setAttribute('tenant_id', $tenantId);
        $model->save();

        return $this->recorded($tenantId, $student, $actor, $action, $model);
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    private function recorded(int $tenantId, Student $student, User $actor, string $action, Model $model): Model
    {
        $this->access->log($tenantId, $student, $actor, $action, HealthAccess::BASIS_FULL);
        $this->auditLogger->record($tenantId, 'health.'.$action, $model, $actor->id, ['student_id' => $student->id]);

        return $model;
    }

    private function clean(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
