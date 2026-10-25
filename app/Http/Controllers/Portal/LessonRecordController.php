<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Learning\Actions\SaveLessonRecord;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Class register: the lesson's own teacher — or, for the covered date
 * only, its substitute — records the topic taught.
 */
class LessonRecordController extends Controller
{
    public function store(Request $request, CurrentTenant $currentTenant, Lesson $lesson, SaveLessonRecord $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($lesson->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'occurs_on' => ['required', 'date_format:Y-m-d'],
            'topic' => ['nullable', 'string', 'max:2000'],
        ]);

        $occursOn = Carbon::parse($data['occurs_on'], $tenant->timezone)->startOfDay();
        $userId = $request->user()->id;
        $isSubstitute = SubstitutionAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('lesson_id', $lesson->id)
            ->where('substitute_teacher_id', $userId)
            ->where('status', SubstitutionAssignment::STATUS_ASSIGNED)
            ->whereDate('date', $occursOn->toDateString())
            ->exists();

        abort_unless($lesson->teacher_id === $userId || $isSubstitute, 403);

        $topic = trim((string) ($data['topic'] ?? ''));
        $action->handle($lesson, $request->user(), $occursOn, Carbon::now($tenant->timezone)->startOfDay(), $topic === '' ? null : $topic);

        return back()->with('toast', ['type' => 'success', 'message' => 'გაკვეთილის თემა შენახულია.']);
    }
}
