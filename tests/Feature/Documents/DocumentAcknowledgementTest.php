<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Actions\CreateDraftDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentAcknowledgement;
use App\Domain\Documents\Models\DocumentAcknowledgementRequest;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Portal\Actions\BuildDailyActionFeed;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Mail\DocumentAcknowledgementMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Document centre stage 3 (docs/07 §5, §11): acknowledgement of a specific
 * published version — recipients limited to people who can see the file,
 * explicit "გავეცანი" only, a new published version re-opens the request —
 * and the template library.
 */
class DocumentAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $director;

    private User $teacher;

    private User $otherTeacher;

    private User $accountant;

    private DocumentWorkspace $internal;

    private DocumentWorkspace $contracts;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->withoutVite();

        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $this->director = $this->member(TenantMembership::ROLE_DIRECTOR);
        $this->teacher = $this->member(TenantMembership::ROLE_TEACHER);
        $this->otherTeacher = $this->member(TenantMembership::ROLE_TEACHER);
        $this->accountant = $this->member(TenantMembership::ROLE_ACCOUNTANT);
        $this->internal = $this->workspace('შიდა', DocumentWorkspace::VISIBILITY_ALL_STAFF);
        $this->contracts = $this->workspace('ხელშეკრულებები', DocumentWorkspace::VISIBILITY_RESTRICTED);
    }

    private function member(string $role, ?Tenant $tenant = null): User
    {
        $user = User::factory()->create();
        TenantMembership::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

        return $user;
    }

    private function workspace(string $title, string $visibility): DocumentWorkspace
    {
        $workspace = new DocumentWorkspace([
            'title' => $title, 'classification' => DocumentWorkspace::CLASSIFICATION_INTERNAL, 'visibility' => $visibility,
            'requires_approval' => false, 'is_confidential' => false,
        ]);
        $workspace->tenant_id = $this->tenant->id;
        $workspace->save();

        return $workspace->refresh();
    }

    private function document(DocumentWorkspace $workspace, string $title): Document
    {
        return app(CreateDraftDocument::class)->handle(
            $this->tenant->id, $workspace, $this->director, 'policy', $title,
            UploadedFile::fake()->create('policy.pdf', 50, 'application/pdf'),
        )->refresh();
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function ask(Document $document, array $roles, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->director)->post("/documents/{$document->id}/acknowledgement-requests", ['roles' => $roles, 'note' => 'ახალი წესი']);
    }

    private function ackOf(User $user): DocumentAcknowledgement
    {
        return DocumentAcknowledgement::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    public function test_staff_in_the_chosen_roles_are_asked_and_confirm_the_exact_version(): void
    {
        $document = $this->document($this->internal, 'შინაგანაწესი');

        $this->ask($document, [TenantMembership::ROLE_TEACHER])->assertSessionHasNoErrors();

        $this->assertSame(2, DocumentAcknowledgement::query()->count());
        $this->assertFalse(DocumentAcknowledgement::query()->where('user_id', $this->accountant->id)->exists());
        Mail::assertQueued(DocumentAcknowledgementMail::class, 2);

        // Opening the page is not a confirmation.
        $this->actingAs($this->teacher)->get("/documents/{$document->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('myAcknowledgement.versionOrdinal', 1)
            ->where('myAcknowledgement.fileReady', true));
        $this->assertNull($this->ackOf($this->teacher)->acknowledged_at);

        $this->actingAs($this->teacher)->post("/document-acknowledgements/{$this->ackOf($this->teacher)->id}/acknowledge")->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post("/document-acknowledgements/{$this->ackOf($this->teacher)->id}/acknowledge")->assertSessionHasNoErrors();
        $this->assertNotNull($this->ackOf($this->teacher)->acknowledged_at);
        $this->assertSame(1, AuditEvent::query()->where('action', 'document.acknowledged')->count());

        // Someone else's receipt cannot be confirmed for them.
        $this->actingAs($this->teacher)->post("/document-acknowledgements/{$this->ackOf($this->otherTeacher)->id}/acknowledge")->assertForbidden();

        $this->actingAs($this->director)->get("/documents/{$document->id}")->assertInertia(fn (Assert $page) => $page
            ->where('acknowledgementRequest.versionOrdinal', 1)
            ->has('acknowledgementRequest.recipients', 2));

        // Still pending for the other teacher: day centre and "my work".
        $feed = app(BuildDailyActionFeed::class)->forUser($this->tenant->id, $this->otherTeacher, TenantMembership::ROLE_TEACHER);
        $this->assertContains('document_acknowledgement', array_column($feed, 'type'));
        $this->actingAs($this->otherTeacher)->get('/documents/my-work')->assertInertia(fn (Assert $page) => $page->where('toAcknowledge.0.title', 'შინაგანაწესი'));
        $feed = app(BuildDailyActionFeed::class)->forUser($this->tenant->id, $this->teacher, TenantMembership::ROLE_TEACHER);
        $this->assertNotContains('document_acknowledgement', array_column($feed, 'type'));
    }

    public function test_a_new_published_version_reopens_the_request_and_old_receipts_do_not_carry_over(): void
    {
        $document = $this->document($this->internal, 'შინაგანაწესი');
        $this->ask($document, [TenantMembership::ROLE_TEACHER])->assertSessionHasNoErrors();
        $oldAck = $this->ackOf($this->teacher);
        $this->actingAs($this->teacher)->post("/document-acknowledgements/{$oldAck->id}/acknowledge")->assertSessionHasNoErrors();
        $stale = $this->ackOf($this->otherTeacher);

        $this->actingAs($this->director)->post("/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->create('policy-v2.pdf', 60, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $requests = DocumentAcknowledgementRequest::query()->orderBy('id')->get();
        $this->assertSame([DocumentAcknowledgementRequest::STATUS_SUPERSEDED, DocumentAcknowledgementRequest::STATUS_OPEN], $requests->pluck('status')->all());
        $this->assertSame($document->fresh()->published_version_id, $requests[1]->document_version_id);
        $this->assertSame([TenantMembership::ROLE_TEACHER], $requests[1]->audience_roles);

        $newAck = $this->ackOf($this->teacher);
        $this->assertNotSame($oldAck->id, $newAck->id);
        $this->assertNull($newAck->acknowledged_at);

        // A receipt for the old version can no longer be confirmed.
        $this->actingAs($this->otherTeacher)->post("/document-acknowledgements/{$stale->id}/acknowledge")->assertSessionHasErrors('acknowledgement');
        $this->actingAs($this->teacher)->post("/document-acknowledgements/{$newAck->id}/acknowledge")->assertSessionHasNoErrors();
    }

    public function test_only_people_who_can_see_the_document_become_recipients(): void
    {
        $contract = $this->document($this->contracts, 'მომწოდებლის ხელშეკრულება');

        $this->ask($contract, [TenantMembership::ROLE_TEACHER])->assertSessionHasErrors('roles');
        $this->assertSame(0, DocumentAcknowledgementRequest::query()->count());

        $this->ask($contract, [TenantMembership::ROLE_TEACHER, TenantMembership::ROLE_ACCOUNTANT])->assertSessionHasErrors('roles');

        $this->actingAs($this->director)->post('/documents/access-grants', [
            'resource_type' => 'workspace', 'resource_id' => $this->contracts->id,
            'role' => TenantMembership::ROLE_ACCOUNTANT, 'permission' => 'view',
        ])->assertSessionHasNoErrors();
        $this->ask($contract, [TenantMembership::ROLE_TEACHER, TenantMembership::ROLE_ACCOUNTANT])->assertSessionHasNoErrors();
        $this->assertSame([$this->accountant->id], DocumentAcknowledgement::query()->pluck('user_id')->all());
    }

    public function test_only_managers_ask_and_other_tenants_are_out_of_reach(): void
    {
        $document = $this->document($this->internal, 'შინაგანაწესი');
        $this->ask($document, [TenantMembership::ROLE_TEACHER], $this->teacher)->assertForbidden();
        $this->ask($document, [TenantMembership::ROLE_TEACHER])->assertSessionHasNoErrors();
        $request = DocumentAcknowledgementRequest::query()->firstOrFail();
        $this->actingAs($this->teacher)->post("/document-acknowledgement-requests/{$request->id}/close")->assertForbidden();

        $other = Tenant::create(['slug' => 'other-docs-ack', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $other->id, 'domain' => 'other.test', 'is_primary' => true]);
        $otherDirector = $this->member(TenantMembership::ROLE_DIRECTOR, $other);
        $this->actingAs($otherDirector)->post("http://other.test/document-acknowledgement-requests/{$request->id}/close")->assertNotFound();
        $this->actingAs($otherDirector)->post("http://other.test/documents/{$document->id}/acknowledgement-requests", ['roles' => ['teacher']])->assertNotFound();
        $this->assertSame(DocumentAcknowledgementRequest::STATUS_OPEN, $request->fresh()->status);
    }

    public function test_a_closed_request_can_no_longer_be_confirmed(): void
    {
        $document = $this->document($this->internal, 'შინაგანაწესი');
        $this->ask($document, [TenantMembership::ROLE_TEACHER])->assertSessionHasNoErrors();
        $request = DocumentAcknowledgementRequest::query()->firstOrFail();

        $this->actingAs($this->director)->post("/document-acknowledgement-requests/{$request->id}/close")->assertRedirect();
        $this->assertSame(DocumentAcknowledgementRequest::STATUS_CLOSED, $request->fresh()->status);
        $this->actingAs($this->teacher)->post("/document-acknowledgements/{$this->ackOf($this->teacher)->id}/acknowledge")->assertSessionHasErrors('acknowledgement');
    }

    public function test_templates_start_new_documents_from_the_published_version(): void
    {
        $template = $this->document($this->internal, 'საგაკვეთილო გეგმის შაბლონი');

        $this->actingAs($this->teacher)->post("/documents/{$template->id}/template", ['is_template' => true])->assertForbidden();
        $this->actingAs($this->director)->post("/documents/{$template->id}/template", ['is_template' => true])->assertSessionHasNoErrors();
        $this->assertTrue($template->fresh()->is_template);

        $this->actingAs($this->teacher)->get('/documents/templates')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/documents/templates')
            ->where('templates.0.title', 'საგაკვეთილო გეგმის შაბლონი')
            ->where('workspaces.0.id', $this->internal->id));

        $this->actingAs($this->teacher)->post("/documents/templates/{$template->id}/use", [
            'workspace_id' => $this->internal->id, 'title' => 'ქართული III — სექტემბერი',
        ])->assertRedirect();

        $created = Document::query()->where('title', 'ქართული III — სექტემბერი')->firstOrFail();
        $source = DocumentVersion::query()->findOrFail($template->published_version_id);
        $copy = DocumentVersion::query()->findOrFail($created->latest_version_id);
        $this->assertSame($this->teacher->id, $created->owner_id);
        $this->assertSame($source->id, $created->template_version_id);
        $this->assertSame($source->checksum, $copy->checksum);
        $this->assertNotSame($source->storage_path, $copy->storage_path);
        $this->assertSame(1, DocumentVersion::query()->where('document_id', $template->id)->count());
        $this->assertTrue(AuditEvent::query()->where('action', 'document.created_from_template')->exists());

        // A template in a workspace the teacher cannot see is not usable.
        $hidden = $this->document($this->contracts, 'კონფიდენციალური შაბლონი');
        $this->actingAs($this->director)->post("/documents/{$hidden->id}/template", ['is_template' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post("/documents/templates/{$hidden->id}/use", [
            'workspace_id' => $this->internal->id, 'title' => 'ასლი',
        ])->assertNotFound();
        // And into a workspace they cannot add to.
        $this->actingAs($this->teacher)->post("/documents/templates/{$template->id}/use", [
            'workspace_id' => $this->contracts->id, 'title' => 'ასლი',
        ])->assertForbidden();
    }
}
