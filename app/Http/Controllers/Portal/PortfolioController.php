<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Portfolio\Actions\AddPortfolioAsset;
use App\Domain\Portfolio\Actions\CreatePortfolioItem;
use App\Domain\Portfolio\Actions\DecidePortfolioItem;
use App\Domain\Portfolio\Actions\SubmitPortfolioItem;
use App\Domain\Portfolio\Models\PortfolioAsset;
use App\Domain\Portfolio\Models\PortfolioFeedback;
use App\Domain\Portfolio\Models\PortfolioItem;
use App\Domain\Portfolio\PortfolioFileStorage;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\DecidePortfolioItemRequest;
use App\Http\Requests\Portal\StorePortfolioItemRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "პორტფოლიო" (CLAUDE-PLATFORM-MODULES.md §5). Default private: only the
 * owning student always sees an item; a teacher of the student's class
 * sees it once submitted; a guardian sees it only once published — never
 * an earlier draft, mirroring the Documents domain's published/draft
 * separation.
 */
class PortfolioController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant, Student $student): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);

        $user = $request->user();
        $isOwner = $this->isOwnerStudent($student, $user);
        $isGuardian = $this->isGuardianOf($tenant->id, $student, $user);
        $isTeacher = $this->isTeacherOfClass($tenant->id, $student, $user);

        abort_unless($isOwner || $isGuardian || $isTeacher, 403);

        $items = PortfolioItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('student_id', $student->id)
            ->when(! $isOwner && ! $isTeacher, fn ($query) => $query->where('status', PortfolioItem::STATUS_PUBLISHED))
            ->when(! $isOwner && $isTeacher, fn ($query) => $query->whereIn('status', [PortfolioItem::STATUS_SUBMITTED, PortfolioItem::STATUS_PUBLISHED]))
            ->with('subject')
            ->latest('updated_at')
            ->get();

        return Inertia::render('portal/portfolio/index', [
            'student' => ['id' => $student->id, 'name' => $student->fullName()],
            'items' => $items->map(fn (PortfolioItem $item) => $this->formatSummary($item))->values(),
            'canCreate' => $isOwner,
        ]);
    }

    public function show(Request $request, CurrentTenant $currentTenant, PortfolioItem $portfolioItem): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($portfolioItem->tenant_id === $tenant->id, 404);

        $portfolioItem->load(['student', 'subject', 'assets', 'feedback.author']);
        $user = $request->user();
        $isOwner = $this->isOwnerStudent($portfolioItem->student, $user);
        $isGuardian = $this->isGuardianOf($tenant->id, $portfolioItem->student, $user);
        $isTeacher = $this->isTeacherOfClass($tenant->id, $portfolioItem->student, $user);

        abort_unless($isOwner || $isGuardian || $isTeacher, 403);

        if ($isGuardian && ! $isOwner && ! $isTeacher) {
            abort_unless($portfolioItem->status === PortfolioItem::STATUS_PUBLISHED, 403);
        }

        if ($isTeacher && ! $isOwner) {
            abort_if($portfolioItem->status === PortfolioItem::STATUS_DRAFT, 403);
        }

        return Inertia::render('portal/portfolio/show', [
            'item' => $this->formatDetail($portfolioItem),
            'canEdit' => $isOwner && $portfolioItem->isEditableByStudent(),
            'canSubmit' => $isOwner && $portfolioItem->isEditableByStudent() && $portfolioItem->assets->isNotEmpty(),
            'canDecide' => $isTeacher && $portfolioItem->status === PortfolioItem::STATUS_SUBMITTED,
        ]);
    }

    public function store(StorePortfolioItemRequest $request, CurrentTenant $currentTenant, Student $student, CreatePortfolioItem $createPortfolioItem, AddPortfolioAsset $addPortfolioAsset): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        abort_unless($this->isOwnerStudent($student, $request->user()), 403);

        $item = $createPortfolioItem->handle(
            tenantId: $tenant->id,
            student: $student,
            creator: $request->user(),
            title: $request->string('title')->toString(),
            description: $request->string('description')->toString() ?: null,
            subjectId: $request->integer('subject_id') ?: null,
        );

        if ($request->hasFile('file')) {
            $addPortfolioAsset->handle($item, $request->file('file'));
        }

        return redirect()->route('portfolio.show', $item);
    }

    public function storeAsset(Request $request, CurrentTenant $currentTenant, PortfolioItem $portfolioItem, AddPortfolioAsset $addPortfolioAsset): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($portfolioItem->tenant_id === $tenant->id, 404);
        abort_unless($this->isOwnerStudent($portfolioItem->student, $request->user()), 403);

        $request->validate(['file' => ['required', 'file']]);

        $addPortfolioAsset->handle($portfolioItem, $request->file('file'));

        return redirect()->route('portfolio.show', $portfolioItem);
    }

    public function downloadAsset(CurrentTenant $currentTenant, Request $request, PortfolioItem $portfolioItem, PortfolioAsset $asset): StreamedResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($portfolioItem->tenant_id === $tenant->id, 404);
        abort_unless($asset->portfolio_item_id === $portfolioItem->id, 404);

        $user = $request->user();
        $isOwner = $this->isOwnerStudent($portfolioItem->student, $user);
        $isGuardian = $this->isGuardianOf($tenant->id, $portfolioItem->student, $user);
        $isTeacher = $this->isTeacherOfClass($tenant->id, $portfolioItem->student, $user);

        abort_unless($isOwner || $isGuardian || $isTeacher, 403);

        if (! $isOwner && ! $isTeacher) {
            abort_unless($portfolioItem->status === PortfolioItem::STATUS_PUBLISHED, 403);
        }

        if ($isTeacher && ! $isOwner) {
            abort_if($portfolioItem->status === PortfolioItem::STATUS_DRAFT, 403);
        }

        return Storage::disk('local')->download($asset->storage_path, $asset->original_filename);
    }

    public function submit(Request $request, CurrentTenant $currentTenant, PortfolioItem $portfolioItem, SubmitPortfolioItem $submitPortfolioItem): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($portfolioItem->tenant_id === $tenant->id, 404);
        abort_unless($this->isOwnerStudent($portfolioItem->student, $request->user()), 403);
        abort_if($portfolioItem->assets()->count() === 0, 422, 'პორტფოლიოს ჩანაწერს სულ მცირე ერთი ფაილი სჭირდება გაგზავნამდე.');

        $submitPortfolioItem->handle($portfolioItem);

        return redirect()->route('portfolio.show', $portfolioItem);
    }

    public function decide(DecidePortfolioItemRequest $request, CurrentTenant $currentTenant, PortfolioItem $portfolioItem, DecidePortfolioItem $decidePortfolioItem): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($portfolioItem->tenant_id === $tenant->id, 404);
        abort_unless($this->isTeacherOfClass($tenant->id, $portfolioItem->student, $request->user()), 403);

        $decidePortfolioItem->handle(
            item: $portfolioItem,
            reviewer: $request->user(),
            decision: $request->string('decision')->toString(),
            feedbackBody: $request->string('feedback')->toString() ?: null,
        );

        return redirect()->route('portfolio.show', $portfolioItem);
    }

    public function reviewQueue(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $classIds = TeacherAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->pluck('school_class_id');

        $items = PortfolioItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', PortfolioItem::STATUS_SUBMITTED)
            ->whereHas('student', fn ($query) => $query->whereIn('school_class_id', $classIds))
            ->with(['student', 'subject'])
            ->oldest('updated_at')
            ->get();

        return Inertia::render('portal/portfolio/review-queue', [
            'items' => $items->map(fn (PortfolioItem $item) => [
                ...$this->formatSummary($item),
                'studentName' => $item->student->fullName(),
            ])->values(),
        ]);
    }

    private function isOwnerStudent(Student $student, User $user): bool
    {
        return $student->user_id === $user->id && $student->is_active;
    }

    private function isGuardianOf(int $tenantId, Student $student, User $user): bool
    {
        return GuardianLink::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('student_id', $student->id)
            ->where('is_active', true)
            ->where('can_view_academic', true)
            ->exists();
    }

    private function isTeacherOfClass(int $tenantId, Student $student, User $user): bool
    {
        if ($student->school_class_id === null) {
            return false;
        }

        return TeacherAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('school_class_id', $student->school_class_id)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSummary(PortfolioItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'subjectName' => $item->subject?->name,
            'status' => $item->status,
            'updatedAt' => $item->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDetail(PortfolioItem $item): array
    {
        return [
            'id' => $item->id,
            'studentId' => $item->student_id,
            'studentName' => $item->student->fullName(),
            'title' => $item->title,
            'description' => $item->description,
            'subjectName' => $item->subject?->name,
            'status' => $item->status,
            'publishedAt' => $item->published_at?->toIso8601String(),
            'assets' => $item->assets->map(fn (PortfolioAsset $asset) => [
                'id' => $asset->id,
                'originalFilename' => $asset->original_filename,
                'mime' => $asset->mime,
                'size' => $asset->size,
                'downloadUrl' => app(PortfolioFileStorage::class)->signedDownloadUrl($item->id, $asset->id),
            ])->values(),
            'feedback' => $item->feedback->map(fn (PortfolioFeedback $feedback) => [
                'id' => $feedback->id,
                'authorName' => $feedback->author->name,
                'body' => $feedback->body,
                'createdAt' => $feedback->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
