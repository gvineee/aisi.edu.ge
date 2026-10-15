<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Actions\CreateDraftDocument;
use App\Domain\Documents\Actions\ManageDocumentAccess;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentAccessGrant;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Internal staff files: workspaces with their own audience and approval
 * setting, categories/dates, and grants that are actually enforced on
 * list, search, detail, download, upload and grant management.
 */
class InternalDocumentsTest extends TestCase
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
        // The new settings page is not in the built manifest until the next build.
        $this->withoutVite();

        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $this->director = $this->member(TenantMembership::ROLE_DIRECTOR);
        $this->teacher = $this->member(TenantMembership::ROLE_TEACHER);
        $this->otherTeacher = $this->member(TenantMembership::ROLE_TEACHER);
        $this->accountant = $this->member(TenantMembership::ROLE_ACCOUNTANT);

        $this->internal = $this->workspace('შიდა დოკუმენტაცია', DocumentWorkspace::CLASSIFICATION_INTERNAL, DocumentWorkspace::VISIBILITY_ALL_STAFF);
        $this->contracts = $this->workspace('ხელშეკრულებები', DocumentWorkspace::CLASSIFICATION_CONTRACTS, DocumentWorkspace::VISIBILITY_RESTRICTED);
    }

    private function member(string $role, ?Tenant $tenant = null): User
    {
        $user = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true,
        ]);

        return $user;
    }

    private function workspace(string $title, string $classification, string $visibility): DocumentWorkspace
    {
        $workspace = new DocumentWorkspace([
            'title' => $title, 'classification' => $classification, 'visibility' => $visibility,
            'requires_approval' => false, 'is_confidential' => false,
        ]);
        $workspace->tenant_id = $this->tenant->id;
        $workspace->save();

        return $workspace->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function document(DocumentWorkspace $workspace, User $owner, string $title, array $metadata = []): Document
    {
        return app(CreateDraftDocument::class)->handle(
            $this->tenant->id, $workspace, $owner, 'file', $title,
            UploadedFile::fake()->create('file.pdf', 50, 'application/pdf'), null, $metadata,
        )->refresh();
    }

    private function downloadUrl(Document $document): string
    {
        return URL::temporarySignedRoute('documents.versions.download', now()->addMinutes(5), [
            'document' => $document->id, 'version' => $document->latest_version_id,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function listedTitles(User $user, string $query = ''): array
    {
        $titles = [];
        $this->actingAs($user)->get('/documents'.$query)->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$titles) {
                $titles = collect($page->toArray()['props']['documents'])->pluck('title')->all();
            });

        return $titles;
    }

    public function test_upload_to_a_workspace_without_approval_is_immediately_current_for_all_staff(): void
    {
        $this->actingAs($this->teacher)->post('/documents', [
            'workspace_id' => $this->internal->id,
            'title' => 'შიდა განაწესი',
            'document_date' => '2026-09-01',
            'tags' => 'წესი, შიდა',
            'file' => UploadedFile::fake()->create('rules.pdf', 50, 'application/pdf'),
        ])->assertRedirect();

        $document = Document::query()->where('title', 'შიდა განაწესი')->firstOrFail();
        $this->assertSame(Document::STATUS_ACTIVE, $document->status);
        $this->assertSame($document->latest_version_id, $document->published_version_id);
        $this->assertSame(['წესი', 'შიდა'], $document->tags);
        $this->assertSame('2026-09-01', $document->document_date?->toDateString());

        $this->assertContains('შიდა განაწესი', $this->listedTitles($this->otherTeacher));
        $this->actingAs($this->otherTeacher)->get($this->downloadUrl($document))->assertOk();
    }

    public function test_restricted_contracts_workspace_is_invisible_without_a_grant_everywhere(): void
    {
        $contract = $this->document($this->contracts, $this->director, 'შრომითი ხელშეკრულება');

        $this->assertNotContains('შრომითი ხელშეკრულება', $this->listedTitles($this->teacher));
        $this->assertSame([], $this->listedTitles($this->teacher, '?search=ხელშეკრულება'));
        $this->actingAs($this->teacher)->get("/documents/{$contract->id}")->assertForbidden();
        $this->actingAs($this->teacher)->get($this->downloadUrl($contract))->assertForbidden();

        $this->actingAs($this->teacher)->post('/documents', [
            'workspace_id' => $this->contracts->id,
            'title' => 'ჩემი',
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertForbidden();

        $this->assertContains('შრომითი ხელშეკრულება', $this->listedTitles($this->director));
    }

    public function test_a_role_grant_opens_the_contracts_workspace_to_the_accountants_and_downloads_are_audited(): void
    {
        $contract = $this->document($this->contracts, $this->director, 'მომწოდებლის ხელშეკრულება');

        $this->actingAs($this->director)->post('/documents/access-grants', [
            'resource_type' => 'workspace', 'resource_id' => $this->contracts->id,
            'role' => TenantMembership::ROLE_ACCOUNTANT, 'permission' => 'view',
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_events', ['action' => 'document_access.granted', 'actor_id' => $this->director->id]);
        $this->assertContains('მომწოდებლის ხელშეკრულება', $this->listedTitles($this->accountant));
        $this->actingAs($this->accountant)->get($this->downloadUrl($contract))->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'document.downloaded', 'actor_id' => $this->accountant->id]);

        // View is not edit: no new version, no metadata change, no grants.
        $this->actingAs($this->accountant)->post("/documents/{$contract->id}/versions", [
            'file' => UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
        $this->actingAs($this->accountant)->put("/documents/{$contract->id}", ['title' => 'შეცვლილი'])->assertForbidden();
        $this->actingAs($this->accountant)->post('/documents/access-grants', [
            'resource_type' => 'document', 'resource_id' => $contract->id,
            'user_id' => $this->teacher->id, 'permission' => 'view',
        ])->assertForbidden();

        // Teachers still see nothing.
        $this->assertNotContains('მომწოდებლის ხელშეკრულება', $this->listedTitles($this->teacher));

        // Revoking closes it again and is audited.
        $grant = DocumentAccessGrant::query()->where('role', TenantMembership::ROLE_ACCOUNTANT)->firstOrFail();
        $this->actingAs($this->director)->delete("/documents/access-grants/{$grant->id}")->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['action' => 'document_access.revoked']);
        $this->actingAs($this->accountant)->get("/documents/{$contract->id}")->assertForbidden();
    }

    public function test_a_document_level_grant_shares_one_file_only(): void
    {
        $shared = $this->document($this->contracts, $this->director, 'გაზიარებული');
        $this->document($this->contracts, $this->director, 'დაფარული');

        app(ManageDocumentAccess::class)->grant($this->tenant->id, 'document', $shared->id, $this->teacher->id, null, 'view', $this->director);

        $titles = $this->listedTitles($this->teacher);
        $this->assertContains('გაზიარებული', $titles);
        $this->assertNotContains('დაფარული', $titles);
        $this->actingAs($this->teacher)->get("/documents/{$shared->id}")->assertOk();
    }

    public function test_a_restricted_document_in_an_all_staff_workspace_is_hidden_from_other_staff(): void
    {
        $private = $this->document($this->internal, $this->teacher, 'პირადი ოქმი', ['visibility' => Document::VISIBILITY_RESTRICTED]);

        $this->assertNotContains('პირადი ოქმი', $this->listedTitles($this->otherTeacher));
        $this->actingAs($this->otherTeacher)->get("/documents/{$private->id}")->assertForbidden();
        $this->actingAs($this->teacher)->get("/documents/{$private->id}")->assertOk();
        $this->actingAs($this->director)->get("/documents/{$private->id}")->assertOk();
    }

    public function test_workspace_edit_grant_allows_new_versions_on_colleagues_files(): void
    {
        $document = $this->document($this->internal, $this->teacher, 'სახელმძღვანელო');

        $this->actingAs($this->otherTeacher)->post("/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf'),
        ])->assertForbidden();

        app(ManageDocumentAccess::class)->grant($this->tenant->id, 'workspace', $this->internal->id, $this->otherTeacher->id, null, 'edit', $this->director);

        $this->actingAs($this->otherTeacher)->post("/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $document->refresh();
        $this->assertSame(2, $document->latestVersion->ordinal);
        $this->assertSame($document->latest_version_id, $document->published_version_id, 'no approval step in this workspace');
    }

    public function test_only_director_or_admin_configure_workspaces_and_categories(): void
    {
        $payload = [
            'title' => 'საკადრო', 'classification' => 'staff_personal', 'visibility' => 'restricted',
            'requires_approval' => false, 'is_confidential' => true,
        ];

        $this->actingAs($this->teacher)->post('/documents/workspaces', $payload)->assertForbidden();
        $this->actingAs($this->teacher)->get('/documents/settings')->assertForbidden();
        $this->actingAs($this->teacher)->post('/documents/categories', ['name' => 'ბრძანება'])->assertForbidden();

        $this->actingAs($this->director)->post('/documents/workspaces', $payload)->assertRedirect();
        $this->actingAs($this->director)->post('/documents/categories', ['name' => 'ბრძანება'])->assertRedirect();
        $this->actingAs($this->director)->get('/documents/settings')->assertOk();

        $this->assertDatabaseHas('document_workspaces', ['title' => 'საკადრო', 'visibility' => 'restricted', 'is_confidential' => true]);
        $this->assertDatabaseHas('audit_events', ['action' => 'document_workspace.created']);
        $this->assertDatabaseHas('document_categories', ['name' => 'ბრძანება', 'tenant_id' => $this->tenant->id]);
    }

    public function test_grants_reject_non_staff_roles_and_users(): void
    {
        $guardian = $this->member(TenantMembership::ROLE_GUARDIAN);

        try {
            app(ManageDocumentAccess::class)->grant($this->tenant->id, 'workspace', $this->contracts->id, null, TenantMembership::ROLE_GUARDIAN, 'view', $this->director);
            $this->fail('guardian role grant must be rejected');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);
        app(ManageDocumentAccess::class)->grant($this->tenant->id, 'workspace', $this->contracts->id, $guardian->id, null, 'view', $this->director);
    }

    public function test_filters_by_category_and_date_and_sorts_by_document_date(): void
    {
        $category = new DocumentCategory(['name' => 'ოქმი']);
        $category->tenant_id = $this->tenant->id;
        $category->save();

        $this->document($this->internal, $this->teacher, 'ოქმი სექტემბერი', ['category_id' => $category->id, 'document_date' => '2026-09-10']);
        $this->document($this->internal, $this->teacher, 'ოქმი ოქტომბერი', ['category_id' => $category->id, 'document_date' => '2026-10-05']);
        $this->document($this->internal, $this->teacher, 'სხვა ფაილი', ['document_date' => '2026-10-01']);

        $this->assertSame(
            ['ოქმი ოქტომბერი', 'ოქმი სექტემბერი'],
            $this->listedTitles($this->otherTeacher, "?category_id={$category->id}&sort=document_date_desc"),
        );
        $this->assertSame(
            ['სხვა ფაილი', 'ოქმი ოქტომბერი'],
            $this->listedTitles($this->otherTeacher, '?date_from=2026-10-01&date_to=2026-10-31&sort=document_date_asc'),
        );
    }

    public function test_directors_see_contracts_expiring_within_thirty_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00', 'Asia/Tbilisi'));

        $this->document($this->contracts, $this->director, 'მალე იწურება', ['expires_on' => '2026-11-01']);
        $this->document($this->contracts, $this->director, 'შორს', ['expires_on' => '2027-06-01']);

        $this->actingAs($this->director)->get('/documents')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('expiringCount', 1));

        $this->assertSame(['მალე იწურება'], $this->listedTitles($this->director, '?expiring=1'));

        Carbon::setTestNow();
    }

    public function test_owner_edits_details_but_only_a_manager_changes_visibility(): void
    {
        $document = $this->document($this->internal, $this->teacher, 'ძველი სათაური');

        $this->actingAs($this->teacher)->put("/documents/{$document->id}", [
            'title' => 'ახალი სათაური', 'document_date' => '2026-10-01', 'expires_on' => '2027-10-01',
            'tags' => 'ა, ბ', 'visibility' => 'restricted',
        ])->assertRedirect();

        $document->refresh();
        $this->assertSame('ახალი სათაური', $document->title);
        $this->assertSame('2027-10-01', $document->expires_on?->toDateString());
        $this->assertSame(Document::VISIBILITY_WORKSPACE, $document->visibility);
        $this->assertDatabaseHas('audit_events', ['action' => 'document.details_updated', 'actor_id' => $this->teacher->id]);

        $this->actingAs($this->otherTeacher)->put("/documents/{$document->id}", ['title' => 'x'])->assertForbidden();

        $this->actingAs($this->director)->put("/documents/{$document->id}", [
            'title' => 'ახალი სათაური', 'visibility' => 'restricted',
        ])->assertRedirect();
        $this->assertSame(Document::VISIBILITY_RESTRICTED, $document->refresh()->visibility);
    }

    public function test_a_version_still_being_scanned_cannot_be_downloaded(): void
    {
        $document = $this->document($this->internal, $this->teacher, 'სკანირდება');
        DocumentVersion::query()->whereKey($document->latest_version_id)->update(['scan_state' => DocumentVersion::SCAN_PENDING]);

        $this->actingAs($this->teacher)->get($this->downloadUrl($document))->assertForbidden();
    }

    public function test_another_tenants_admin_cannot_touch_this_tenants_grants(): void
    {
        $grant = app(ManageDocumentAccess::class)->grant($this->tenant->id, 'workspace', $this->contracts->id, null, TenantMembership::ROLE_ACCOUNTANT, 'view', $this->director);

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'locale' => 'en', 'timezone' => 'UTC', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $other->id, 'domain' => 'other.test', 'is_primary' => true]);
        $otherAdmin = $this->member(TenantMembership::ROLE_ADMIN, $other);

        $this->actingAs($otherAdmin)
            ->delete("http://other.test/documents/access-grants/{$grant->id}")
            ->assertNotFound();
        $this->assertDatabaseHas('document_access_grants', ['id' => $grant->id]);
    }
}
