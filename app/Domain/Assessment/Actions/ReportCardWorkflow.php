<?php

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Models\ReportCard;
use App\Domain\Assessment\Models\ReportCardApproval;
use App\Domain\Assessment\Models\ReportCardComment;
use App\Domain\Assessment\Models\ReportCardPublication;
use App\Domain\Assessment\Models\ReportCardSubject;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every report card state change. Each step locks the card row and checks
 * the expected current status, so two reviewers clicking at once cannot
 * both win; each step is logged in report_card_approvals and the audit log.
 * Who may perform a step is decided by ReportCardController — this class
 * enforces the state machine only.
 */
class ReportCardWorkflow
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SummarizeSubjectResults $summarize,
    ) {}

    /**
     * Teacher fills in (and optionally completes) their own subject line.
     * The card moves to teacher_complete automatically once every line is
     * complete.
     */
    public function saveSubject(ReportCardSubject $line, User $actor, ?string $finalMark, ?string $comment, bool $complete): void
    {
        $finalMark = $finalMark !== null && trim($finalMark) !== '' ? trim($finalMark) : null;
        $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;

        if ($complete && $finalMark === null) {
            throw ValidationException::withMessages(['final_mark' => 'დასრულებისთვის საბოლოო შეფასება სავალდებულოა.']);
        }

        DB::transaction(function () use ($line, $actor, $finalMark, $comment, $complete) {
            $card = $this->lock($line->report_card_id, $line->tenant_id);
            $this->expect($card, [ReportCard::STATUS_DRAFT]);

            $line->refresh();
            $summary = $this->summarize->handle($card->tenant_id, $card->student_id, $line->teacher_assignment_id, $card->assessment_period_id);

            $line->final_mark = $finalMark;
            $line->teacher_comment = $comment;
            $line->assessments_count = $summary['count'];
            $line->average_percent = $summary['average_percent'];
            $line->completed_at = $complete ? Carbon::now() : null;
            $line->completed_by = $complete ? $actor->id : null;
            $line->save();

            $this->auditLogger->record($card->tenant_id, 'report_card_subject.saved', $line, $actor->id, [
                'report_card_id' => $card->id,
                'revision' => $card->revision,
                'completed' => $complete,
            ]);

            $allComplete = ! ReportCardSubject::query()
                ->where('report_card_id', $card->id)
                ->whereNull('completed_at')
                ->exists();

            if ($allComplete) {
                $this->move($card, ReportCard::STATUS_TEACHER_COMPLETE, $actor, null);
            }
        });
    }

    public function submitHomeroomReview(ReportCard $card, User $actor, string $comment): void
    {
        $comment = trim($comment);

        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => 'დამრიგებლის კომენტარი სავალდებულოა.']);
        }

        DB::transaction(function () use ($card, $actor, $comment) {
            $locked = $this->lock($card->id, $card->tenant_id);
            $this->expect($locked, [ReportCard::STATUS_TEACHER_COMPLETE]);
            $this->addComment($locked, ReportCardComment::KIND_HOMEROOM, $comment, $actor);
            $this->move($locked, ReportCard::STATUS_HOMEROOM_REVIEW, $actor, null);
        });

        $card->refresh();
    }

    public function approve(ReportCard $card, User $actor, ?string $comment): void
    {
        DB::transaction(function () use ($card, $actor, $comment) {
            $locked = $this->lock($card->id, $card->tenant_id);
            $this->expect($locked, [ReportCard::STATUS_HOMEROOM_REVIEW]);

            if ($comment !== null && trim($comment) !== '') {
                $this->addComment($locked, ReportCardComment::KIND_DIRECTOR, trim($comment), $actor);
            }

            $this->move($locked, ReportCard::STATUS_DIRECTOR_APPROVED, $actor, null);
        });

        $card->refresh();
    }

    /**
     * Freezes the approved card into a ReportCardPublication with a fresh
     * verification code — the only thing families and the public verify
     * page ever read.
     */
    public function publish(ReportCard $card, User $actor, string $schoolName): ReportCardPublication
    {
        $publication = DB::transaction(function () use ($card, $actor, $schoolName) {
            $locked = $this->lock($card->id, $card->tenant_id);
            $this->expect($locked, [ReportCard::STATUS_DIRECTOR_APPROVED]);
            $locked->load('student', 'schoolClass', 'period.academicYear', 'subjects', 'comments');

            $publishedAt = Carbon::now();
            $code = $this->uniqueCode();

            $publication = new ReportCardPublication([
                'report_card_id' => $locked->id,
                'revision' => $locked->revision,
                'verification_code' => $code,
                'snapshot' => [
                    'schoolName' => $schoolName,
                    'studentName' => $locked->student->fullName(),
                    'className' => $locked->schoolClass->name,
                    'academicYearName' => $locked->period->academicYear->name ?? null,
                    'periodName' => $locked->period->name,
                    'periodStartsOn' => $locked->period->starts_on->toDateString(),
                    'periodEndsOn' => $locked->period->ends_on->toDateString(),
                    'subjects' => $locked->subjects->map(fn (ReportCardSubject $line) => [
                        'subject' => $line->subject_label,
                        'finalMark' => $line->final_mark,
                        'teacherComment' => $line->teacher_comment,
                        'assessmentsCount' => $line->assessments_count,
                        'averagePercent' => $line->average_percent,
                    ])->values()->all(),
                    'homeroomComment' => $locked->latestComment(ReportCardComment::KIND_HOMEROOM)?->body,
                    'directorComment' => $locked->latestComment(ReportCardComment::KIND_DIRECTOR)?->body,
                    'revision' => $locked->revision,
                    'publishedAt' => $publishedAt->toIso8601String(),
                    'verificationCode' => $code,
                ],
                'published_by' => $actor->id,
                'published_at' => $publishedAt,
            ]);
            $publication->tenant_id = $locked->tenant_id;
            $publication->save();

            $this->move($locked, ReportCard::STATUS_PUBLISHED, $actor, null);

            return $publication;
        });

        $card->refresh();

        return $publication;
    }

    /**
     * Sends a card under review back. With subject lines selected, those
     * lines reopen and the card returns to draft for their teachers;
     * without, it returns to teacher_complete for the homeroom step.
     *
     * @param  array<int, int>  $subjectIds
     */
    public function returnForChanges(ReportCard $card, User $actor, string $reason, array $subjectIds): void
    {
        $this->requireReason($reason);

        DB::transaction(function () use ($card, $actor, $reason, $subjectIds) {
            $locked = $this->lock($card->id, $card->tenant_id);
            $this->expect($locked, [ReportCard::STATUS_TEACHER_COMPLETE, ReportCard::STATUS_HOMEROOM_REVIEW, ReportCard::STATUS_DIRECTOR_APPROVED]);

            $target = $this->reopenLines($locked, $subjectIds);

            if ($target === ReportCard::STATUS_TEACHER_COMPLETE && $locked->status === ReportCard::STATUS_TEACHER_COMPLETE) {
                throw ValidationException::withMessages(['subject_ids' => 'აირჩიეთ სულ მცირე ერთი საგანი, რომელიც მასწავლებელს უბრუნდება.']);
            }

            $this->move($locked, $target, $actor, trim($reason));
        });

        $card->refresh();
    }

    /**
     * Starts a correction of a published card: a new revision is opened,
     * while the previous publication stays exactly what families see until
     * the new revision is approved and published.
     *
     * @param  array<int, int>  $subjectIds
     */
    public function revise(ReportCard $card, User $actor, string $reason, array $subjectIds): void
    {
        $this->requireReason($reason);

        DB::transaction(function () use ($card, $actor, $reason, $subjectIds) {
            $locked = $this->lock($card->id, $card->tenant_id);
            $this->expect($locked, [ReportCard::STATUS_PUBLISHED]);

            $locked->revision = $locked->revision + 1;
            $locked->save();

            $target = $this->reopenLines($locked, $subjectIds);
            $this->move($locked, $target, $actor, trim($reason));
        });

        $card->refresh();
    }

    /**
     * @param  array<int, int>  $subjectIds
     */
    private function reopenLines(ReportCard $card, array $subjectIds): string
    {
        if ($subjectIds === []) {
            return ReportCard::STATUS_TEACHER_COMPLETE;
        }

        $reopened = ReportCardSubject::query()
            ->where('tenant_id', $card->tenant_id)
            ->where('report_card_id', $card->id)
            ->whereIn('id', $subjectIds)
            ->update(['completed_at' => null, 'completed_by' => null]);

        if ($reopened !== count(array_unique($subjectIds))) {
            throw ValidationException::withMessages(['subject_ids' => 'არჩეული საგანი ამ ტაბელს არ ეკუთვნის.']);
        }

        return ReportCard::STATUS_DRAFT;
    }

    private function lock(int $cardId, int $tenantId): ReportCard
    {
        return ReportCard::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($cardId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function expect(ReportCard $card, array $allowed): void
    {
        if (! in_array($card->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'ტაბელის სტატუსი ამასობაში შეიცვალა — განაახლეთ გვერდი.',
            ]);
        }
    }

    private function move(ReportCard $card, string $to, User $actor, ?string $note): void
    {
        $from = $card->status;
        $card->status = $to;
        $card->save();

        $approval = new ReportCardApproval([
            'report_card_id' => $card->id,
            'revision' => $card->revision,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'actor_id' => $actor->id,
        ]);
        $approval->tenant_id = $card->tenant_id;
        $approval->save();

        $this->auditLogger->record($card->tenant_id, 'report_card.transitioned', $card, $actor->id, [
            'from' => $from,
            'to' => $to,
            'revision' => $card->revision,
            'note' => $note,
        ]);
    }

    private function addComment(ReportCard $card, string $kind, string $body, User $actor): void
    {
        $comment = new ReportCardComment([
            'report_card_id' => $card->id,
            'revision' => $card->revision,
            'kind' => $kind,
            'body' => $body,
            'author_id' => $actor->id,
        ]);
        $comment->tenant_id = $card->tenant_id;
        $comment->save();
    }

    private function requireReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'მიზეზი სავალდებულოა (მინიმუმ 5 სიმბოლო).']);
        }
    }

    private function uniqueCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < 12; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (ReportCardPublication::query()->withoutGlobalScopes()->where('verification_code', $code)->exists());

        return $code;
    }
}
