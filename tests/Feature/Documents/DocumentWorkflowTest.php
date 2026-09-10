<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Actions\ApprovalAlreadyDecidedException;
use App\Domain\Documents\Actions\CreateDraftDocument;
use App\Domain\Documents\Actions\DecideApproval;
use App\Domain\Documents\Actions\SubmitForReview;
use App\Domain\Documents\Actions\UploadDocumentVersion;
use App\Domain\Documents\Models\ApprovalDecision;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Covers docs/07-document-management-spec.md §11's required cases for the
 * Stage 1+2 slice: tenant isolation, single-reviewer approval, immutable
 * published versions, and upload validation.
 */
class DocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * @return array{tenant: Tenant, workspace: DocumentWorkspace, teacher: User, otherTeacher: User, director: User}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $workspace = $tenant->documentWorkspaces()->create([
            'title' => 'სასწავლო გეგმები',
            'classification' => DocumentWorkspace::CLASSIFICATION_CURRICULUM,
        ]);

        $teacher = User::factory()->create();
        $otherTeacher = User::factory()->create();
        $director = User::factory()->create();

        foreach ([$teacher, $otherTeacher] as $user) {
            TenantMembership::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true,
            ]);
        }

        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $director->id,
            'role' => TenantMembership::ROLE_DIRECTOR, 'is_active' => true,
        ]);

        return compact('tenant', 'workspace', 'teacher', 'otherTeacher', 'director');
    }

    private function pdf(string $name = 'plan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }

    public function test_teacher_can_create_a_draft_and_submit_it_for_review(): void
    {
        $f = $this->baseFixtures();

        $document = app(CreateDraftDocument::class)->handle(
            tenantId: $f['tenant']->id,
            workspace: $f['workspace'],
            owner: $f['teacher'],
            type: 'lesson_plan',
            title: 'VI კლასის გეგმა',
            file: $this->pdf(),
        );

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'status' => Document::STATUS_DRAFT]);

        $request = app(SubmitForReview::class)->handle($document, $f['teacher']);

        $this->assertSame('pending', $request->state);
        $document->refresh();
        $this->assertSame(Document::STATUS_IN_REVIEW, $document->status);
    }

    public function test_a_teacher_cannot_approve_their_own_submission(): void
    {
        $f = $this->baseFixtures();

        $document = app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $this->pdf(),
        );
        $request = app(SubmitForReview::class)->handle($document, $f['teacher']);

        $this->actingAs($f['teacher'])
            ->post(route('documents.approvals.decide', $request), ['decision' => 'approved'])
            ->assertForbidden();

        $this->assertDatabaseHas('approval_requests', ['id' => $request->id, 'state' => 'pending']);
    }

    public function test_director_approving_makes_the_version_the_published_version(): void
    {
        $f = $this->baseFixtures();

        $document = app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $this->pdf(),
        );
        $version = $document->latestVersion;
        $request = app(SubmitForReview::class)->handle($document, $f['teacher']);

        $this->actingAs($f['director'])
            ->post(route('documents.approvals.decide', $request), ['decision' => 'approved'])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(Document::STATUS_APPROVED, $document->status);
        $this->assertSame($version->id, $document->published_version_id);
    }

    public function test_returning_without_a_reason_is_rejected(): void
    {
        $f = $this->baseFixtures();

        $document = app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $this->pdf(),
        );
        $request = app(SubmitForReview::class)->handle($document, $f['teacher']);

        $this->actingAs($f['director'])
            ->post(route('documents.approvals.decide', $request), ['decision' => 'returned'])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('approval_requests', ['id' => $request->id, 'state' => 'pending']);
    }

    public function test_returning_with_a_reason_sends_the_document_back_to_changes_requested(): void
    {
        $f = $this->baseFixtures();

        $document = app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $this->pdf(),
        );
        $request = app(SubmitForReview::class)->handle($document, $f['teacher']);

        $this->actingAs($f['director'])
            ->post(route('documents.approvals.decide', $request), [
                'decision' => 'returned',
                'comment' => 'თარიღები დააზუსტეთ.',
            ])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(Document::STATUS_CHANGES_REQUESTED, $document->status);
        $this->assertDatabaseHas('approval_decisions', [
            'approval_request_id' => $request->id,
            'decision' => 'returned',
            'comment' => 'თარიღები დააზუსტეთ.',
        ]);
    }

    public function test_a_second_decision_on_an_already_decided_request_is_rejected(): void
    {
        $f = $this->baseFixtures();

        $document = app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $this->pdf(),
        );
        $request = app(SubmitForReview::class)->handle($document, $f['teacher']);

        app(DecideApproval::class)->handle($request, $f['director'], ApprovalDecision::DECISION_APPROVED, null);

        $this->expectException(ApprovalAlreadyDecidedException::class);
        app(DecideApproval::class)->handle($request, $f['director'], ApprovalDecision::DECISION_APPROVED, null);
    }

    /**
     * Proves the row-lock/state-recheck path actually prevents a double
     * apply. True multi-connection concurrency isn't exercisable against
     * this suite's in-memory SQLite (each :memory: connection is isolated),
     * so this asserts the operationally meaningful guarantee instead: once
     * a decision has landed, a second attempt on the same request — however
     * it arrives — can never also succeed. The exact same lockForUpdate +
     * state-recheck code path is what serializes genuinely concurrent
     * requests under MySQL/PostgreSQL in production.
     */
    public function test_only_one_of_two_decisions_on_the_same_request_can_ever_win(): void
    {
        $f = $this->baseFixtures();

        $document = app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $this->pdf(),
        );
        $request = app(SubmitForReview::class)->handle($document, $f['teacher']);

        $succeeded = 0;
        $conflicted = 0;

        foreach (['returned', 'approved'] as $decision) {
            try {
                app(DecideApproval::class)->handle($request, $f['director'], $decision, $decision === 'returned' ? 'მიზეზი' : null);
                $succeeded++;
            } catch (ApprovalAlreadyDecidedException) {
                $conflicted++;
            }
        }

        $this->assertSame(1, $succeeded);
        $this->assertSame(1, $conflicted);
    }

    public function test_a_newer_draft_never_leaks_to_a_reader_while_an_older_version_is_still_published(): void
    {
        $f = $this->baseFixtures();

        $document = app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $this->pdf('v1.pdf'),
        );
        $v1 = $document->latestVersion;
        $request = app(SubmitForReview::class)->handle($document, $f['teacher']);
        app(DecideApproval::class)->handle($request, $f['director'], ApprovalDecision::DECISION_APPROVED, null);

        $document->refresh();
        $this->assertSame($v1->id, $document->published_version_id);

        $v2 = app(UploadDocumentVersion::class)->handle($document, $f['teacher'], $this->pdf('v2.pdf'));

        $document->refresh();
        $this->assertSame($v1->id, $document->published_version_id, 'the published version must not change on new upload');
        $this->assertSame($v2->id, $document->latest_version_id);
        $this->assertSame(Document::STATUS_DRAFT, $document->status);

        // A staff reader (not owner/director) can only ever be handed the
        // published version's bytes through the app's own download flow.
        $reader = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $f['tenant']->id, 'user_id' => $reader->id,
            'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true,
        ]);

        $publishedUrl = URL::temporarySignedRoute('documents.versions.download', now()->addMinutes(5), [
            'document' => $document->id, 'version' => $v1->id,
        ]);
        $this->actingAs($reader)->get($publishedUrl)->assertOk();

        $draftUrl = URL::temporarySignedRoute('documents.versions.download', now()->addMinutes(5), [
            'document' => $document->id, 'version' => $v2->id,
        ]);
        $this->actingAs($reader)->get($draftUrl)->assertForbidden();

        // The owner and the director, though, can see the working draft.
        $this->actingAs($f['teacher'])->get($draftUrl)->assertOk();
        $this->actingAs($f['director'])->get($draftUrl)->assertOk();
    }

    public function test_disallowed_mime_type_is_rejected(): void
    {
        $f = $this->baseFixtures();

        $exe = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');

        $this->expectException(ValidationException::class);
        app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $exe,
        );
    }

    public function test_oversized_file_is_rejected(): void
    {
        $f = $this->baseFixtures();

        $huge = UploadedFile::fake()->create('big.pdf', 25 * 1024, 'application/pdf');

        $this->expectException(ValidationException::class);
        app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $huge,
        );
    }

    public function test_a_quarantined_version_cannot_be_downloaded_even_by_the_owner(): void
    {
        $f = $this->baseFixtures();

        $document = app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $this->pdf(),
        );
        $version = $document->latestVersion;
        $version->scan_state = DocumentVersion::SCAN_QUARANTINED;
        $version->save();

        $url = URL::temporarySignedRoute('documents.versions.download', now()->addMinutes(5), [
            'document' => $document->id, 'version' => $version->id,
        ]);

        $this->actingAs($f['teacher'])->get($url)->assertForbidden();
    }

    public function test_expired_download_link_is_rejected(): void
    {
        $f = $this->baseFixtures();

        $document = app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $this->pdf(),
        );
        $version = $document->latestVersion;

        $url = URL::temporarySignedRoute('documents.versions.download', now()->subMinutes(1), [
            'document' => $document->id, 'version' => $version->id,
        ]);

        $this->actingAs($f['teacher'])->get($url)->assertForbidden();
    }

    public function test_a_different_tenants_staff_cannot_see_find_or_download_this_document(): void
    {
        $f = $this->baseFixtures();

        $document = app(CreateDraftDocument::class)->handle(
            $f['tenant']->id, $f['workspace'], $f['teacher'], 'lesson_plan', 'გეგმა', $this->pdf(),
        );
        $version = $document->latestVersion;
        $request = app(SubmitForReview::class)->handle($document, $f['teacher']);
        app(DecideApproval::class)->handle($request, $f['director'], ApprovalDecision::DECISION_APPROVED, null);

        $otherTenant = Tenant::create([
            'slug' => 'other-school', 'name' => 'Other School', 'locale' => 'en', 'timezone' => 'UTC', 'is_active' => true,
        ]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school.test', 'is_primary' => true]);

        $otherStaff = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $otherTenant->id, 'user_id' => $otherStaff->id,
            'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true,
        ]);

        // Same-ID lookup on the OTHER tenant's host must not resolve tenant A's document.
        $this->actingAs($otherStaff)
            ->get('http://other-school.test/documents/'.$document->id, ['Host' => 'other-school.test'])
            ->assertNotFound();

        $url = URL::temporarySignedRoute('documents.versions.download', now()->addMinutes(5), [
            'document' => $document->id, 'version' => $version->id,
        ]);
        $this->actingAs($otherStaff)
            ->get(str_replace('http://localhost', 'http://other-school.test', $url), ['Host' => 'other-school.test'])
            ->assertNotFound();

        // Search from the other tenant's own document center never surfaces it either.
        $this->actingAs($otherStaff)
            ->get('http://other-school.test/documents?search=გეგმა', ['Host' => 'other-school.test'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('documents', []));
    }

    public function test_guardian_role_cannot_access_the_document_center(): void
    {
        $f = $this->baseFixtures();

        $guardian = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $f['tenant']->id, 'user_id' => $guardian->id,
            'role' => TenantMembership::ROLE_GUARDIAN, 'is_active' => true,
        ]);

        $this->actingAs($guardian)->get(route('documents.index'))->assertForbidden();
    }
}
