<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Governance\AuditLogger;
use App\Domain\Portfolio\Models\Achievement;
use App\Domain\Portfolio\Models\PortfolioExport;
use App\Domain\Portfolio\Models\PortfolioFeedback;
use App\Domain\Portfolio\Models\PortfolioItem;
use App\Domain\Portfolio\Models\PortfolioTag;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portfolio completion (CLAUDE-PLATFORM-MODULES §5): tags, archiving,
 * achievements and the printable export. Same audiences as the portfolio
 * itself: the student owner, the class's teachers, guardians with academic
 * access (published/shared content only).
 */
class PortfolioExtrasController extends Controller
{
    public function setTags(Request $request, CurrentTenant $currentTenant, PortfolioItem $portfolioItem): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($portfolioItem->tenant_id === $tenant->id, 404);
        $portfolioItem->loadMissing('student');
        $user = $request->user();
        abort_unless($this->isOwner($tenant->id, $portfolioItem->student, $user) || $this->isTeacher($tenant->id, $portfolioItem->student, $user), 403);

        $request->validate(['tags' => ['array', 'max:10'], 'tags.*' => ['string', 'max:40']]);

        /** @var array<int, string> $input */
        $input = $request->array('tags');
        $tags = collect($input)->map(fn (string $t) => mb_strtolower(trim($t)))->filter()->unique()->values();

        DB::transaction(function () use ($portfolioItem, $tags, $tenant) {
            PortfolioTag::query()->where('tenant_id', $tenant->id)->where('portfolio_item_id', $portfolioItem->id)->delete();
            foreach ($tags as $tag) {
                $row = new PortfolioTag(['portfolio_item_id' => $portfolioItem->id, 'tag' => $tag]);
                $row->tenant_id = $tenant->id;
                $row->save();
            }
        });

        return back();
    }

    public function archive(Request $request, CurrentTenant $currentTenant, PortfolioItem $portfolioItem, AuditLogger $auditLogger): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($portfolioItem->tenant_id === $tenant->id, 404);
        $portfolioItem->loadMissing('student');
        $user = $request->user();
        abort_unless($this->isOwner($tenant->id, $portfolioItem->student, $user) || $this->isTeacher($tenant->id, $portfolioItem->student, $user), 403);

        if ($portfolioItem->status !== PortfolioItem::STATUS_PUBLISHED) {
            throw ValidationException::withMessages(['status' => 'არქივში მხოლოდ გამოქვეყნებული ნამუშევარი გადადის.']);
        }

        $portfolioItem->status = PortfolioItem::STATUS_ARCHIVED;
        $portfolioItem->save();
        $auditLogger->record($tenant->id, 'portfolio.archived', $portfolioItem, $user->id);

        return back();
    }

    public function award(Request $request, CurrentTenant $currentTenant, Student $student, AuditLogger $auditLogger): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        $user = $request->user();
        $isManagement = TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, AssessmentSettingsController::ACCESS_ROLES);
        abort_unless($isManagement || $this->isTeacher($tenant->id, $student, $user), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'awarded_on' => ['required', 'date', 'before_or_equal:today'],
            'shared_with_family' => ['boolean'],
        ]);

        $achievement = new Achievement($data + ['student_id' => $student->id, 'awarded_by' => $user->id, 'shared_with_family' => $request->boolean('shared_with_family', true)]);
        $achievement->tenant_id = $tenant->id;
        $achievement->save();
        $auditLogger->record($tenant->id, 'achievement.awarded', $achievement, $user->id, ['student_id' => $student->id]);

        return back();
    }

    /**
     * Printable portfolio of published work and shared achievements. Each
     * export is recorded with the item ids and a checksum of the content.
     * Printed/saved as PDF by the browser (server PDF needs a PDF engine
     * with Georgian fonts — documented open item).
     */
    public function export(Request $request, CurrentTenant $currentTenant, Student $student): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        $user = $request->user();
        abort_unless($this->isOwner($tenant->id, $student, $user) || $this->isGuardian($tenant->id, $student, $user) || $this->isTeacher($tenant->id, $student, $user), 403);

        $items = PortfolioItem::query()->where('tenant_id', $tenant->id)->where('student_id', $student->id)
            ->where('status', PortfolioItem::STATUS_PUBLISHED)->with('subject')->orderBy('published_at')->get();
        $achievements = Achievement::query()->where('tenant_id', $tenant->id)->where('student_id', $student->id)->where('shared_with_family', true)->orderBy('awarded_on')->get();
        $tags = PortfolioTag::query()->where('tenant_id', $tenant->id)->whereIn('portfolio_item_id', $items->pluck('id'))->get()->groupBy('portfolio_item_id');
        $feedback = PortfolioFeedback::query()->where('tenant_id', $tenant->id)->whereIn('portfolio_item_id', $items->pluck('id'))->latest('id')->get()->groupBy('portfolio_item_id');

        $content = [
            'student' => $student->fullName(),
            'items' => $items->map(fn (PortfolioItem $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'subject' => $item->subject?->name,
                'publishedAt' => $item->published_at?->toIso8601String(),
                'tags' => $tags->get($item->id, collect())->pluck('tag')->values()->all(),
                'feedback' => $feedback->get($item->id, collect())->first()?->body,
            ])->values()->all(),
            'achievements' => $achievements->map(fn (Achievement $a) => ['title' => $a->title, 'description' => $a->description, 'awardedOn' => $a->awarded_on->toDateString()])->values()->all(),
        ];

        $export = new PortfolioExport([
            'student_id' => $student->id,
            'generated_by' => $user->id,
            'item_ids' => $items->pluck('id')->all(),
            'checksum' => hash('sha256', (string) json_encode($content, JSON_UNESCAPED_UNICODE)),
            'generated_at' => Carbon::now(),
        ]);
        $export->tenant_id = $tenant->id;
        $export->save();

        return Inertia::render('portal/portfolio/export', $content + [
            'studentId' => $student->id,
            'checksum' => $export->checksum,
            'generatedAt' => $export->generated_at->toIso8601String(),
        ]);
    }

    private function isOwner(int $tenantId, Student $student, User $user): bool
    {
        return $student->user_id === $user->id && $student->is_active && TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_STUDENT);
    }

    private function isGuardian(int $tenantId, Student $student, User $user): bool
    {
        return GuardianLink::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('student_id', $student->id)
            ->where('is_active', true)->where('can_view_academic', true)->exists();
    }

    private function isTeacher(int $tenantId, Student $student, User $user): bool
    {
        return $student->school_class_id !== null
            && TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_TEACHER)
            && TeacherAssignment::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('school_class_id', $student->school_class_id)->exists();
    }
}
