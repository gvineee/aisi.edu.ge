<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreLessonRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Schedule management — only an academic manager or admin may create or
 * change lessons (docs/02 permissions matrix: "განრიგი: ... სკოლის
 * მართვა" for the manager role, not the teacher who only sees their own).
 */
class LessonController extends Controller
{
    public function store(StoreLessonRequest $request, CurrentTenant $currentTenant): RedirectResponse
    {
        $tenant = $currentTenant->get();

        abort_unless(
            TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, [
                TenantMembership::ROLE_ACADEMIC_MANAGER,
                TenantMembership::ROLE_ADMIN,
            ]),
            403,
        );

        $data = $request->validated();
        $data['starts_at'] .= ':00';
        $data['ends_at'] .= ':00';
        $data['created_by'] = $request->user()->id;

        $lesson = new Lesson($data);
        $lesson->tenant_id = $tenant->id;
        $lesson->save();

        return back()->with('lessonId', $lesson->id);
    }
}
