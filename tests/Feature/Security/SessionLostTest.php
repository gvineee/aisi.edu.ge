<?php

namespace Tests\Feature\Security;

use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Http\SessionLostResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit A03: an upload sent after the session ended was redirected to the
 * sign-in page, and the form's onSuccess called that "file uploaded". A
 * write that lost its session now gets a full-page visit (Inertia never
 * calls onSuccess for it) and a plain note that nothing was saved.
 */
class SessionLostTest extends TestCase
{
    use RefreshDatabase;

    private const INERTIA = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];

    public function test_an_upload_after_sign_out_saves_nothing_and_says_so(): void
    {
        Storage::fake('local');

        $response = $this->from('/documents')->withHeaders(self::INERTIA)->post('/documents', [
            'title' => 'ოქმი',
            'file' => UploadedFile::fake()->create('protocol.pdf', 20, 'application/pdf'),
        ]);

        $response->assertStatus(409)->assertHeader('X-Inertia-Location', route('login'));
        $this->assertSame(0, Document::query()->count());
        $this->assertSame(SessionLostResponse::MESSAGE, session('status'));
        // Signing in again leads back to the form's page, not the POST URL.
        $this->assertSame(url('/documents'), session('url.intended'));
        $this->flushHeaders()->get(route('login'))->assertInertia(fn ($page) => $page->where('status', SessionLostResponse::MESSAGE));
    }

    public function test_ordinary_requests_keep_the_usual_sign_in_redirect(): void
    {
        $this->post('/documents', ['title' => 'x'])->assertRedirect(route('login'));
        $this->withHeaders(self::INERTIA)->get('/documents')->assertRedirect(route('login'));
    }

    public function test_a_stale_form_token_on_a_public_form_reloads_the_page_with_a_note(): void
    {
        Route::middleware('web')->post('/_qa/stale-token', fn () => throw new TokenMismatchException);

        $this->from('/admissions')->withHeaders(self::INERTIA)->post('/_qa/stale-token')
            ->assertStatus(409)->assertHeader('X-Inertia-Location', url('/admissions'));
        $this->assertSame(SessionLostResponse::PUBLIC_MESSAGE, session('toast.message'));
    }

    public function test_a_stale_form_token_behind_sign_in_goes_to_sign_in(): void
    {
        Route::middleware(['web', 'auth'])->post('/_qa/stale-private', fn () => throw new TokenMismatchException);

        $this->from('/portal/teacher')->withHeaders(self::INERTIA)->post('/_qa/stale-private')
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('login'));
    }

    /**
     * Every `throttle:X,Y` route used to share one counter per visitor, so
     * loading the free visit times five times blocked the visit form.
     */
    public function test_each_throttled_route_counts_on_its_own(): void
    {
        TenantDomain::query()->where('domain', 'localhost')->firstOrFail();

        for ($i = 0; $i < 8; $i++) {
            $this->getJson('/admissions/visit-slots')->assertOk();
        }

        $lead = ['guardian_name' => 'სატესტო მშობელი', 'contact_method' => 'phone', 'contact_value' => '+995 555 000 000', 'consent_given' => true];
        $this->post('/admissions/leads', $lead)->assertSessionHasNoErrors();
        $this->assertSame(1, AdmissionLead::query()->count());

        // The form's own limit still holds, and an Inertia form hears it as
        // an error on the form, not as a redirect it would read as "sent".
        for ($i = 0; $i < 4; $i++) {
            $this->post('/admissions/leads', $lead);
        }
        $this->from('/admissions')->withHeaders(self::INERTIA)->post('/admissions/leads', $lead)
            ->assertRedirect('/admissions')->assertSessionHasErrors('rate_limit');
        $this->assertSame(5, AdmissionLead::query()->count());
    }
}
