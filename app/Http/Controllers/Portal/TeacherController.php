<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Content\Actions\ChangeTeacherStatus;
use App\Domain\Content\Actions\SaveTeacher;
use App\Domain\Content\Models\Teacher;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\SaveTeacherRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin/director/editor-facing management for teacher profiles — a
 * dedicated block separate from the general CMS Page/Post screens, per
 * explicit request. Publishing here is what makes a teacher appear on
 * their public page and the homepage carousel.
 */
class TeacherController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ACCESS_ROLES = [
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request);

        $teachers = Teacher::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('portal/teachers/index', [
            'teachers' => $teachers->map(fn (Teacher $teacher) => $this->formatSummary($teacher))->values(),
        ]);
    }

    public function create(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request);

        return Inertia::render('portal/teachers/edit', ['teacher' => null]);
    }

    public function edit(Request $request, CurrentTenant $currentTenant, Teacher $teacher): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($teacher->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request);

        return Inertia::render('portal/teachers/edit', ['teacher' => $this->formatSummary($teacher)]);
    }

    public function store(SaveTeacherRequest $request, CurrentTenant $currentTenant, SaveTeacher $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);

        $teacher = $action->handle($tenant->id, null, $this->attributes($request), $actor, $request->file('photo'));

        return redirect()->route('teachers.edit', $teacher)->with('toast', [
            'type' => 'success', 'message' => 'მასწავლებელი დაემატა.',
        ]);
    }

    public function update(SaveTeacherRequest $request, CurrentTenant $currentTenant, Teacher $teacher, SaveTeacher $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        abort_unless($teacher->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $actor->id);

        $action->handle($tenant->id, $teacher, $this->attributes($request), $actor, $request->file('photo'));

        return redirect()->route('teachers.edit', $teacher)->with('toast', [
            'type' => 'success', 'message' => 'ცვლილება შენახულია.',
        ]);
    }

    public function publish(Request $request, CurrentTenant $currentTenant, Teacher $teacher, ChangeTeacherStatus $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        abort_unless($teacher->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $actor->id);

        $action->handle($teacher, true, $actor);

        return redirect()->route('teachers.index')->with('toast', ['type' => 'success', 'message' => 'გამოქვეყნდა.']);
    }

    public function unpublish(Request $request, CurrentTenant $currentTenant, Teacher $teacher, ChangeTeacherStatus $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        abort_unless($teacher->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $actor->id);

        $action->handle($teacher, false, $actor);

        return redirect()->route('teachers.index')->with('toast', ['type' => 'success', 'message' => 'მოხსნილია გამოქვეყნებიდან.']);
    }

    private function authorizeAccess(int $tenantId, int|Request $userIdOrRequest): void
    {
        $userId = $userIdOrRequest instanceof Request ? $userIdOrRequest->user()->id : $userIdOrRequest;
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::ACCESS_ROLES), 403);
    }

    /**
     * @return array{name: string, subject: string, bio: string|null, display_order: int}
     */
    private function attributes(SaveTeacherRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'subject' => $request->string('subject')->toString(),
            'bio' => $request->string('bio')->toString() ?: null,
            'display_order' => $request->integer('display_order'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSummary(Teacher $teacher): array
    {
        return [
            'id' => $teacher->id,
            'slug' => $teacher->slug,
            'name' => $teacher->name,
            'subject' => $teacher->subject,
            'bio' => $teacher->bio,
            'photoUrl' => $teacher->photoUrl(),
            'displayOrder' => $teacher->display_order,
            'status' => $teacher->status,
        ];
    }
}
