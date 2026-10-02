<?php

namespace App\Domain\Assessment\Actions;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Assessment\Models\ReportCard;
use App\Domain\Assessment\Models\ReportCardSubject;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft report card for every active student of a class for a
 * closed period, with one subject line per teacher_assignment of that
 * class. Re-running is safe: existing cards are kept, and draft cards only
 * gain subject lines for newly added subjects plus a refreshed summary.
 * Requiring a closed period means the scores being summarised are final.
 */
class GenerateReportCards
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SummarizeSubjectResults $summarize,
    ) {}

    /**
     * @return array{created: int, refreshed: int}
     */
    public function handle(SchoolClass $schoolClass, AssessmentPeriod $period, User $actor): array
    {
        if ($schoolClass->tenant_id !== $period->tenant_id) {
            abort(404);
        }

        if (! $period->isClosed()) {
            throw ValidationException::withMessages([
                'assessment_period_id' => 'ტაბელი იქმნება მხოლოდ დახურული შეფასების პერიოდისთვის — ჯერ დახურეთ პერიოდი.',
            ]);
        }

        if ($period->academic_year_id !== $schoolClass->academic_year_id) {
            throw ValidationException::withMessages([
                'assessment_period_id' => 'პერიოდი ამ კლასის სასწავლო წელს არ ეკუთვნის.',
            ]);
        }

        $teacherAssignments = TeacherAssignment::query()
            ->where('tenant_id', $schoolClass->tenant_id)
            ->where('school_class_id', $schoolClass->id)
            ->with('teacher')
            ->get();

        if ($teacherAssignments->isEmpty()) {
            throw ValidationException::withMessages([
                'school_class_id' => 'ამ კლასს საგანი/მასწავლებელი არ აქვს მინიჭებული.',
            ]);
        }

        $students = Student::query()
            ->where('tenant_id', $schoolClass->tenant_id)
            ->where('school_class_id', $schoolClass->id)
            ->where('is_active', true)
            ->get();

        $created = 0;
        $refreshed = 0;

        DB::transaction(function () use ($schoolClass, $period, $actor, $teacherAssignments, $students, &$created, &$refreshed) {
            foreach ($students as $student) {
                $card = ReportCard::query()
                    ->where('tenant_id', $schoolClass->tenant_id)
                    ->where('student_id', $student->id)
                    ->where('assessment_period_id', $period->id)
                    ->lockForUpdate()
                    ->first();

                if ($card === null) {
                    $card = new ReportCard([
                        'student_id' => $student->id,
                        'school_class_id' => $schoolClass->id,
                        'assessment_period_id' => $period->id,
                        'status' => ReportCard::STATUS_DRAFT,
                        'revision' => 1,
                        'created_by' => $actor->id,
                    ]);
                    $card->tenant_id = $schoolClass->tenant_id;
                    $card->save();
                    $created++;
                } elseif ($card->status === ReportCard::STATUS_DRAFT) {
                    $refreshed++;
                } else {
                    continue;
                }

                foreach ($teacherAssignments as $teacherAssignment) {
                    $summary = $this->summarize->handle($schoolClass->tenant_id, $student->id, $teacherAssignment->id, $period->id);

                    $line = ReportCardSubject::query()
                        ->where('report_card_id', $card->id)
                        ->where('teacher_assignment_id', $teacherAssignment->id)
                        ->first();

                    if ($line === null) {
                        $line = new ReportCardSubject([
                            'report_card_id' => $card->id,
                            'teacher_assignment_id' => $teacherAssignment->id,
                            'subject_label' => $teacherAssignment->subject ?: 'საგანი მითითებული არ არის',
                        ]);
                        $line->tenant_id = $schoolClass->tenant_id;
                    }

                    $line->assessments_count = $summary['count'];
                    $line->average_percent = $summary['average_percent'];
                    $line->save();
                }
            }

            $this->auditLogger->record($schoolClass->tenant_id, 'report_cards.generated', $schoolClass, $actor->id, [
                'assessment_period_id' => $period->id,
                'created' => $created,
                'refreshed' => $refreshed,
            ]);
        });

        return ['created' => $created, 'refreshed' => $refreshed];
    }
}
