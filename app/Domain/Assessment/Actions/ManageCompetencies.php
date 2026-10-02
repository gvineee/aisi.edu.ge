<?php

namespace App\Domain\Assessment\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Assessment\Models\Competency;
use App\Domain\Assessment\Models\CompetencyLevel;
use App\Domain\Assessment\Models\StudentCompetencyObservation;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageCompetencies
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<int, array{label: string, descriptor?: string|null}>  $levels  in ascending order
     */
    public function create(int $tenantId, User $actor, string $code, string $name, ?string $description, array $levels): Competency
    {
        if (count($levels) < 2) {
            throw ValidationException::withMessages(['levels' => 'საჭიროა სულ მცირე ორი დონე.']);
        }

        if (Competency::query()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'ეს კოდი უკვე გამოყენებულია.']);
        }

        return DB::transaction(function () use ($tenantId, $actor, $code, $name, $description, $levels) {
            $competency = new Competency(['code' => $code, 'name' => $name, 'description' => $description, 'is_active' => true, 'created_by' => $actor->id]);
            $competency->tenant_id = $tenantId;
            $competency->save();

            foreach (array_values($levels) as $index => $level) {
                $row = new CompetencyLevel(['competency_id' => $competency->id, 'level' => $index + 1, 'label' => trim($level['label']), 'descriptor' => $level['descriptor'] ?? null]);
                $row->tenant_id = $tenantId;
                $row->save();
            }

            $this->auditLogger->record($tenantId, 'competency.created', $competency, $actor->id, ['code' => $code, 'levels' => count($levels)]);

            return $competency;
        });
    }

    /**
     * Records an observation for a student of the teacher's own class.
     */
    public function observe(TeacherAssignment $teacherAssignment, Student $student, CompetencyLevel $level, User $teacher, Carbon $observedOn, ?string $note, bool $share): StudentCompetencyObservation
    {
        if ($student->tenant_id !== $teacherAssignment->tenant_id || $student->school_class_id !== $teacherAssignment->school_class_id || ! $student->is_active) {
            throw ValidationException::withMessages(['student_id' => 'მოსწავლე თქვენი კლასის აქტიურ სიაში არ არის.']);
        }

        $competency = Competency::query()->where('tenant_id', $teacherAssignment->tenant_id)->whereKey($level->competency_id)->where('is_active', true)->first();

        if ($competency === null || $level->tenant_id !== $teacherAssignment->tenant_id) {
            throw ValidationException::withMessages(['competency_level_id' => 'დონე ამ სკოლის აქტიურ კომპეტენციას არ ეკუთვნის.']);
        }

        if ($observedOn->isFuture()) {
            throw ValidationException::withMessages(['observed_on' => 'თარიღი მომავალში ვერ იქნება.']);
        }

        $observation = new StudentCompetencyObservation([
            'student_id' => $student->id,
            'competency_id' => $competency->id,
            'competency_level_id' => $level->id,
            'teacher_assignment_id' => $teacherAssignment->id,
            'observed_on' => $observedOn->toDateString(),
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'shared_with_family' => $share,
            'recorded_by' => $teacher->id,
        ]);
        $observation->tenant_id = $teacherAssignment->tenant_id;
        $observation->save();

        $this->auditLogger->record($observation->tenant_id, 'competency.observed', $observation, $teacher->id, [
            'student_id' => $student->id,
            'competency_id' => $competency->id,
            'level' => $level->level,
            'shared' => $share,
        ]);

        return $observation;
    }
}
