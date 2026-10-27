<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Learning\Models\CurriculumTopic;
use App\Domain\Learning\Models\LessonRecord;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Writes the class-register entry for one lesson occurrence: the topic
 * taught that day. An entry belongs to a real occurrence — a date on the
 * lesson's own weekday, not in the future.
 */
class SaveLessonRecord
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Lesson $lesson, User $actor, Carbon $occursOn, Carbon $today, ?string $topic, ?int $curriculumTopicId = null): LessonRecord
    {
        if ($curriculumTopicId !== null && ! $this->topicBelongsTo($lesson, $curriculumTopicId)) {
            throw ValidationException::withMessages(['curriculum_topic_id' => 'ეს თემა ამ კლასისა და საგნის გეგმაში არ არის.']);
        }

        if ($occursOn->greaterThan($today)) {
            throw ValidationException::withMessages(['occurs_on' => 'მომავალი გაკვეთილის თემა ჯერ ვერ ჩაიწერება.']);
        }

        if ($occursOn->isoWeekday() !== $lesson->day_of_week) {
            throw ValidationException::withMessages(['occurs_on' => 'ამ დღეს ეს გაკვეთილი არ ტარდება.']);
        }

        $record = LessonRecord::query()
            ->where('tenant_id', $lesson->tenant_id)
            ->where('lesson_id', $lesson->id)
            ->whereDate('occurs_on', $occursOn->toDateString())
            ->first() ?? new LessonRecord(['lesson_id' => $lesson->id, 'occurs_on' => $occursOn->toDateString()]);

        $created = ! $record->exists;
        $record->tenant_id = $lesson->tenant_id;
        $record->topic = $topic;
        $record->curriculum_topic_id = $curriculumTopicId;
        $record->recorded_by = $actor->id;

        if ($record->isDirty()) {
            $record->save();
            $this->auditLogger->record($lesson->tenant_id, $created ? 'lesson_record.created' : 'lesson_record.updated', $record, $actor->id, [
                'lesson_id' => $lesson->id,
                'occurs_on' => $occursOn->toDateString(),
                'curriculum_topic_id' => $curriculumTopicId,
            ]);
        }

        return $record;
    }

    /**
     * A plan topic can be named only by a lesson of that plan's class and
     * subject.
     */
    private function topicBelongsTo(Lesson $lesson, int $curriculumTopicId): bool
    {
        return CurriculumTopic::query()->where('tenant_id', $lesson->tenant_id)->whereKey($curriculumTopicId)
            ->whereHas('plan', fn ($query) => $query->where('school_class_id', $lesson->school_class_id)->where('subject_id', $lesson->subject_id))
            ->exists();
    }
}
