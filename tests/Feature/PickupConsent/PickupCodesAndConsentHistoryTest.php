<?php

namespace Tests\Feature\PickupConsent;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\PickupConsent\Actions\RemoveAuthorizedPickup;
use App\Domain\PickupConsent\Models\AuthorizedPickup;
use App\Domain\PickupConsent\Models\ConsentForm;
use App\Domain\PickupConsent\Models\ConsentResponse;
use App\Domain\PickupConsent\Models\ConsentResponseEvent;
use App\Domain\PickupConsent\Models\PickupCode;
use App\Domain\PickupConsent\Models\PickupEvent;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES §7/§17: pickup codes are random, hashed,
 * short-lived, single-use (atomic), rate-limited and tenant-scoped, with a
 * reason-required override; consent answers keep an append-only history
 * tied to the form text checksum, revocation is recorded, and the cutoff
 * is enforced on the server.
 */
class PickupCodesAndConsentHistoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function fixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);

        $u = [];
        foreach ([
            'guardian' => TenantMembership::ROLE_GUARDIAN,
            'noPickupGuardian' => TenantMembership::ROLE_GUARDIAN,
            'otherGuardian' => TenantMembership::ROLE_GUARDIAN,
            'teacher' => TenantMembership::ROLE_TEACHER,
            'director' => TenantMembership::ROLE_DIRECTOR,
        ] as $key => $role) {
            $u[$key] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u[$key]->id, 'role' => $role, 'is_active' => true]);
        }

        $student = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დოლიძე', 'is_active' => true]);
        $otherStudent = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ანა', 'last_name' => 'ბ.', 'is_active' => true]);

        $this->link($tenant, $u['guardian'], $student, true);
        $this->link($tenant, $u['noPickupGuardian'], $student, false);
        $this->link($tenant, $u['otherGuardian'], $otherStudent, true);

        $person = new AuthorizedPickup(['student_id' => $student->id, 'full_name' => 'ნინო ბებია', 'relationship' => 'ბებია', 'is_active' => true, 'added_by' => $u['guardian']->id]);
        $person->tenant_id = $tenant->id;
        $person->save();

        $otherPerson = new AuthorizedPickup(['student_id' => $otherStudent->id, 'full_name' => 'სხვისი ძიძა', 'relationship' => 'ძიძა', 'is_active' => true, 'added_by' => $u['otherGuardian']->id]);
        $otherPerson->tenant_id = $tenant->id;
        $otherPerson->save();

        return $u + compact('tenant', 'student', 'otherStudent', 'person', 'otherPerson');
    }

    private function link(Tenant $tenant, User $guardian, Student $student, bool $canPickup): void
    {
        $link = new GuardianLink([
            'user_id' => $guardian->id, 'student_id' => $student->id, 'can_view_academic' => true,
            'can_view_financial' => false, 'can_pickup' => $canPickup, 'can_receive_notifications' => true, 'is_active' => true,
        ]);
        $link->tenant_id = $tenant->id;
        $link->save();
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function issue(array $f, ?int $personId = null): string
    {
        $response = $this->actingAs($f['guardian'])->post('/portal/pickup/codes', [
            'student_id' => $f['student']->id,
            'authorized_pickup_id' => $personId,
        ]);
        $response->assertRedirect(route('pickup.index'));

        return session('issuedPickupCode')['code'];
    }

    public function test_code_is_random_hashed_and_consumed_exactly_once(): void
    {
        $f = $this->fixtures();
        $code = $this->issue($f, $f['person']->id);

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);
        $stored = PickupCode::query()->firstOrFail();
        $this->assertNotSame($code, $stored->code_hash);
        $this->assertSame(PickupCode::hash($code), $stored->code_hash);
        $this->assertStringNotContainsString((string) $f['student']->id, $code);

        $this->actingAs($f['teacher'])->post('/portal/pickup-desk/verify', ['code' => strtolower($code)])
            ->assertRedirect(route('pickup.verify-desk'))
            ->assertSessionHas('pickupResult', fn (array $result) => $result['studentName'] === 'ნიკა დ.' && $result['releasedTo'] === 'ნინო ბებია');

        $this->assertNotNull($stored->fresh()->consumed_at);
        $this->assertSame(1, PickupEvent::query()->where('method', 'code')->count());

        // Second use fails with the generic message.
        $this->actingAs($f['teacher'])->post('/portal/pickup-desk/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertSame(1, PickupEvent::query()->count());
    }

    public function test_expired_and_cancelled_codes_are_rejected(): void
    {
        $f = $this->fixtures();
        $expired = $this->issue($f);
        $this->travel(5)->hours();
        $this->actingAs($f['teacher'])->post('/portal/pickup-desk/verify', ['code' => $expired])->assertSessionHasErrors('code');

        $cancelled = $this->issue($f);
        $model = PickupCode::query()->where('code_hash', PickupCode::hash($cancelled))->firstOrFail();
        $this->actingAs($f['otherGuardian'])->post("/portal/pickup/codes/{$model->id}/cancel")->assertForbidden();
        $this->actingAs($f['guardian'])->post("/portal/pickup/codes/{$model->id}/cancel")->assertRedirect();
        $this->actingAs($f['teacher'])->post('/portal/pickup-desk/verify', ['code' => $cancelled])->assertSessionHasErrors('code');

        $this->assertSame(0, PickupEvent::query()->count());
    }

    public function test_codes_stop_working_when_the_authority_behind_them_is_revoked(): void
    {
        $f = $this->fixtures();

        // The named person is removed after the code was issued.
        $forPerson = $this->issue($f, $f['person']->id);
        app(RemoveAuthorizedPickup::class)->handle($f['person']->fresh(), $f['guardian']);
        $this->assertNotNull(PickupCode::query()->where('code_hash', PickupCode::hash($forPerson))->value('cancelled_at'));
        $this->actingAs($f['teacher'])->post('/portal/pickup-desk/verify', ['code' => $forPerson])->assertSessionHasErrors('code');

        // The issuing guardian loses the pickup right after issuing.
        $ownCode = $this->issue($f);
        GuardianLink::query()->where('user_id', $f['guardian']->id)->update(['can_pickup' => false]);
        $this->actingAs($f['teacher'])->post('/portal/pickup-desk/verify', ['code' => $ownCode])->assertSessionHasErrors('code');
        $this->assertNotNull(PickupCode::query()->where('code_hash', PickupCode::hash($ownCode))->value('cancelled_at'));
        $this->assertTrue(AuditEvent::query()->where('action', 'pickup_code.rejected_revoked')->exists());

        $this->assertSame(0, PickupEvent::query()->count());
    }

    public function test_only_guardians_with_pickup_right_issue_codes_for_their_own_child(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['noPickupGuardian'])->post('/portal/pickup/codes', ['student_id' => $f['student']->id])->assertForbidden();
        $this->actingAs($f['otherGuardian'])->post('/portal/pickup/codes', ['student_id' => $f['student']->id])->assertForbidden();
        $this->actingAs($f['guardian'])->post('/portal/pickup/codes', [
            'student_id' => $f['student']->id, 'authorized_pickup_id' => $f['otherPerson']->id,
        ])->assertSessionHasErrors('authorized_pickup_id');

        $this->assertSame(0, PickupCode::query()->count());
    }

    public function test_verification_and_override_are_staff_only_and_override_needs_reason(): void
    {
        $f = $this->fixtures();
        $code = $this->issue($f);

        $this->actingAs($f['guardian'])->post('/portal/pickup-desk/verify', ['code' => $code])->assertForbidden();
        $this->actingAs($f['guardian'])->get('/portal/pickup-desk')->assertForbidden();

        $this->actingAs($f['teacher'])->post('/portal/pickup-desk/override', [
            'student_id' => $f['student']->id, 'released_to' => 'მამიდა', 'reason' => 'მშობელმა დარეკა და დაადასტურა',
        ])->assertForbidden();

        $this->actingAs($f['director'])->post('/portal/pickup-desk/override', [
            'student_id' => $f['student']->id, 'released_to' => 'მამიდა', 'reason' => 'მოკლე',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($f['director'])->post('/portal/pickup-desk/override', [
            'student_id' => $f['student']->id, 'released_to' => 'მამიდა', 'reason' => 'მშობელმა დარეკა და დაადასტურა',
        ])->assertRedirect();

        $this->assertSame(1, PickupEvent::query()->where('method', 'override')->count());
        $this->assertTrue(AuditEvent::query()->where('action', 'pickup.override_release')->exists());
    }

    public function test_verification_is_rate_limited(): void
    {
        $f = $this->fixtures();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($f['teacher'])->post('/portal/pickup-desk/verify', ['code' => 'WRONG'.$i]);
        }

        $this->actingAs($f['teacher'])->post('/portal/pickup-desk/verify', ['code' => 'WRONGX'])->assertStatus(429);
    }

    public function test_code_does_not_work_in_another_tenant(): void
    {
        $f = $this->fixtures();
        $code = $this->issue($f);

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        $this->actingAs($outsider)
            ->post('http://other-school.test/portal/pickup-desk/verify', ['code' => $code], ['Host' => 'other-school.test'])
            ->assertSessionHasErrors('code');

        $this->assertNull(PickupCode::query()->firstOrFail()->consumed_at);
    }

    public function test_consent_history_is_append_only_with_checksum_and_revocation(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['director'])->post('/portal/consents', ['title' => 'ექსკურსია', 'body' => 'ნებას ვრთავ...'])->assertRedirect();
        $form = ConsentForm::query()->firstOrFail();
        $this->assertSame(ConsentForm::checksumFor('ექსკურსია', 'ნებას ვრთავ...'), $form->body_checksum);

        $this->actingAs($f['guardian'])->post("/portal/consents/{$form->id}/respond", ['student_id' => $f['student']->id, 'granted' => true])->assertRedirect();
        $this->actingAs($f['guardian'])->post("/portal/consents/{$form->id}/respond", ['student_id' => $f['student']->id, 'granted' => false])->assertRedirect();

        $events = ConsentResponseEvent::query()->orderBy('id')->get();
        $this->assertSame(['granted', 'revoked'], $events->pluck('decision')->all());
        $this->assertSame([$form->body_checksum], $events->pluck('form_checksum')->unique()->values()->all());
        $this->assertFalse(ConsentResponse::query()->firstOrFail()->granted);

        // A fresh decline (never granted before) is a full answer, recorded as "declined".
        $this->actingAs($f['otherGuardian'])->post("/portal/consents/{$form->id}/respond", ['student_id' => $f['otherStudent']->id, 'granted' => false]);
        $this->assertSame('declined', ConsentResponseEvent::query()->where('student_id', $f['otherStudent']->id)->value('decision'));
    }

    public function test_consent_cutoff_is_enforced_on_the_server(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['director'])->post('/portal/consents', [
            'title' => 'ფოტო', 'body' => 'ფოტოს გამოყენება', 'responses_close_at' => now('Asia/Tbilisi')->subHour()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('responses_close_at');

        $this->actingAs($f['director'])->post('/portal/consents', [
            'title' => 'ფოტო', 'body' => 'ფოტოს გამოყენება', 'responses_close_at' => now('Asia/Tbilisi')->addHour()->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();
        $form = ConsentForm::query()->firstOrFail();

        $this->travel(2)->hours();

        $this->actingAs($f['guardian'])->post("/portal/consents/{$form->id}/respond", ['student_id' => $f['student']->id, 'granted' => true])
            ->assertSessionHasErrors('granted');
        $this->assertSame(0, ConsentResponse::query()->count());
    }
}
