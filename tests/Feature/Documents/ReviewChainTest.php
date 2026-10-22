<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Actions\CreateDraftDocument;
use App\Domain\Documents\Models\ApprovalDecision;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\ApprovalStep;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentReviewDelegation;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Portal\Actions\BuildDailyActionFeed;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Mail\DocumentReviewReminderMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Document centre stage 4 (docs/07 §4): workspace review chains
 * (sequential / all-required), review due dates with reminders, and
 * time-limited reviewer delegation.
 */
class ReviewChainTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $director;

    private User $author;

    private User $deputy;

    private User $lawyer;

    private User $accountant;

    private DocumentWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->withoutVite();

        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $this->director = $this->member(TenantMembership::ROLE_DIRECTOR);
        $this->author = $this->member(TenantMembership::ROLE_TEACHER);
        $this->deputy = $this->member(TenantMembership::ROLE_ACADEMIC_MANAGER);
        $this->lawyer = $this->member(TenantMembership::ROLE_ACCOUNTANT);
        $this->accountant = $this->member(TenantMembership::ROLE_ACCOUNTANT);

        $workspace = new DocumentWorkspace([
            'title' => 'პოლიტიკები', 'classification' => DocumentWorkspace::CLASSIFICATION_INTERNAL,
            'visibility' => DocumentWorkspace::VISIBILITY_RESTRICTED, 'requires_approval' => true, 'is_confidential' => false,
        ]);
        $workspace->tenant_id = $this->tenant->id;
        $workspace->save();
        $this->workspace = $workspace->refresh();
    }

    private function member(string $role, ?Tenant $tenant = null): User
    {
        $user = User::factory()->create();
        TenantMembership::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

        return $user;
    }

    /**
     * @param  array<int, User>  $reviewers
     */
    private function chain(string $mode, array $reviewers, ?int $days = null): TestResponse
    {
        return $this->actingAs($this->director)->put("/documents/workspaces/{$this->workspace->id}/review-chain", [
            'review_mode' => $mode,
            'review_days' => $days,
            'reviewer_ids' => array_map(fn (User $user) => $user->id, $reviewers),
        ]);
    }

    /** A draft authored by $author, submitted for review by the director. */
    private function submitted(?User $author = null): Document
    {
        $document = app(CreateDraftDocument::class)->handle(
            $this->tenant->id, $this->workspace, $author ?? $this->author, 'policy', 'შინაგანაწესი',
            UploadedFile::fake()->create('policy.pdf', 50, 'application/pdf'),
        )->refresh();

        $this->actingAs($this->director)->post("/documents/{$document->id}/submit")->assertSessionHasNoErrors();

        return $document->refresh();
    }

    private function decide(User $user, string $decision = 'approved', string $comment = ''): TestResponse
    {
        $request = ApprovalRequest::query()->where('state', ApprovalRequest::STATE_PENDING)->latest('id')->firstOrFail();

        return $this->actingAs($user)->post("/approval-requests/{$request->id}/decide", ['decision' => $decision, 'comment' => $comment]);
    }

    /**
     * @return array<int, string>
     */
    private function stepStates(): array
    {
        return ApprovalStep::query()->orderBy('approval_request_id')->orderBy('position')->pluck('state')->all();
    }

    public function test_a_sequential_chain_is_decided_in_order_and_publishes_after_the_last_step(): void
    {
        $this->chain(DocumentWorkspace::REVIEW_SEQUENTIAL, [$this->deputy, $this->lawyer], 3)->assertSessionHasNoErrors();
        $document = $this->submitted();

        $request = ApprovalRequest::query()->firstOrFail();
        $this->assertNotNull($request->due_at);
        $this->assertTrue($request->due_at->between(Carbon::now()->addDays(3)->subMinute(), Carbon::now()->addDays(3)->addMinute()));
        $this->assertSame(['pending', 'pending'], $this->stepStates());

        // The second reviewer waits for the first; the director is not in the chain.
        $this->decide($this->lawyer)->assertForbidden();
        $this->decide($this->director)->assertForbidden();
        $this->actingAs($this->lawyer)->get("/documents/{$document->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canDecide', false)->has('versions', 1)->has('approvalHistory.0.steps', 2));

        // A reviewer without other access reads the version under review.
        $this->actingAs($this->deputy)->get("/documents/{$document->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canDecide', true)->has('versions', 1));
        $this->actingAs($this->accountant)->get("/documents/{$document->id}")->assertForbidden();

        $this->decide($this->deputy)->assertSessionHasNoErrors();
        $this->assertSame(Document::STATUS_IN_REVIEW, $document->fresh()->status);
        $this->assertNull($document->fresh()->published_version_id);
        $this->assertSame(['approved', 'pending'], $this->stepStates());

        $this->decide($this->lawyer)->assertSessionHasNoErrors();
        $this->assertSame(Document::STATUS_APPROVED, $document->fresh()->status);
        $this->assertSame($document->latest_version_id, $document->fresh()->published_version_id);
        $this->assertSame(ApprovalRequest::STATE_APPROVED, $request->fresh()->state);
        $this->assertSame(2, ApprovalDecision::query()->whereNotNull('approval_step_id')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'document.step_approved')->count());

        // Once decided, the chain reviewer's extra read access ends.
        $this->actingAs($this->lawyer)->get("/documents/{$document->id}")->assertForbidden();
    }

    public function test_an_all_required_chain_accepts_any_order_and_a_double_decision_is_refused(): void
    {
        $this->chain(DocumentWorkspace::REVIEW_ALL, [$this->deputy, $this->lawyer])->assertSessionHasNoErrors();
        $document = $this->submitted();

        $this->decide($this->lawyer)->assertSessionHasNoErrors();
        $this->decide($this->lawyer)->assertForbidden();
        $this->assertSame(Document::STATUS_IN_REVIEW, $document->fresh()->status);

        $this->decide($this->deputy)->assertSessionHasNoErrors();
        $this->assertSame(Document::STATUS_APPROVED, $document->fresh()->status);
        $request = ApprovalRequest::query()->firstOrFail();
        $this->actingAs($this->deputy)->post("/approval-requests/{$request->id}/decide", ['decision' => 'approved'])->assertSessionHasErrors('decision');
    }

    public function test_a_return_closes_the_request_and_cancels_the_remaining_steps(): void
    {
        $this->chain(DocumentWorkspace::REVIEW_ALL, [$this->deputy, $this->lawyer])->assertSessionHasNoErrors();
        $document = $this->submitted();

        $this->decide($this->deputy, 'returned')->assertSessionHasErrors('comment');
        $this->decide($this->deputy, 'returned', 'პუნქტი 3 გასასწორებელია')->assertSessionHasNoErrors();

        $this->assertSame(['returned', 'cancelled'], $this->stepStates());
        $this->assertSame(Document::STATUS_CHANGES_REQUESTED, $document->fresh()->status);
        $this->assertSame(ApprovalRequest::STATE_RETURNED, ApprovalRequest::query()->firstOrFail()->state);
    }

    public function test_the_authors_own_step_is_skipped_and_a_chain_without_an_independent_reviewer_cannot_be_submitted(): void
    {
        $this->chain(DocumentWorkspace::REVIEW_SEQUENTIAL, [$this->author, $this->deputy])->assertSessionHasNoErrors();
        $this->submitted();
        $this->assertSame(['skipped', 'pending'], $this->stepStates());
        $this->decide($this->author)->assertForbidden();
        $this->decide($this->deputy)->assertSessionHasNoErrors();
        $this->assertSame(ApprovalRequest::STATE_APPROVED, ApprovalRequest::query()->firstOrFail()->state);

        $this->chain(DocumentWorkspace::REVIEW_SEQUENTIAL, [$this->author])->assertSessionHasNoErrors();
        $document = app(CreateDraftDocument::class)->handle(
            $this->tenant->id, $this->workspace, $this->author, 'policy', 'მეორე',
            UploadedFile::fake()->create('second.pdf', 50, 'application/pdf'),
        );
        $this->actingAs($this->director)->post("/documents/{$document->id}/submit")->assertSessionHasErrors('document');
        $this->assertSame(1, ApprovalRequest::query()->count());

        // A chain must name reviewers, and only active staff of this school.
        $this->chain(DocumentWorkspace::REVIEW_ALL, [])->assertSessionHasErrors('reviewer_ids');
        $this->chain(DocumentWorkspace::REVIEW_ALL, [User::factory()->create()])->assertSessionHasErrors('reviewer_ids');
        $this->actingAs($this->deputy)->put("/documents/workspaces/{$this->workspace->id}/review-chain", ['review_mode' => 'single'])->assertForbidden();
    }

    public function test_a_delegate_decides_within_the_window_and_the_decision_names_both(): void
    {
        $this->chain(DocumentWorkspace::REVIEW_SEQUENTIAL, [$this->deputy, $this->lawyer])->assertSessionHasNoErrors();
        $this->submitted();

        // Staff delegate only for themselves.
        $this->actingAs($this->accountant)->post('/documents/delegations', [
            'delegator_id' => $this->deputy->id, 'delegate_id' => $this->accountant->id,
            'starts_at' => Carbon::now('Asia/Tbilisi')->subHour()->format('Y-m-d H:i'), 'ends_at' => Carbon::now('Asia/Tbilisi')->addDays(3)->format('Y-m-d H:i'), 'reason' => 'x',
        ])->assertForbidden();
        $this->actingAs($this->deputy)->post('/documents/delegations', [
            'delegate_id' => $this->accountant->id,
            'starts_at' => Carbon::now('Asia/Tbilisi')->format('Y-m-d H:i'), 'ends_at' => Carbon::now('Asia/Tbilisi')->addDays(120)->format('Y-m-d H:i'), 'reason' => 'შვებულება',
        ])->assertSessionHasErrors('ends_at');

        // A future window does not count yet.
        $this->actingAs($this->deputy)->post('/documents/delegations', [
            'delegate_id' => $this->accountant->id,
            'starts_at' => Carbon::now('Asia/Tbilisi')->addDays(10)->format('Y-m-d H:i'), 'ends_at' => Carbon::now('Asia/Tbilisi')->addDays(12)->format('Y-m-d H:i'), 'reason' => 'შვებულება',
        ])->assertSessionHasNoErrors();
        $this->decide($this->accountant)->assertForbidden();

        $this->actingAs($this->director)->post('/documents/delegations', [
            'delegator_id' => $this->deputy->id, 'delegate_id' => $this->accountant->id,
            'starts_at' => Carbon::now('Asia/Tbilisi')->subMinute()->format('Y-m-d H:i'), 'ends_at' => Carbon::now('Asia/Tbilisi')->addDays(2)->format('Y-m-d H:i'), 'reason' => 'ავადმყოფობა',
        ])->assertSessionHasNoErrors();

        $feed = app(BuildDailyActionFeed::class)->forUser($this->tenant->id, $this->accountant, TenantMembership::ROLE_ACCOUNTANT);
        $this->assertContains('document_approval', array_column($feed, 'type'));
        $this->actingAs($this->accountant)->get('/documents/director-worklist')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('requests', 1)->where('requests.0.canDecide', true));

        $this->decide($this->accountant)->assertSessionHasNoErrors();
        $decision = ApprovalDecision::query()->firstOrFail();
        $this->assertSame($this->accountant->id, $decision->reviewer_id);
        $this->assertSame($this->deputy->id, $decision->on_behalf_of_id);
        $this->assertSame(['approved', 'pending'], $this->stepStates());

        // Revoking ends a delegation at once.
        $this->actingAs($this->lawyer)->post('/documents/delegations', [
            'delegate_id' => $this->accountant->id,
            'starts_at' => Carbon::now('Asia/Tbilisi')->subMinute()->format('Y-m-d H:i'), 'ends_at' => Carbon::now('Asia/Tbilisi')->addDay()->format('Y-m-d H:i'), 'reason' => 'მივლინება',
        ])->assertSessionHasNoErrors();
        $delegation = DocumentReviewDelegation::query()->where('delegator_id', $this->lawyer->id)->firstOrFail();
        $this->actingAs($this->deputy)->post("/documents/delegations/{$delegation->id}/revoke")->assertForbidden();
        $this->actingAs($this->lawyer)->post("/documents/delegations/{$delegation->id}/revoke")->assertSessionHasNoErrors();
        $this->decide($this->accountant)->assertForbidden();
        $this->assertSame(1, AuditEvent::query()->where('action', 'document_review.delegation_revoked')->count());
    }

    public function test_a_new_version_supersedes_the_request_and_cancels_its_steps(): void
    {
        $this->chain(DocumentWorkspace::REVIEW_ALL, [$this->deputy, $this->lawyer])->assertSessionHasNoErrors();
        $document = $this->submitted();
        $this->decide($this->deputy)->assertSessionHasNoErrors();

        $this->actingAs($this->author)->post("/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->create('policy-v2.pdf', 60, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(ApprovalRequest::STATE_SUPERSEDED, ApprovalRequest::query()->firstOrFail()->state);
        $this->assertSame(['approved', 'cancelled'], $this->stepStates());
        $request = ApprovalRequest::query()->firstOrFail();
        $this->actingAs($this->lawyer)->post("/approval-requests/{$request->id}/decide", ['decision' => 'approved'])->assertSessionHasErrors('decision');
    }

    public function test_reminders_go_once_a_day_to_the_waiting_reviewer_and_their_delegate(): void
    {
        $this->chain(DocumentWorkspace::REVIEW_SEQUENTIAL, [$this->deputy, $this->lawyer], 1)->assertSessionHasNoErrors();
        $this->submitted();
        $this->actingAs($this->deputy)->post('/documents/delegations', [
            'delegate_id' => $this->accountant->id,
            'starts_at' => Carbon::now('Asia/Tbilisi')->subMinute()->format('Y-m-d H:i'), 'ends_at' => Carbon::now('Asia/Tbilisi')->addDays(5)->format('Y-m-d H:i'), 'reason' => 'შვებულება',
        ])->assertSessionHasNoErrors();

        $this->artisan('documents:send-review-reminders')->assertSuccessful();
        Mail::assertQueued(DocumentReviewReminderMail::class, 2);
        Mail::assertQueued(DocumentReviewReminderMail::class, fn (DocumentReviewReminderMail $mail) => $mail->hasTo($this->deputy->email) && $mail->onBehalfOf === null);
        Mail::assertQueued(DocumentReviewReminderMail::class, fn (DocumentReviewReminderMail $mail) => $mail->hasTo($this->accountant->email) && $mail->onBehalfOf === $this->deputy->name);
        Mail::assertNotQueued(DocumentReviewReminderMail::class, fn (DocumentReviewReminderMail $mail) => $mail->hasTo($this->lawyer->email));

        $this->artisan('documents:send-review-reminders')->assertSuccessful();
        Mail::assertQueued(DocumentReviewReminderMail::class, 2);

        $this->travel(25)->hours();
        $this->artisan('documents:send-review-reminders')->assertSuccessful();
        Mail::assertQueued(DocumentReviewReminderMail::class, 4);
        Mail::assertQueued(DocumentReviewReminderMail::class, fn (DocumentReviewReminderMail $mail) => $mail->overdue);
    }

    public function test_other_tenants_cannot_decide_configure_or_revoke(): void
    {
        $this->chain(DocumentWorkspace::REVIEW_SEQUENTIAL, [$this->deputy])->assertSessionHasNoErrors();
        $this->submitted();
        $this->actingAs($this->deputy)->post('/documents/delegations', [
            'delegate_id' => $this->accountant->id,
            'starts_at' => Carbon::now('Asia/Tbilisi')->format('Y-m-d H:i'), 'ends_at' => Carbon::now('Asia/Tbilisi')->addDay()->format('Y-m-d H:i'), 'reason' => 'შვებულება',
        ])->assertSessionHasNoErrors();
        $request = ApprovalRequest::query()->firstOrFail();
        $delegation = DocumentReviewDelegation::query()->firstOrFail();

        $other = Tenant::create(['slug' => 'other-review-chain', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $other->id, 'domain' => 'other.test', 'is_primary' => true]);
        $otherDirector = $this->member(TenantMembership::ROLE_DIRECTOR, $other);

        $this->actingAs($otherDirector)->post("http://other.test/approval-requests/{$request->id}/decide", ['decision' => 'approved'])->assertNotFound();
        $this->actingAs($otherDirector)->put("http://other.test/documents/workspaces/{$this->workspace->id}/review-chain", ['review_mode' => 'single'])->assertNotFound();
        $this->actingAs($otherDirector)->post("http://other.test/documents/delegations/{$delegation->id}/revoke")->assertNotFound();
        // Someone from another school cannot be named as reviewer or delegate.
        $this->actingAs($otherDirector)->put("http://other.test/documents/workspaces/{$this->workspace->id}/review-chain", ['review_mode' => 'all', 'reviewer_ids' => [$this->deputy->id]])->assertNotFound();

        $this->assertSame(ApprovalRequest::STATE_PENDING, $request->fresh()->state);
        $this->assertNull($delegation->fresh()->revoked_at);
        $this->assertSame(DocumentWorkspace::REVIEW_SEQUENTIAL, $this->workspace->fresh()->review_mode);
    }
}
