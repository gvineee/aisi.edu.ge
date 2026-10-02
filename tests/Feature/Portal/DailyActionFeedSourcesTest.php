<?php

namespace Tests\Feature\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Finance\Models\BillingAccount;
use App\Domain\Finance\Models\Invoice;
use App\Domain\PickupConsent\Models\ConsentForm;
use App\Domain\Platform\Models\SupportAccessRequest;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES §3: the day centre aggregates real sources per
 * role, orders urgent/overdue first, shows an item only to someone allowed
 * to act on it, and drops it once it is done.
 */
class DailyActionFeedSourcesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    private function types(array $items): array
    {
        return array_column($items, 'type');
    }

    public function test_feed_sources_priority_and_completion(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00', 'Asia/Tbilisi')); // Monday
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        FeatureEntitlement::query()->updateOrCreate(['tenant_id' => $tenant->id, 'feature' => 'finance'], ['is_enabled' => true]);

        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $subject = $tenant->subjects()->create(['name' => 'მათემატიკა']);

        $u = [];
        foreach (['guardian' => TenantMembership::ROLE_GUARDIAN, 'stranger' => TenantMembership::ROLE_GUARDIAN, 'teacher' => TenantMembership::ROLE_TEACHER, 'director' => TenantMembership::ROLE_DIRECTOR] as $k => $role) {
            $u[$k] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u[$k]->id, 'role' => $role, 'is_active' => true]);
        }

        $child = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);
        $link = new GuardianLink(['user_id' => $u['guardian']->id, 'student_id' => $child->id, 'can_view_academic' => true, 'can_view_financial' => true, 'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $tenant->id;
        $link->save();

        // A consent request closing soon and an overdue invoice.
        $this->actingAs($u['director'])->post('/portal/consents', ['title' => 'ექსკურსია', 'body' => 'ტექსტი', 'responses_close_at' => '2026-10-06T09:00']);
        $form = ConsentForm::query()->firstOrFail();

        $account = new BillingAccount(['student_id' => $child->id, 'currency' => 'GEL']);
        $account->tenant_id = $tenant->id;
        $account->save();
        $invoice = new Invoice(['billing_account_id' => $account->id, 'fiscal_year' => 2026, 'number' => '2026-000001', 'status' => 'issued', 'issued_on' => '2026-09-01', 'due_on' => '2026-09-30', 'currency' => 'GEL', 'total_minor' => 10000, 'paid_minor' => 0, 'created_by' => $u['director']->id]);
        $invoice->tenant_id = $tenant->id;
        $invoice->save();

        $feed = fn (User $user) => $this->actingAs($user)->get('/portal/today')->assertOk()->viewData('page')['props']['actionItems'];

        $guardianFeed = $feed($u['guardian']);
        $this->assertSame('invoice', $guardianFeed[0]['type']);
        $this->assertSame('urgent', $guardianFeed[0]['priority']);
        $this->assertContains('consent_request', $this->types($guardianFeed));
        $this->assertSame('high', collect($guardianFeed)->firstWhere('type', 'consent_request')['priority']);

        // Nothing leaks to an unrelated guardian.
        $this->assertSame([], $this->types($feed($u['stranger'])));

        // Answering the consent removes it.
        $this->actingAs($u['guardian'])->post("/portal/consents/{$form->id}/respond", ['student_id' => $child->id, 'granted' => true]);
        $this->assertNotContains('consent_request', $this->types($feed($u['guardian'])));

        // Teacher: today's lesson needs attendance until it is marked.
        $tenant->teacherAssignments()->create(['user_id' => $u['teacher']->id, 'school_class_id' => $class->id, 'subject' => 'მათემატიკა']);
        $lesson = new Lesson(['academic_year_id' => $year->id, 'school_class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $u['teacher']->id, 'day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '09:45:00', 'status' => 'published']);
        $lesson->tenant_id = $tenant->id;
        $lesson->save();

        $teacherFeed = $feed($u['teacher']);
        $attendance = collect($teacherFeed)->firstWhere('type', 'attendance');
        $this->assertNotNull($attendance);
        $this->assertSame('high', $attendance['priority']); // lesson already started

        $this->actingAs($u['teacher'])->post("/lessons/{$lesson->id}/attendance", ['occurred_on' => '2026-10-05', 'records' => [['student_id' => $child->id, 'status' => 'present']]]);
        $this->assertNotContains('attendance', $this->types($feed($u['teacher'])));

        // Director: a pending support access request.
        $operator = User::factory()->create();
        SupportAccessRequest::query()->create(['tenant_id' => $tenant->id, 'operator_id' => $operator->id, 'reason' => 'დიაგნოსტიკა საჭიროა', 'requested_minutes' => 30, 'status' => 'requested']);
        $this->assertContains('support_access', $this->types($feed($u['director'])));
        $this->assertNotContains('support_access', $this->types($feed($u['teacher'])));

        $this->actingAs($u['guardian'])->get('/portal/today')->assertInertia(fn (Assert $page) => $page->component('portal/parent-dashboard'));
    }
}
