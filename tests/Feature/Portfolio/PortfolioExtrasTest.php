<?php

namespace Tests\Feature\Portfolio;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Portfolio\Models\Achievement;
use App\Domain\Portfolio\Models\PortfolioAsset;
use App\Domain\Portfolio\Models\PortfolioExport;
use App\Domain\Portfolio\Models\PortfolioItem;
use App\Domain\Portfolio\Models\PortfolioTag;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES §5 / CLAUDE.md #5: tags, archive, achievements
 * (shared only to families), recorded export, and pending/unscanned files
 * are never served unless explicitly allowed.
 */
class PortfolioExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_archive_achievements_export_and_scan_gate(): void
    {
        Storage::fake('local');
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);

        $u = [];
        foreach (['studentUser' => TenantMembership::ROLE_STUDENT, 'teacher' => TenantMembership::ROLE_TEACHER, 'otherTeacher' => TenantMembership::ROLE_TEACHER, 'guardian' => TenantMembership::ROLE_GUARDIAN] as $k => $role) {
            $u[$k] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u[$k]->id, 'role' => $role, 'is_active' => true]);
        }
        $student = $tenant->students()->create(['school_class_id' => $class->id, 'user_id' => $u['studentUser']->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);
        $tenant->teacherAssignments()->create(['user_id' => $u['teacher']->id, 'school_class_id' => $class->id, 'subject' => 'ხელოვნება']);
        $link = new GuardianLink(['user_id' => $u['guardian']->id, 'student_id' => $student->id, 'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $tenant->id;
        $link->save();

        $this->actingAs($u['studentUser'])->post("/portal/students/{$student->id}/portfolio", ['title' => 'ნახატი', 'file' => UploadedFile::fake()->create('w.pdf', 50, 'application/pdf')]);
        $item = PortfolioItem::query()->firstOrFail();
        $asset = PortfolioAsset::query()->firstOrFail();

        // The upload was queued for scanning; with no engine it is recorded as skipped, not clean.
        $this->assertSame('skipped', $asset->fresh()->scan_status);

        // Tags: owner and class teacher only; normalised and de-duplicated.
        $this->actingAs($u['otherTeacher'])->post("/portal/portfolio/{$item->id}/tags", ['tags' => ['x']])->assertForbidden();
        $this->actingAs($u['studentUser'])->post("/portal/portfolio/{$item->id}/tags", ['tags' => ['ხელოვნება', ' ხელოვნება ', 'Aquarelle']])->assertRedirect();
        $this->assertSame(['aquarelle', 'ხელოვნება'], PortfolioTag::query()->pluck('tag')->sort()->values()->all());

        // Archive only once published.
        $this->actingAs($u['studentUser'])->post("/portal/portfolio/{$item->id}/archive")->assertSessionHasErrors('status');
        $this->actingAs($u['studentUser'])->post("/portal/portfolio/{$item->id}/submit");
        $this->actingAs($u['teacher'])->post("/portal/portfolio/{$item->id}/decide", ['decision' => 'publish']);

        // Achievements: teacher of the class; families see shared ones only.
        $this->actingAs($u['otherTeacher'])->post("/portal/students/{$student->id}/achievements", ['title' => 'x', 'awarded_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($u['teacher'])->post("/portal/students/{$student->id}/achievements", ['title' => 'საუკეთესო ნახატი', 'awarded_on' => now()->toDateString(), 'shared_with_family' => true])->assertSessionHasNoErrors();
        $this->actingAs($u['teacher'])->post("/portal/students/{$student->id}/achievements", ['title' => 'შიდა შენიშვნა', 'awarded_on' => now()->toDateString(), 'shared_with_family' => false])->assertSessionHasNoErrors();
        $this->assertSame(2, Achievement::query()->count());

        $this->actingAs($u['guardian'])->get("/portal/students/{$student->id}/portfolio")
            ->assertInertia(fn (Assert $page) => $page->has('achievements', 1)->where('achievements.0.title', 'საუკეთესო ნახატი'));

        // Export: published items + shared achievements, recorded with checksum.
        $this->actingAs($u['guardian'])->get("/portal/students/{$student->id}/portfolio/export")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/portfolio/export')->has('items', 1)->has('achievements', 1));
        $export = PortfolioExport::query()->firstOrFail();
        $this->assertSame([$item->id], $export->item_ids);
        $this->assertSame(64, strlen($export->checksum));

        $this->actingAs($u['studentUser'])->post("/portal/portfolio/{$item->id}/archive")->assertRedirect();
        $this->assertSame('archived', $item->fresh()->status);

        // Scan gate on download: pending never; skipped only when allowed.
        $url = URL::signedRoute('portfolio.assets.download', [$item->id, $asset->id]);
        $asset->forceFill(['scan_status' => 'pending'])->save();
        $this->actingAs($u['studentUser'])->get($url)->assertStatus(423);
        $asset->forceFill(['scan_status' => 'skipped'])->save();
        $this->actingAs($u['studentUser'])->get($url)->assertOk();
        config(['uploads.allow_unscanned' => false]);
        $this->actingAs($u['studentUser'])->get($url)->assertStatus(423);
    }
}
