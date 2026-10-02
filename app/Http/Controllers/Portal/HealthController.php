<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Health\Actions\ManageEmergencyAccess;
use App\Domain\Health\Actions\ManageHealthGrants;
use App\Domain\Health\Actions\UpdateHealthRecord;
use App\Domain\Health\HealthAccess;
use App\Domain\Health\Models\CarePlanDocument;
use App\Domain\Health\Models\EmergencyContact;
use App\Domain\Health\Models\HealthAccessEvent;
use App\Domain\Health\Models\HealthCondition;
use App\Domain\Health\Models\HealthEmergencyAccess;
use App\Domain\Health\Models\HealthIncident;
use App\Domain\Health\Models\HealthPermissionGrant;
use App\Domain\Health\Models\Medication;
use App\Domain\Health\Models\StudentHealthProfile;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Uploads\ScanStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "ჯანმრთელობა" (CLAUDE-PLATFORM-MODULES §8). Every decision about what a
 * viewer may see comes from HealthAccess; every read and write is logged
 * as a health_access_event that never contains the health data itself.
 */
class HealthController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const GRANT_MANAGER_ROLES = [
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * @var array<string, string>
     */
    private const CAPABILITY_LABELS = [
        HealthPermissionGrant::VIEW => 'ჩანაწერის ნახვა',
        HealthPermissionGrant::MANAGE => 'ჩანაწერის მართვა',
        HealthPermissionGrant::INCIDENT_CREATE => 'ინციდენტის ჩაწერა',
        HealthPermissionGrant::AUDIT_VIEW => 'წვდომის ისტორიის ნახვა',
    ];

    public function __construct(private readonly HealthAccess $access) {}

    /**
     * Staff entry point: teachers see the actionable alerts of their own
     * classes; holders of a health capability get the student list and,
     * with audit rights, the emergency-access review queue.
     */
    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless($this->access->isStaff($tenant->id, $user), 403);

        $capabilities = $this->access->capabilities($tenant->id, $user);
        $hasMfa = $this->access->hasMfa($user);

        $classIds = TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER)
            ? TeacherAssignment::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('school_class_id')
                ->merge(SchoolClass::query()->where('tenant_id', $tenant->id)->where('homeroom_teacher_id', $user->id)->pluck('id'))
                ->unique()->values()->all()
            : [];

        $alertConditions = $classIds === [] ? collect() : HealthCondition::query()
            ->where('tenant_id', $tenant->id)
            ->where('show_alert_to_staff', true)
            ->whereIn('student_id', Student::query()->where('tenant_id', $tenant->id)->where('is_active', true)->whereIn('school_class_id', $classIds)->select('id'))
            ->get();

        $alertStudents = Student::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $alertConditions->pluck('student_id')->unique())
            ->with('schoolClass')
            ->get();

        foreach ($alertStudents as $student) {
            $this->access->log($tenant->id, $student, $user, 'view_class_alerts', HealthAccess::BASIS_ALERTS_ONLY);
        }

        $canBrowse = $hasMfa && array_intersect($capabilities, [HealthPermissionGrant::VIEW, HealthPermissionGrant::MANAGE, HealthPermissionGrant::INCIDENT_CREATE]) !== [];
        $canAudit = $hasMfa && in_array(HealthPermissionGrant::AUDIT_VIEW, $capabilities, true);

        $students = $canBrowse
            ? Student::query()->where('tenant_id', $tenant->id)->where('is_active', true)->with('schoolClass')->orderBy('last_name')->orderBy('first_name')->get()
            : collect();

        $reviewQueue = $canAudit
            ? HealthEmergencyAccess::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('reviewed_at')
                ->with('student')
                ->latest('id')
                ->get()
            : collect();

        $reviewers = User::query()->whereIn('id', $reviewQueue->pluck('user_id'))->pluck('name', 'id');

        return Inertia::render('portal/health/index', [
            'alerts' => $alertStudents->map(fn (Student $student) => [
                'studentId' => $student->id,
                'studentName' => $student->fullName(),
                'className' => $student->schoolClass?->name,
                'alerts' => $alertConditions->where('student_id', $student->id)->map(fn (HealthCondition $condition) => [
                    'severity' => $condition->severity,
                    'text' => $condition->staff_alert,
                ])->values()->all(),
            ])->sortBy('studentName')->values(),
            'students' => $students->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'className' => $student->schoolClass?->name,
            ])->values(),
            'reviewQueue' => $reviewQueue->map(fn (HealthEmergencyAccess $emergency) => [
                'id' => $emergency->id,
                'studentId' => $emergency->student_id,
                'studentName' => $emergency->student?->fullName(),
                'userName' => $reviewers[$emergency->user_id] ?? null,
                'reason' => $emergency->reason,
                'createdAt' => $emergency->created_at?->toIso8601String(),
                'expiresAt' => $emergency->expires_at->toIso8601String(),
            ])->values(),
            'capabilities' => $capabilities,
            'needsMfa' => $capabilities !== [] && ! $hasMfa,
            'canManageGrants' => TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::GRANT_MANAGER_ROLES),
        ]);
    }

    public function show(Request $request, CurrentTenant $currentTenant, Student $student): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        $user = $request->user();

        $resolved = $this->access->resolve($tenant->id, $user, $student);
        $basis = $resolved['basis'];
        $isStaff = $this->access->isStaff($tenant->id, $user);

        if ($basis === HealthAccess::BASIS_NONE && ! $isStaff) {
            abort(403);
        }

        $capabilities = $this->access->capabilities($tenant->id, $user);
        $hasMfa = $this->access->hasMfa($user);
        $seesFull = in_array($basis, [HealthAccess::BASIS_FULL, HealthAccess::BASIS_GUARDIAN, HealthAccess::BASIS_EMERGENCY], true);

        if ($basis !== HealthAccess::BASIS_NONE) {
            $this->access->log($tenant->id, $student, $user, $seesFull ? 'view_record' : 'view_alerts', $basis, $resolved['emergency']);
        }

        $conditions = HealthCondition::query()->where('tenant_id', $tenant->id)->where('student_id', $student->id)->orderByRaw("case severity when 'high' then 0 when 'medium' then 1 else 2 end")->get();

        $full = null;

        if ($seesFull) {
            $profile = StudentHealthProfile::query()->where('tenant_id', $tenant->id)->where('student_id', $student->id)->first();

            $full = [
                'familyDoctor' => $profile?->family_doctor,
                'notes' => $profile?->notes,
                'conditions' => $conditions->map(fn (HealthCondition $condition) => [
                    'id' => $condition->id,
                    'name' => $condition->name,
                    'severity' => $condition->severity,
                    'actionPlan' => $condition->action_plan,
                    'showAlertToStaff' => $condition->show_alert_to_staff,
                    'staffAlert' => $condition->staff_alert,
                ])->values(),
                'medications' => Medication::query()->where('tenant_id', $tenant->id)->where('student_id', $student->id)->get()
                    ->map(fn (Medication $medication) => ['id' => $medication->id, 'name' => $medication->name, 'instructions' => $medication->instructions])->values(),
                'contacts' => EmergencyContact::query()->where('tenant_id', $tenant->id)->where('student_id', $student->id)->orderBy('priority')->get()
                    ->map(fn (EmergencyContact $contact) => [
                        'id' => $contact->id, 'name' => $contact->name, 'relationship' => $contact->relationship,
                        'phone' => $contact->phone, 'priority' => $contact->priority,
                    ])->values(),
                'carePlans' => CarePlanDocument::query()->where('tenant_id', $tenant->id)->where('student_id', $student->id)->latest('id')->get()
                    ->map(fn (CarePlanDocument $document) => [
                        'id' => $document->id,
                        'title' => $document->title,
                        'uploadedAt' => $document->created_at?->toIso8601String(),
                        'downloadUrl' => URL::temporarySignedRoute('health.care-plans.download', now()->addMinutes((int) config('health.download_link_minutes', 5)), [$student->id, $document->id]),
                    ])->values(),
                'incidents' => HealthIncident::query()->where('tenant_id', $tenant->id)->where('student_id', $student->id)->latest('occurred_at')->limit(50)->get()
                    ->map(fn (HealthIncident $incident) => [
                        'id' => $incident->id,
                        'occurredAt' => $incident->occurred_at->toIso8601String(),
                        'summary' => $incident->summary,
                        'actionTaken' => $incident->action_taken,
                        'guardianNotifiedAt' => $incident->guardian_notified_at?->toIso8601String(),
                    ])->values(),
            ];
        }

        $student->loadMissing('schoolClass');

        return Inertia::render('portal/health/show', [
            'student' => ['id' => $student->id, 'name' => $student->fullName(), 'className' => $student->schoolClass?->name],
            'basis' => $basis,
            // A staff member with no basis sees nothing until they open emergency access.
            'alerts' => $basis === HealthAccess::BASIS_NONE ? [] : $conditions->where('show_alert_to_staff', true)->map(fn (HealthCondition $condition) => [
                'severity' => $condition->severity,
                'text' => $condition->staff_alert,
            ])->values(),
            'record' => $full,
            'emergencyExpiresAt' => $resolved['emergency']?->expires_at->toIso8601String(),
            'needsMfa' => $resolved['needsMfa'],
            'can' => [
                'manage' => $basis === HealthAccess::BASIS_FULL && in_array(HealthPermissionGrant::MANAGE, $capabilities, true),
                'createIncident' => $hasMfa && array_intersect($capabilities, [HealthPermissionGrant::MANAGE, HealthPermissionGrant::INCIDENT_CREATE]) !== [],
                'viewAudit' => $hasMfa && in_array(HealthPermissionGrant::AUDIT_VIEW, $capabilities, true),
                'requestEmergency' => $isStaff && in_array($basis, [HealthAccess::BASIS_ALERTS_ONLY, HealthAccess::BASIS_NONE], true),
            ],
        ]);
    }

    public function saveProfile(Request $request, CurrentTenant $currentTenant, Student $student, UpdateHealthRecord $update): RedirectResponse
    {
        $tenantId = $this->authorizeManage($request, $currentTenant, $student);
        $data = $request->validate([
            'family_doctor' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $update->saveProfile($tenantId, $student, $request->user(), $data['family_doctor'] ?? null, $data['notes'] ?? null);

        return back();
    }

    public function storeCondition(Request $request, CurrentTenant $currentTenant, Student $student, UpdateHealthRecord $update): RedirectResponse
    {
        $tenantId = $this->authorizeManage($request, $currentTenant, $student);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'in:'.implode(',', HealthCondition::SEVERITIES)],
            'action_plan' => ['nullable', 'string', 'max:5000'],
            'show_alert_to_staff' => ['boolean'],
            'staff_alert' => ['nullable', 'string', 'max:500'],
        ]);

        $update->addCondition($tenantId, $student, $request->user(), $data['name'], $data['severity'], $data['action_plan'] ?? null, $request->boolean('show_alert_to_staff'), $data['staff_alert'] ?? null);

        return back();
    }

    public function storeMedication(Request $request, CurrentTenant $currentTenant, Student $student, UpdateHealthRecord $update): RedirectResponse
    {
        $tenantId = $this->authorizeManage($request, $currentTenant, $student);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $update->addMedication($tenantId, $student, $request->user(), $data['name'], $data['instructions'] ?? null);

        return back();
    }

    public function storeContact(Request $request, CurrentTenant $currentTenant, Student $student, UpdateHealthRecord $update): RedirectResponse
    {
        $tenantId = $this->authorizeManage($request, $currentTenant, $student);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\s-]{5,40}$/'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9'],
        ]);

        $update->addContact($tenantId, $student, $request->user(), $data['name'], $data['relationship'] ?? null, $data['phone'], (int) ($data['priority'] ?? 1));

        return back();
    }

    public function storeCarePlan(Request $request, CurrentTenant $currentTenant, Student $student, UpdateHealthRecord $update): RedirectResponse
    {
        $tenantId = $this->authorizeManage($request, $currentTenant, $student);
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        $update->addCarePlan($tenantId, $student, $request->user(), $request->string('title')->toString(), $file);

        return back();
    }

    public function destroyItem(Request $request, CurrentTenant $currentTenant, Student $student, string $type, int $id, UpdateHealthRecord $update): RedirectResponse
    {
        $tenantId = $this->authorizeManage($request, $currentTenant, $student);

        $class = match ($type) {
            'conditions' => HealthCondition::class,
            'medications' => Medication::class,
            'contacts' => EmergencyContact::class,
            'care-plans' => CarePlanDocument::class,
            default => abort(404),
        };

        $item = $class::query()->where('tenant_id', $tenantId)->where('student_id', $student->id)->whereKey($id)->firstOrFail();
        $update->remove($tenantId, $student, $request->user(), $item);

        return back();
    }

    public function storeIncident(Request $request, CurrentTenant $currentTenant, Student $student, UpdateHealthRecord $update): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        $user = $request->user();

        $capabilities = $this->access->capabilities($tenant->id, $user);
        abort_unless($this->access->hasMfa($user) && array_intersect($capabilities, [HealthPermissionGrant::MANAGE, HealthPermissionGrant::INCIDENT_CREATE]) !== [], 403);

        $data = $request->validate([
            'occurred_at' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:2000'],
            'guardian_notified_at' => ['nullable', 'date'],
        ]);

        $update->recordIncident(
            $tenant->id,
            $student,
            $user,
            Carbon::parse($data['occurred_at'], 'Asia/Tbilisi')->utc(),
            $data['summary'],
            $data['action_taken'] ?? null,
            isset($data['guardian_notified_at']) ? Carbon::parse($data['guardian_notified_at'], 'Asia/Tbilisi')->utc() : null,
        );

        return back();
    }

    public function downloadCarePlan(Request $request, CurrentTenant $currentTenant, Student $student, CarePlanDocument $document): StreamedResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        abort_unless($document->tenant_id === $tenant->id && $document->student_id === $student->id, 404);

        // The signed URL only proves the link is fresh; access is re-checked now.
        $resolved = $this->access->resolve($tenant->id, $request->user(), $student);
        abort_unless(in_array($resolved['basis'], [HealthAccess::BASIS_FULL, HealthAccess::BASIS_GUARDIAN, HealthAccess::BASIS_EMERGENCY], true), 403);

        abort_unless(ScanStatus::servable((string) $document->scan_status), 423, 'ფაილი ჯერ შემოწმების პროცესშია.');

        $this->access->log($tenant->id, $student, $request->user(), 'download_care_plan', $resolved['basis'], $resolved['emergency']);

        $extension = pathinfo($document->storage_path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($document->storage_path, "care-plan-{$document->id}.{$extension}", [
            'Content-Type' => $document->mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function requestEmergency(Request $request, CurrentTenant $currentTenant, Student $student, ManageEmergencyAccess $emergency): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $emergency->request($tenant->id, $request->user(), $student, $request->string('reason')->toString());

        return back();
    }

    public function accessLog(Request $request, CurrentTenant $currentTenant, Student $student): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        $this->authorizeAudit($request, $tenant->id);

        $events = HealthAccessEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('student_id', $student->id)
            ->latest('id')
            ->limit(200)
            ->get();

        $names = User::query()->whereIn('id', $events->pluck('actor_id')->unique())->pluck('name', 'id');

        $this->access->log($tenant->id, $student, $request->user(), 'view_access_log', HealthAccess::BASIS_FULL);

        return Inertia::render('portal/health/access-log', [
            'student' => ['id' => $student->id, 'name' => $student->fullName()],
            'events' => $events->map(fn (HealthAccessEvent $event) => [
                'id' => $event->id,
                'actorName' => $names[$event->actor_id] ?? null,
                'action' => $event->action,
                'basis' => $event->access_basis,
                'createdAt' => $event->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function reviewEmergency(Request $request, CurrentTenant $currentTenant, HealthEmergencyAccess $emergencyAccess, ManageEmergencyAccess $emergency): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($emergencyAccess->tenant_id === $tenant->id, 404);
        $this->authorizeAudit($request, $tenant->id);
        $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $emergency->review($emergencyAccess, $request->user(), $request->string('note')->toString() ?: null);

        return back();
    }

    public function permissions(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::GRANT_MANAGER_ROLES), 403);

        $grants = HealthPermissionGrant::query()->where('tenant_id', $tenant->id)->whereNull('revoked_at')->get();

        $staff = User::query()
            ->whereIn('id', TenantMembership::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->whereNotIn('role', [TenantMembership::ROLE_GUARDIAN, TenantMembership::ROLE_STUDENT])
                ->select('user_id'))
            ->orderBy('name')
            ->get();

        return Inertia::render('portal/health/permissions', [
            'capabilities' => collect(self::CAPABILITY_LABELS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'staff' => $staff->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'hasMfa' => $member->two_factor_confirmed_at !== null,
                'grants' => $grants->where('user_id', $member->id)->map(fn (HealthPermissionGrant $grant) => [
                    'id' => $grant->id,
                    'capability' => $grant->capability,
                ])->values()->all(),
            ])->values(),
        ]);
    }

    public function grant(Request $request, CurrentTenant $currentTenant, ManageHealthGrants $grants): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::GRANT_MANAGER_ROLES), 403);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'capability' => ['required', 'string'],
        ]);

        $grantee = User::query()->whereKey((int) $data['user_id'])->firstOrFail();
        $grants->grant($tenant->id, $request->user(), $grantee, $data['capability']);

        return back();
    }

    public function revoke(Request $request, CurrentTenant $currentTenant, HealthPermissionGrant $grant, ManageHealthGrants $grants): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($grant->tenant_id === $tenant->id, 404);
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::GRANT_MANAGER_ROLES), 403);

        $grants->revoke($grant, $request->user());

        return back();
    }

    private function authorizeManage(Request $request, CurrentTenant $currentTenant, Student $student): int
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        $user = $request->user();

        abort_unless($this->access->hasMfa($user) && $this->access->can($tenant->id, $user, HealthPermissionGrant::MANAGE), 403);

        return $tenant->id;
    }

    private function authorizeAudit(Request $request, int $tenantId): void
    {
        $user = $request->user();
        abort_unless($this->access->hasMfa($user) && $this->access->can($tenantId, $user, HealthPermissionGrant::AUDIT_VIEW), 403);
    }
}
