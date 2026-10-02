<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Platform\Models\TenantOnboardingStep;
use App\Domain\Platform\PlatformAudit;
use App\Domain\Tenancy\Actions\CreateTenantInvitation;
use App\Domain\Tenancy\Models\BrandSetting;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantInvitation;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The onboarding wizard (identity/domain → brand → locale/timezone →
 * academic year → staff invites → modules → review). Each step can be
 * re-run safely: it updates rather than duplicates, so an interrupted
 * onboarding resumes where it stopped. The tenant only goes live at review,
 * once its primary domain is DNS-verified and the required steps are done.
 */
class OnboardingWizard
{
    /**
     * Brand uploads: raster only — no SVG (it can carry script) and no
     * arbitrary files (CLAUDE.md design rules).
     *
     * @var array<int, string>
     */
    private const LOGO_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    private const LOGO_MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly PlatformAudit $audit,
        private readonly ManageTenants $tenants,
        private readonly CreateTenantInvitation $createInvitation,
    ) {}

    /**
     * @param  array{display_name: string, short_name?: string|null, primary: string, accent: string, secondary: string}  $data
     * @param  array{logo?: UploadedFile|null, logo_dark?: UploadedFile|null, icon?: UploadedFile|null}  $files
     */
    public function brand(User $operator, Tenant $tenant, array $data, array $files): void
    {
        foreach (['primary', 'accent', 'secondary'] as $key) {
            if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $data[$key])) {
                throw ValidationException::withMessages([$key => 'ფერი უნდა იყოს #RRGGBB ფორმატში.']);
            }
        }

        $brand = BrandSetting::query()->firstOrNew(['tenant_id' => $tenant->id]);
        $brand->display_name = $data['display_name'];
        $brand->short_name = $data['short_name'] ?? null;
        $brand->colors = ['primary' => $data['primary'], 'accent' => $data['accent'], 'secondary' => $data['secondary']];

        foreach (['logo' => 'logo_path', 'logo_dark' => 'logo_dark_path', 'icon' => 'icon_path'] as $input => $column) {
            $file = $files[$input] ?? null;

            if ($file === null) {
                continue;
            }

            if (! in_array((string) $file->getMimeType(), self::LOGO_MIME_TYPES, true) || ($file->getSize() ?: 0) > self::LOGO_MAX_BYTES) {
                throw ValidationException::withMessages([$input => 'დაშვებულია PNG, JPEG ან WebP, მაქსიმუმ 2MB (SVG — არა).']);
            }

            $extension = match ((string) $file->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $path = "brand/{$tenant->id}/{$input}-".bin2hex(random_bytes(6)).".{$extension}";
            Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));
            $brand->{$column} = $path;
        }

        $brand->save();
        $this->done($operator, $tenant, 'brand');
    }

    public function locale(User $operator, Tenant $tenant, string $locale, string $timezone): void
    {
        if (! in_array($locale, ['ka', 'en'], true)) {
            throw ValidationException::withMessages(['locale' => 'ენა არასწორია.']);
        }

        if (! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages(['timezone' => 'დროის სარტყელი არასწორია.']);
        }

        $tenant->locale = $locale;
        $tenant->timezone = $timezone;
        $tenant->save();

        $this->done($operator, $tenant, 'locale');
    }

    public function academicYear(User $operator, Tenant $tenant, string $name, Carbon $startsOn, Carbon $endsOn): void
    {
        if ($endsOn->lte($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'დასრულება დაწყების შემდეგ უნდა იყოს.']);
        }

        // Idempotent by name: re-running updates the same year.
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', $name)->first()
            ?? new AcademicYear(['name' => $name]);
        $year->tenant_id = $tenant->id;
        $year->starts_on = $startsOn;
        $year->ends_on = $endsOn;
        $year->is_current = true;
        $year->save();

        AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->whereKeyNot($year->id)->update(['is_current' => false]);

        $this->done($operator, $tenant, 'academic_year');
    }

    /**
     * Invites the school's first administrator. Re-running with the same
     * email reuses the open invitation instead of creating another.
     */
    public function staffInvite(User $operator, Tenant $tenant, string $email): TenantInvitation
    {
        $email = mb_strtolower(trim($email));

        $existing = TenantInvitation::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)->where('email', $email)->whereNull('accepted_at')->where('expires_at', '>', Carbon::now())->first();

        $invitation = $existing ?? $this->createInvitation->handle($tenant->id, $operator, $email, TenantMembership::ROLE_ADMIN, null, null, null);

        $this->done($operator, $tenant, 'staff_invites');

        return $invitation;
    }

    /**
     * @param  array<int, string>  $features
     */
    public function modules(User $operator, Tenant $tenant, array $features): void
    {
        foreach (FeatureEntitlement::ALL as $feature) {
            $this->tenants->setFeature($operator, $tenant, $feature, in_array($feature, $features, true));
        }

        $this->done($operator, $tenant, 'modules');
    }

    /**
     * Goes live only when the primary domain is verified and every earlier
     * step is complete.
     */
    public function review(User $operator, Tenant $tenant): void
    {
        $missing = TenantOnboardingStep::query()->where('tenant_id', $tenant->id)->where('step', '!=', 'review')->whereNull('completed_at')->pluck('step')->all();

        $primaryVerified = TenantDomain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->whereNotNull('verified_at')->exists();

        if ($missing !== [] || ! $primaryVerified) {
            throw ValidationException::withMessages(['review' => 'დასასრულებელია: '.implode(', ', $missing === [] ? ['domain'] : $missing)]);
        }

        DB::transaction(function () use ($operator, $tenant) {
            $tenant->is_active = true;
            $tenant->save();
            $this->done($operator, $tenant, 'review');
            $this->audit->record($operator, 'tenant.activated', $tenant->id);
        });
    }

    private function done(User $operator, Tenant $tenant, string $step): void
    {
        TenantOnboardingStep::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'step' => $step],
            ['completed_at' => Carbon::now(), 'completed_by' => $operator->id],
        );

        $this->audit->record($operator, "onboarding.{$step}", $tenant->id);
    }
}
