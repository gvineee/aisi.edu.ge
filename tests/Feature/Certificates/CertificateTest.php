<?php

namespace Tests\Feature\Certificates;

use App\Domain\Certificates\Models\CertificateTemplate;
use App\Domain\Certificates\Models\IssuedCertificate;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Certificates: a template fills in the pupil; numbers run per year; the
 * public check shows initials only and knows a revoked document.
 */
class CertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_to_a_pupil_and_a_class_verify_and_revoke(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 10:00', 'Asia/Tbilisi'));
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-03', 'ends_on' => '2027-07-01', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '9ა']);
        $ana = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ანა', 'last_name' => 'ბერიძე', 'is_active' => true]);
        $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'გიო', 'last_name' => 'ქართველიშვილი', 'is_active' => true]);

        [$manager, $teacher] = array_map(function (string $role) use ($tenant) {
            $user = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

            return $user;
        }, [TenantMembership::ROLE_ACADEMIC_MANAGER, TenantMembership::ROLE_TEACHER]);

        $this->actingAs($teacher)->get('/portal/certificates')->assertForbidden();
        $this->actingAs($manager)->post('/portal/certificates/templates', [
            'name' => 'სწავლის ცნობა', 'kind' => 'certificate', 'title' => 'ცნობა № {number}',
            'body' => 'ეძლევა {name}-ს, {class} კლასი, {year}. <b>{school}</b>', 'signatory' => 'დირექტორი', 'orientation' => 'portrait',
        ])->assertSessionHasNoErrors();
        $template = CertificateTemplate::query()->sole();

        $this->actingAs($manager)->post('/portal/certificates/issue', ['certificate_template_id' => $template->id, 'mode' => 'student', 'student_id' => $ana->id])->assertSessionHasNoErrors();
        $first = IssuedCertificate::query()->sole();
        $this->assertSame('2026/0001', $first->number());
        $this->assertSame('ცნობა № 2026/0001', $first->snapshot['title']);
        $this->assertSame("ეძლევა ანა ბერიძე-ს, 9ა კლასი, 2026-2027. <b>{$tenant->name}</b>", $first->snapshot['body']);

        $this->actingAs($manager)->post('/portal/certificates/issue', ['certificate_template_id' => $template->id, 'mode' => 'class', 'school_class_id' => $class->id])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/portal/certificates/issue', ['certificate_template_id' => $template->id, 'mode' => 'recipient', 'recipient' => 'სამინისტრო'])->assertSessionHasNoErrors();
        $this->assertSame([1, 2, 3, 4], IssuedCertificate::query()->orderBy('sequence')->pluck('sequence')->all());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'action' => 'certificate.issued']);

        // Another school's pupil is refused.
        $other = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $foreign = $other->students()->create(['first_name' => 'x', 'last_name' => 'y', 'is_active' => true]);
        $this->actingAs($manager)->post('/portal/certificates/issue', ['certificate_template_id' => $template->id, 'mode' => 'student', 'student_id' => $foreign->id])->assertSessionHasErrors('student_id');

        // PDF from the snapshot; the user-written body is escaped.
        $pdf = $this->actingAs($manager)->get("/portal/certificates/{$first->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        // Public check: initials only, no text; a revoked document says so.
        $this->get("/verify/document/{$first->verification_code}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/certificate-verify')->where('result.holderInitials', 'ა. ბ.')->where('result.number', '2026/0001')
            ->where('result.isRevoked', false)->missing('result.body'));
        $this->actingAs($manager)->post("/portal/certificates/{$first->id}/revoke", ['reason' => 'შეცდომა გვარში'])->assertSessionHasNoErrors();
        $this->get("/verify/document/{$first->verification_code}")->assertInertia(fn ($page) => $page->where('result.isRevoked', true));
        $this->get('/verify/document/NOSUCHCODE12')->assertInertia(fn ($page) => $page->where('result', null));

        $this->actingAs($manager)->get('/portal/certificates?q=2026/0003')->assertInertia(fn ($page) => $page->has('issued', 1)->where('issued.0.recipient', 'გიო ქართველიშვილი'));
    }
}
