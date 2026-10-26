<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\Room;
use App\Domain\Timetable\Models\Subject;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The timetable's catalogues: subjects and rooms. Renaming is always
 * allowed; deleting only what no lesson or workload uses, so history and
 * imported mappings stay intact.
 */
class TimetableCatalogController extends Controller
{
    /** @var list<string> */
    private const ACCESS_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenantId = $this->authorizeTenant($request, $currentTenant);
        $lessonsBySubject = Lesson::query()->where('tenant_id', $tenantId)->selectRaw('subject_id, count(*) as n')->groupBy('subject_id')->pluck('n', 'subject_id');
        $workloadsBySubject = TeacherAssignment::query()->where('tenant_id', $tenantId)->whereNotNull('subject_id')->selectRaw('subject_id, count(*) as n')->groupBy('subject_id')->pluck('n', 'subject_id');
        $lessonsByRoom = Lesson::query()->where('tenant_id', $tenantId)->whereNotNull('room_id')->selectRaw('room_id, count(*) as n')->groupBy('room_id')->pluck('n', 'room_id');

        return Inertia::render('portal/timetable/catalogs', [
            'subjects' => Subject::query()->where('tenant_id', $tenantId)->orderBy('name')->get()->map(fn (Subject $s) => [
                'id' => $s->id, 'name' => $s->name, 'shortName' => $s->short_name,
                'uses' => (int) ($lessonsBySubject[$s->id] ?? 0) + (int) ($workloadsBySubject[$s->id] ?? 0),
            ])->values(),
            'rooms' => Room::query()->where('tenant_id', $tenantId)->orderBy('name')->get()->sortBy('name', SORT_NATURAL)->map(fn (Room $r) => [
                'id' => $r->id, 'name' => $r->name, 'uses' => (int) ($lessonsByRoom[$r->id] ?? 0),
            ])->values(),
        ]);
    }

    public function saveSubject(Request $request, CurrentTenant $currentTenant, AuditLogger $auditLogger, ?Subject $subject = null): RedirectResponse
    {
        $tenantId = $this->authorizeTenant($request, $currentTenant);
        abort_if($subject !== null && $subject->tenant_id !== $tenantId, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('subjects', 'name')->where('tenant_id', $tenantId)->ignore($subject?->id)],
            'short_name' => ['nullable', 'string', 'max:40'],
        ]);

        $subject ??= new Subject;
        $subject->fill(['name' => trim($data['name']), 'short_name' => ($data['short_name'] ?? null) ?: null]);
        $subject->tenant_id = $tenantId;
        $subject->save();
        $auditLogger->record($tenantId, $subject->wasRecentlyCreated ? 'subject.created' : 'subject.updated', $subject, $request->user()->id);

        return back()->with('toast', ['type' => 'success', 'message' => 'საგანი შენახულია.']);
    }

    public function destroySubject(Request $request, CurrentTenant $currentTenant, Subject $subject, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $this->authorizeTenant($request, $currentTenant);
        abort_unless($subject->tenant_id === $tenantId, 404);
        $used = Lesson::query()->where('subject_id', $subject->id)->exists()
            || TeacherAssignment::query()->where('subject_id', $subject->id)->exists();

        if ($used) {
            return back()->withErrors(['subject' => 'საგანი გაკვეთილებში ან დატვირთვაში გამოიყენება — წაშლა შეუძლებელია.']);
        }

        $auditLogger->record($tenantId, 'subject.deleted', $subject, $request->user()->id, ['name' => $subject->name]);
        $subject->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'საგანი წაიშალა.']);
    }

    public function saveRoom(Request $request, CurrentTenant $currentTenant, AuditLogger $auditLogger, ?Room $room = null): RedirectResponse
    {
        $tenantId = $this->authorizeTenant($request, $currentTenant);
        abort_if($room !== null && $room->tenant_id !== $tenantId, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('rooms', 'name')->where('tenant_id', $tenantId)->ignore($room?->id)],
        ]);

        $room ??= new Room;
        $room->fill(['name' => trim($data['name'])]);
        $room->tenant_id = $tenantId;
        $room->save();
        $auditLogger->record($tenantId, $room->wasRecentlyCreated ? 'room.created' : 'room.updated', $room, $request->user()->id);

        return back()->with('toast', ['type' => 'success', 'message' => 'ოთახი შენახულია.']);
    }

    public function destroyRoom(Request $request, CurrentTenant $currentTenant, Room $room, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $this->authorizeTenant($request, $currentTenant);
        abort_unless($room->tenant_id === $tenantId, 404);

        if (Lesson::query()->where('room_id', $room->id)->exists()) {
            return back()->withErrors(['room' => 'ოთახი გაკვეთილებში გამოიყენება — წაშლა შეუძლებელია.']);
        }

        $auditLogger->record($tenantId, 'room.deleted', $room, $request->user()->id, ['name' => $room->name]);
        $room->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'ოთახი წაიშალა.']);
    }

    private function authorizeTenant(Request $request, CurrentTenant $currentTenant): int
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, self::ACCESS_ROLES), 403);

        return $tenantId;
    }
}
