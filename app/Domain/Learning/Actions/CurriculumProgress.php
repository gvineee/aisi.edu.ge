<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Models\CurriculumPlan;
use App\Domain\Learning\Models\CurriculumTopic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How far each plan has got, from the class register: a topic is covered
 * once a journal entry of that class's lesson in that subject names it.
 * A topic whose planned end has passed while still uncovered is overdue.
 */
class CurriculumProgress
{
    /**
     * @param  Collection<int, CurriculumPlan>  $plans  with `topics` loaded
     * @return array<int, array{topics: int, covered: int, overdue: int, plannedHours: int, journalLessons: int, linkedLessons: int, byTopic: array<int, array{lessons: int, firstOn: string, lastOn: string}>}>
     */
    public function handle(int $tenantId, Collection $plans, Carbon $today): array
    {
        if ($plans->isEmpty()) {
            return [];
        }

        $rows = DB::table('lesson_records')
            ->join('lessons', 'lessons.id', '=', 'lesson_records.lesson_id')
            ->where('lesson_records.tenant_id', $tenantId)
            ->where('lessons.tenant_id', $tenantId)
            ->whereIn('lessons.school_class_id', $plans->pluck('school_class_id')->unique()->values()->all())
            ->whereIn('lessons.subject_id', $plans->pluck('subject_id')->unique()->values()->all())
            ->groupBy('lessons.school_class_id', 'lessons.subject_id', 'lesson_records.curriculum_topic_id')
            ->selectRaw('lessons.school_class_id as class_id, lessons.subject_id as subject_id, lesson_records.curriculum_topic_id as topic_id, count(*) as lessons, min(lesson_records.occurs_on) as first_on, max(lesson_records.occurs_on) as last_on')
            ->get();

        $result = [];

        foreach ($plans as $plan) {
            $ownRows = $rows->filter(fn (object $row) => (int) $row->class_id === $plan->school_class_id && (int) $row->subject_id === $plan->subject_id);
            $topicIds = $plan->topics->pluck('id')->all();
            $byTopic = [];

            foreach ($ownRows as $row) {
                if ($row->topic_id !== null && in_array((int) $row->topic_id, $topicIds, true)) {
                    $byTopic[(int) $row->topic_id] = [
                        'lessons' => (int) $row->lessons,
                        'firstOn' => substr((string) $row->first_on, 0, 10),
                        'lastOn' => substr((string) $row->last_on, 0, 10),
                    ];
                }
            }

            $result[$plan->id] = [
                'topics' => count($topicIds),
                'covered' => count($byTopic),
                'overdue' => $plan->topics->filter(fn (CurriculumTopic $topic) => ! isset($byTopic[$topic->id])
                    && $topic->planned_to !== null && $topic->planned_to->toDateString() < $today->toDateString())->count(),
                'plannedHours' => (int) $plan->topics->sum('planned_hours'),
                'journalLessons' => (int) $ownRows->sum('lessons'),
                'linkedLessons' => array_sum(array_column($byTopic, 'lessons')),
                'byTopic' => $byTopic,
            ];
        }

        return $result;
    }
}
