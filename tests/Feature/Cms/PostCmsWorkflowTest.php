<?php

namespace Tests\Feature\Cms;

use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostRevision;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Post's equivalent of PageCmsWorkflowTest — same required cases (role
 * gate, publish transition, revision restore, tenant isolation) applied to
 * "აისის ამბები".
 */
class PostCmsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $editor = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $editor->id, 'role' => TenantMembership::ROLE_EDITOR, 'is_active' => true]);

        return [$tenant, $editor];
    }

    public function test_editor_can_create_a_draft_post_and_preview_it(): void
    {
        [$tenant, $editor] = $this->editor();

        $response = $this->actingAs($editor)->post('/portal/cms/posts', [
            'slug' => 'first-story',
            'locale' => 'ka',
            'title' => 'პირველი ამბავი',
            'excerpt' => '',
            'body' => 'სრული ტექსტი',
            'cover_image_path' => '',
            'seo_title' => '',
            'seo_description' => '',
        ]);

        $post = Post::query()->where('slug', 'first-story')->firstOrFail();
        $response->assertRedirect(route('cms.posts.edit', $post));
        $this->assertSame(Post::STATUS_DRAFT, $post->status);
        $this->assertSame($tenant->id, $post->tenant_id);
        $this->assertSame(1, $post->revisions()->count());

        $this->get('/news/first-story')->assertNotFound();

        $this->actingAs($editor)->get(route('cms.posts.preview', $post))
            ->assertOk()
            ->assertInertia(fn ($assert) => $assert
                ->where('post.title', 'პირველი ამბავი')
                ->where('preview', true));
    }

    public function test_non_staff_role_is_forbidden(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $guardian = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $guardian->id, 'role' => TenantMembership::ROLE_GUARDIAN, 'is_active' => true]);

        $this->actingAs($guardian)->get('/portal/cms/posts')->assertForbidden();
    }

    public function test_publish_and_unpublish_change_public_visibility(): void
    {
        [$tenant, $editor] = $this->editor();
        $admin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $post = $tenant->posts()->create([
            'slug' => 'second-story', 'locale' => 'ka', 'title' => 'მეორე ამბავი',
            'body' => 'ტექსტი', 'status' => Post::STATUS_DRAFT,
        ]);

        $this->actingAs($editor)->post(route('cms.posts.publish', $post))->assertForbidden();

        $this->actingAs($admin)->post(route('cms.posts.publish', $post))->assertRedirect();
        $this->assertSame(Post::STATUS_PUBLISHED, $post->fresh()->status);
        $this->get('/news/second-story')->assertOk();

        $this->actingAs($admin)->post(route('cms.posts.unpublish', $post))->assertRedirect();
        $this->assertSame(Post::STATUS_DRAFT, $post->fresh()->status);
        $this->get('/news/second-story')->assertNotFound();
    }

    public function test_restoring_a_revision_restores_prior_body(): void
    {
        [$tenant, $editor] = $this->editor();

        $post = $tenant->posts()->create([
            'slug' => 'third-story', 'locale' => 'ka', 'title' => 'მესამე — ვ1',
            'body' => 'ტექსტი ვ1', 'status' => Post::STATUS_DRAFT,
        ]);

        $v1 = new PostRevision([
            'post_id' => $post->id, 'title' => $post->title, 'body' => $post->body,
            'status' => $post->status, 'created_by' => $editor->id,
        ]);
        $v1->tenant_id = $tenant->id;
        $v1->save();

        $this->actingAs($editor)->put(route('cms.posts.update', $post), [
            'slug' => 'third-story', 'locale' => 'ka', 'title' => 'მესამე — ვ2',
            'body' => 'ტექსტი ვ2', 'cover_image_path' => '', 'seo_title' => '', 'seo_description' => '',
        ])->assertRedirect();

        $this->assertSame('მესამე — ვ2', $post->fresh()->title);

        $this->actingAs($editor)->post(route('cms.posts.revisions.restore', [$post, $v1]))->assertRedirect();

        $post->refresh();
        $this->assertSame('მესამე — ვ1', $post->title);
        $this->assertSame('ტექსტი ვ1', $post->body);
    }

    public function test_tenant_isolation_on_post_edit(): void
    {
        [$tenant, $editor] = $this->editor();

        $post = $tenant->posts()->create([
            'slug' => 'fourth-story', 'locale' => 'ka', 'title' => 'მეოთხე',
            'body' => 'ტექსტი', 'status' => Post::STATUS_DRAFT,
        ]);

        $otherTenant = Tenant::create(['slug' => 'other-school-2', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school-2.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($outsider)
            ->get("http://other-school-2.test/portal/cms/posts/{$post->id}/edit", ['Host' => 'other-school-2.test'])
            ->assertNotFound();
    }
}
