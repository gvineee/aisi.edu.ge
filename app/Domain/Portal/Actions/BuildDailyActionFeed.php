<?php

namespace App\Domain\Portal\Actions;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Assessment\Models\ReportCard;
use App\Domain\Assessment\Models\ReportCardPublication;
use App\Domain\Assessment\Models\ReportCardSubject;
use App\Domain\Communications\Models\ActionRequest;
use App\Domain\Communications\Models\AppointmentBooking;
use App\Domain\Communications\Models\ConversationParticipant;
use App\Domain\Documents\Actions\ListPendingApprovalRequests;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\DocumentAcknowledgement;
use App\Domain\Documents\Models\DocumentAcknowledgementRequest;
use App\Domain\Finance\Models\BillingAccount;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\AssignmentSubmission;
use App\Domain\Learning\Models\AttendanceRecord;
use App\Domain\PickupConsent\Models\ConsentForm;
use App\Domain\PickupConsent\Models\ConsentResponse;
use App\Domain\Platform\Models\SupportAccessRequest;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Domain\Timetable\Models\SubstitutionRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * CLAUDE-PLATFORM-MODULES.md §3's "დღის ცენტრი" read model. A thin set of
 * source adapters over the real modules — no duplicated table. Each item
 * has a stable key, type, title, due time, priority, context label and the
 * route that resolves it; it appears only for someone genuinely allowed to
 * act on it (re-derived on every request), and disappears once done.
 * Overdue/urgent come first; "today" uses the tenant's own timezone.
 */
class BuildDailyActionFeed
{
    private const PRIORITY_ORDER = ['urgent' => 0, 'high' => 1, 'normal' => 2];

    public function __construct(
        private readonly ListPendingApprovalRequests $listPendingApprovalRequests,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $tenantId, User $user, string $activeRole): array
    {
        $timezone = Tenant::query()->whereKey($tenantId)->value('timezone') ?: 'Asia/Tbilisi';
        $now = Carbon::now($timezone);

        $items = collect($this->unreadConversations($tenantId, $user))->merge($this->openActionRequests($tenantId, $user))
            ->merge($this->pendingAcknowledgements($tenantId, $user));

        $items = $items->merge(match ($activeRole) {
            TenantMembership::ROLE_GUARDIAN => $this->guardianItems($tenantId, $user, $now),
            TenantMembership::ROLE_TEACHER => $this->teacherItems($tenantId, $user, $now),
            TenantMembership::ROLE_STUDENT => $this->studentItems($tenantId, $user, $now),
            TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_ADMIN => $this->leadershipItems($tenantId, $activeRole),
            default => [],
        });

        return $items
            ->sortBy([
                fn (array $a, array $b) => self::PRIORITY_ORDER[$a['priority']] <=> self::PRIORITY_ORDER[$b['priority']],
                fn (array $a, array $b) => ($a['dueAt'] ?? '9999') <=> ($b['dueAt'] ?? '9999'),
            ])
            ->values()
            ->all();
    }

    /**
     * Published documents this staff member was asked to read and has not
     * confirmed yet (document centre §5 "გაცნობის დადასტურება").
     *
     * @return array<int, array<string, mixed>>
     */
    private function pendingAcknowledgements(int $tenantId, User $user): array
    {
        return DocumentAcknowledgement::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->whereNull('acknowledged_at')
            ->whereHas('request', fn ($query) => $query->where('status', DocumentAcknowledgementRequest::STATUS_OPEN))
            ->with('request.document')->get()
            ->map(fn (DocumentAcknowledgement $ack) => $this->item("document_ack:{$ack->id}", 'document_acknowledgement',
                'გაეცანით: '.$ack->request->document->title, 'დოკუმენტები', "/documents/{$ack->request->document_id}",
                $ack->request->due_on, $ack->request->due_on !== null && $ack->request->due_on->lt(Carbon::now()->startOfDay()) ? 'high' : 'normal'))
            ->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function guardianItems(int $tenantId, User $user, Carbon $now): array
    {
        $links = GuardianLink::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('is_active', true)
            ->with(['student' => fn ($query) => $query->where('is_active', true)])->get()
            ->filter(fn (GuardianLink $link) => $link->student !== null);

        if ($links->isEmpty()) {
            return [];
        }

        $items = [];
        $openForms = ConsentForm::query()->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereNull('responses_close_at')->orWhere('responses_close_at', '>', Carbon::now()))
            ->get();

        foreach ($links as $link) {
            $student = $link->student;
            $answered = ConsentResponse::query()->where('tenant_id', $tenantId)->where('student_id', $student->id)->whereNotNull('granted')->pluck('consent_form_id');

            foreach ($openForms->whereNotIn('id', $answered) as $form) {
                $soon = $form->responses_close_at !== null && $form->responses_close_at->lt(Carbon::now()->addHours(48));
                $items[] = $this->item("consent:{$form->id}:{$student->id}", 'consent_request', "თანხმობა სჭირდება: {$form->title}",
                    $student->fullName(), '/portal/consents', $form->responses_close_at, $soon ? 'high' : 'normal');
            }

            if ($link->can_view_financial && FeatureEntitlement::isEnabled($tenantId, FeatureEntitlement::FEATURE_FINANCE)) {
                $accountId = BillingAccount::query()->where('tenant_id', $tenantId)->where('student_id', $student->id)->value('id');

                if ($accountId !== null) {
                    foreach (Invoice::query()->where('tenant_id', $tenantId)->where('billing_account_id', $accountId)->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])->get() as $invoice) {
                        $overdue = $invoice->due_on->lt($now->copy()->startOfDay());
                        $items[] = $this->item("invoice:{$invoice->id}", 'invoice', ($overdue ? 'ვადაგადაცილებული ინვოისი' : 'გადასახდელი ინვოისი')." № {$invoice->number}",
                            $student->fullName(), "/portal/finance/invoices/{$invoice->id}", $invoice->due_on, $overdue ? 'urgent' : 'normal');
                    }
                }
            }

            if ($link->can_view_academic) {
                $recent = ReportCardPublication::query()->where('tenant_id', $tenantId)
                    ->whereIn('report_card_id', ReportCard::query()->where('tenant_id', $tenantId)->where('student_id', $student->id)->select('id'))
                    ->where('published_at', '>=', Carbon::now()->subDays(14))->latest('published_at')->first();

                if ($recent !== null) {
                    $items[] = $this->item("report_card:{$recent->id}", 'report_card', 'გამოქვეყნდა ტაბელი', $student->fullName(),
                        "/portal/report-card-documents/{$recent->id}", null, 'normal');
                }
            }
        }

        foreach (AppointmentBooking::query()->where('tenant_id', $tenantId)->where('guardian_id', $user->id)->where('status', AppointmentBooking::STATUS_BOOKED)
            ->whereHas('slot', fn ($query) => $query->where('starts_at', '>=', Carbon::now())->where('starts_at', '<=', Carbon::now()->addDays(7)))
            ->with('slot.teacher', 'student')->get() as $booking) {
            $items[] = $this->item("appointment:{$booking->id}", 'appointment', 'შეხვედრა: '.($booking->slot->teacher->name ?? ''),
                $booking->student->fullName(), '/portal/appointments', $booking->slot->starts_at, $booking->slot->starts_at->isSameDay($now) ? 'high' : 'normal');
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teacherItems(int $tenantId, User $user, Carbon $now): array
    {
        $items = [];
        $taIds = TeacherAssignment::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->pluck('id');

        // Today's lessons that still need attendance.
        $today = $now->toDateString();
        $lessons = Lesson::query()->where('tenant_id', $tenantId)->where('teacher_id', $user->id)->where('day_of_week', $now->dayOfWeekIso)->where('status', 'published')->with('subject', 'schoolClass')->get();

        foreach ($lessons as $lesson) {
            $marked = AttendanceRecord::query()->where('tenant_id', $tenantId)->where('lesson_id', $lesson->id)->whereDate('occurred_on', $today)->exists();

            if (! $marked) {
                $startsAt = Carbon::parse("{$today} {$lesson->starts_at}", $now->getTimezone());
                $items[] = $this->item("attendance:{$lesson->id}:{$today}", 'attendance', 'მოსანიშნია დასწრება: '.($lesson->subject->name ?? ''),
                    $lesson->schoolClass->name ?? null, "/lessons/{$lesson->id}/attendance", $startsAt, $startsAt->isPast() ? 'high' : 'normal');
            }
        }

        foreach (SubstitutionAssignment::query()->where('tenant_id', $tenantId)->where('substitute_teacher_id', $user->id)->where('status', 'assigned')
            ->whereDate('date', $today)->with('lesson.schoolClass')->get() as $sub) {
            $items[] = $this->item("substitution:{$sub->id}", 'substitution', 'დღეს ჩანაცვლება გაქვთ', $sub->lesson->schoolClass->name ?? null, '/dashboard', null, 'high');
        }

        // My own lessons someone covers this week that still have no handoff plan.
        foreach (SubstitutionAssignment::query()->where('tenant_id', $tenantId)->where('absent_teacher_id', $user->id)->where('status', 'assigned')
            ->whereDate('date', '>=', $today)->whereDate('date', '<=', $now->copy()->addDays(7)->toDateString())
            ->doesntHave('handoff')->with('lesson.schoolClass')->get() as $cover) {
            $items[] = $this->item("handoff:{$cover->id}", 'handoff', 'შემცვლელს გეგმა სჭირდება', ($cover->lesson->schoolClass->name ?? '').' · '.$cover->date->toDateString(),
                '/portal/my-coverage', $cover->date, $cover->date->toDateString() === $today ? 'urgent' : 'high');
        }

        $toGrade = AssignmentSubmission::query()->where('tenant_id', $tenantId)->where('status', AssignmentSubmission::STATUS_SUBMITTED)
            ->whereIn('assignment_id', Assignment::query()->where('tenant_id', $tenantId)->whereIn('teacher_assignment_id', $taIds)->select('id'))->count();

        if ($toGrade > 0) {
            $items[] = $this->item('assignments_to_grade', 'grading', 'შესაფასებელი ნამუშევრები', "{$toGrade} ნამუშევარი", '/portal/assignments', null, 'normal');
        }

        $lines = ReportCardSubject::query()->where('tenant_id', $tenantId)->whereIn('teacher_assignment_id', $taIds)->whereNull('completed_at')
            ->whereHas('reportCard', fn ($query) => $query->where('status', ReportCard::STATUS_DRAFT))->count();

        if ($lines > 0) {
            $items[] = $this->item('report_card_lines', 'report_card', 'შესავსებია ტაბელის საგნები', "{$lines} ხაზი", '/portal/report-cards', null, 'high');
        }

        $homeroomClassIds = SchoolClass::query()->where('tenant_id', $tenantId)->where('homeroom_teacher_id', $user->id)->pluck('id');
        $toReview = $homeroomClassIds->isEmpty() ? 0 : ReportCard::query()->where('tenant_id', $tenantId)->whereIn('school_class_id', $homeroomClassIds)->where('status', ReportCard::STATUS_TEACHER_COMPLETE)->count();

        if ($toReview > 0) {
            $items[] = $this->item('homeroom_review', 'report_card', 'დამრიგებლის განხილვას ელის ტაბელები', "{$toReview} ტაბელი", '/portal/report-cards', null, 'high');
        }

        foreach (AppointmentBooking::query()->where('tenant_id', $tenantId)->where('status', AppointmentBooking::STATUS_BOOKED)
            ->whereHas('slot', fn ($query) => $query->where('teacher_id', $user->id)->where('starts_at', '>=', $now->copy()->startOfDay()->utc())->where('starts_at', '<=', $now->copy()->endOfDay()->utc()))
            ->with('slot', 'student')->get() as $booking) {
            $items[] = $this->item("appointment:{$booking->id}", 'appointment', 'დღეს შეხვედრა მშობელთან', $booking->student->fullName(), '/portal/appointments', $booking->slot->starts_at, 'high');
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function studentItems(int $tenantId, User $user, Carbon $now): array
    {
        $student = Student::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('is_active', true)->first();

        if ($student === null || $student->school_class_id === null) {
            return [];
        }

        $submitted = AssignmentSubmission::query()->where('tenant_id', $tenantId)->where('student_id', $student->id)
            ->whereIn('status', [AssignmentSubmission::STATUS_SUBMITTED, AssignmentSubmission::STATUS_GRADED])->pluck('assignment_id');

        return Assignment::query()->where('tenant_id', $tenantId)->where('status', Assignment::STATUS_PUBLISHED)
            ->whereIn('teacher_assignment_id', TeacherAssignment::query()->where('tenant_id', $tenantId)->where('school_class_id', $student->school_class_id)->select('id'))
            ->whereNotIn('id', $submitted)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', Carbon::now()->addDays(3))
            ->get()
            ->map(fn (Assignment $assignment) => $this->item("assignment:{$assignment->id}", 'assignment', "დავალება: {$assignment->title}", null,
                '/portal/my-assignments', $assignment->due_at, $assignment->due_at !== null && $assignment->due_at->isPast() ? 'urgent' : 'high'))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function leadershipItems(int $tenantId, string $activeRole): array
    {
        $items = [];

        foreach ($this->listPendingApprovalRequests->handle($tenantId) as $approvalRequest) {
            $items[] = $this->formatApprovalItem($approvalRequest);
        }

        if ($activeRole === TenantMembership::ROLE_DIRECTOR) {
            $awaiting = ReportCard::query()->where('tenant_id', $tenantId)->where('status', ReportCard::STATUS_HOMEROOM_REVIEW)->count();

            if ($awaiting > 0) {
                $items[] = $this->item('report_cards_to_approve', 'report_card', 'დასამტკიცებელი ტაბელები', "{$awaiting} ტაბელი", '/portal/report-cards', null, 'high');
            }
        }

        $coverageRequests = SubstitutionRequest::query()->where('tenant_id', $tenantId)->where('status', SubstitutionRequest::STATUS_PENDING)->count();

        if ($coverageRequests > 0) {
            $items[] = $this->item('substitution_requests', 'substitution_request', 'ჩანაცვლების მოთხოვნები', "{$coverageRequests} მოთხოვნა", '/portal/substitutions', null, 'high');
        }

        foreach (SupportAccessRequest::query()->where('tenant_id', $tenantId)->where('status', 'requested')->get() as $request) {
            $items[] = $this->item("support_access:{$request->id}", 'support_access', 'პლატფორმის მხარდაჭერა ითხოვს წვდომას', null, '/portal/support-access', null, 'high');
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $key, string $type, string $title, ?string $context, string $href, ?CarbonInterface $dueAt, string $priority): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'title' => $title,
            'contextLabel' => $context,
            'href' => $href,
            'dueAt' => $dueAt?->toIso8601String(),
            'priority' => $priority,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatApprovalItem(ApprovalRequest $approvalRequest): array
    {
        $document = $approvalRequest->documentVersion->document;

        return $this->item("document_approval:{$approvalRequest->id}", 'document_approval', "დასამტკიცებელია: {$document->title}",
            $approvalRequest->documentVersion->author->name, "/documents/{$document->id}", null, 'normal');
    }

    /**
     * "Needs response" requests addressed to this user (§4 action_requests).
     *
     * @return array<int, array<string, mixed>>
     */
    private function openActionRequests(int $tenantId, User $user): array
    {
        return ActionRequest::query()->where('tenant_id', $tenantId)->where('target_user_id', $user->id)->where('status', 'open')
            ->with('conversation')->get()
            ->map(fn (ActionRequest $r) => $this->item("action_request:{$r->id}", 'action_request',
                ($r->type === 'reply' ? 'პასუხს ელოდებიან: ' : 'დადასტურება სჭირდება: ').($r->conversation->subject ?? ''),
                null, "/portal/messages/{$r->conversation_id}", $r->due_at,
                $r->due_at !== null && $r->due_at->isPast() ? 'urgent' : 'high'))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unreadConversations(int $tenantId, User $user): array
    {
        /** @var Collection<int, ConversationParticipant> $participants */
        $participants = ConversationParticipant::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->with(['conversation' => fn ($query) => $query->with('messages')])
            ->get()
            ->filter(fn (ConversationParticipant $participant) => $participant->conversation !== null);

        $items = [];

        foreach ($participants as $participant) {
            $conversation = $participant->conversation;
            $unreadCount = $participant->last_read_message_id === null
                ? $conversation->messages->count()
                : $conversation->messages->where('id', '>', $participant->last_read_message_id)->count();

            if ($unreadCount === 0) {
                continue;
            }

            $items[] = $this->item("unread_conversation:{$conversation->id}", 'unread_message', "წაუკითხავი შეტყობინება: {$conversation->subject}",
                $unreadCount > 1 ? "{$unreadCount} ახალი" : null, "/portal/messages/{$conversation->id}", null, 'normal');
        }

        return $items;
    }
}
