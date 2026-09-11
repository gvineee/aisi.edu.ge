<?php

namespace Tests\Feature\Cms;

use App\Domain\Content\Models\Media;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The media library screen. Real multipart uploads fail under `php artisan
 * serve` on this machine (environment-only UPLOAD_ERR_NO_TMP_DIR bug, not a
 * code bug — the same limitation already noted for the Portfolio domain),
 * so this covers the upload code path with UploadedFile::fake(), which
 * bypasses that OS-level mechanism entirely.
 */
class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function editor(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $editor = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $editor->id, 'role' => TenantMembership::ROLE_EDITOR, 'is_active' => true]);

        return [$tenant, $editor];
    }

    public function test_editor_can_upload_an_image_and_it_appears_in_the_library(): void
    {
        [$tenant, $editor] = $this->editor();

        $this->actingAs($editor)->post('/portal/cms/media', [
            'file' => UploadedFile::fake()->image('hero.png', 800, 600),
            'alt_text' => 'სკოლის ეზო',
        ])->assertRedirect(route('cms.media.index'));

        $media = Media::query()->firstOrFail();
        $this->assertSame($tenant->id, $media->tenant_id);
        $this->assertSame('სკოლის ეზო', $media->alt_text);
        Storage::disk('public')->assertExists($media->path);

        $this->actingAs($editor)->get('/portal/cms/media')
            ->assertOk()
            ->assertInertia(fn ($assert) => $assert->has('media', 1));
    }

    public function test_a_disallowed_file_type_is_rejected(): void
    {
        [, $editor] = $this->editor();

        $this->actingAs($editor)->post('/portal/cms/media', [
            'file' => UploadedFile::fake()->create('script.js', 10, 'application/javascript'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Media::query()->count());
    }

    public function test_non_staff_role_cannot_reach_the_media_library(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $student = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $student->id, 'role' => TenantMembership::ROLE_STUDENT, 'is_active' => true]);

        $this->actingAs($student)->get('/portal/cms/media')->assertForbidden();
    }

    public function test_tenant_isolation_on_media_listing(): void
    {
        [$tenant, $editor] = $this->editor();

        $this->actingAs($editor)->post('/portal/cms/media', [
            'file' => UploadedFile::fake()->image('a.png'),
        ]);

        $otherTenant = Tenant::create(['slug' => 'other-school-3', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school-3.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_EDITOR, 'is_active' => true]);

        $this->actingAs($outsider)
            ->get('http://other-school-3.test/portal/cms/media', ['Host' => 'other-school-3.test'])
            ->assertInertia(fn ($assert) => $assert->has('media', 0));
    }
}
