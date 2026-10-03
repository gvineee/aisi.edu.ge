<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\InvoiceItem;
use App\Domain\Operations\Models\Club;
use App\Domain\Operations\Models\ClubEnrollment;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES §11: a club fee is billed through a Finance
 * invoice line tied to the specific enrollment — once (until voided), never
 * twice on a double click, and only by finance staff with 2FA.
 */
class ClubFeeInvoicingTest extends TestCase
{
    use RefreshDatabase;

    public function test_club_enrollment_is_invoiced_once_and_listed_until_billed(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        FeatureEntitlement::query()->updateOrCreate(['tenant_id' => $tenant->id, 'feature' => 'finance'], ['is_enabled' => true]);
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $child = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);

        $accountant = User::factory()->create();
        $accountant->forceFill(['two_factor_confirmed_at' => now()])->save();
        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $accountant->id, 'role' => TenantMembership::ROLE_ACCOUNTANT, 'is_active' => true]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        $club = new Club(['name' => 'რობოტიკა', 'capacity' => 10, 'fee_minor' => 4550, 'currency' => 'GEL', 'is_open' => true]);
        $club->tenant_id = $tenant->id;
        $club->save();
        $enrollment = new ClubEnrollment(['club_id' => $club->id, 'student_id' => $child->id, 'status' => ClubEnrollment::STATUS_ENROLLED, 'requested_by' => $teacher->id]);
        $enrollment->tenant_id = $tenant->id;
        $enrollment->save();

        $this->actingAs($accountant)->get('/portal/finance')->assertInertia(fn (Assert $page) => $page
            ->has('staff.billableClubFees', 1)
            ->where('staff.billableClubFees.0.feeMinor', 4550));

        $due = now('Asia/Tbilisi')->addWeek()->toDateString();
        $this->actingAs($teacher)->post("/portal/finance/clubs/{$club->id}/invoice", ['student_id' => $child->id, 'due_on' => $due])->assertForbidden();
        $this->actingAs($accountant)->post("/portal/finance/clubs/{$club->id}/invoice", ['student_id' => $child->id, 'due_on' => $due])->assertRedirect();
        $this->actingAs($accountant)->post("/portal/finance/clubs/{$club->id}/invoice", ['student_id' => $child->id, 'due_on' => $due])->assertSessionHasErrors('student_id');

        $this->assertSame(1, Invoice::query()->count());
        $line = InvoiceItem::query()->firstOrFail();
        $this->assertSame('club_enrollment', $line->source_type);
        $this->assertSame($enrollment->id, $line->source_id);
        $this->assertSame(4550, $line->amount_minor);
        $this->actingAs($accountant)->get('/portal/finance')->assertInertia(fn (Assert $page) => $page->has('staff.billableClubFees', 0));

        // Voiding the invoice makes the enrollment billable again.
        $invoice = Invoice::query()->firstOrFail();
        $this->actingAs($accountant)->post("/portal/finance/invoices/{$invoice->id}/void", ['reason' => 'შეცდომით'])->assertSessionHasNoErrors();
        $this->actingAs($accountant)->get('/portal/finance')->assertInertia(fn (Assert $page) => $page->has('staff.billableClubFees', 1));
    }
}
