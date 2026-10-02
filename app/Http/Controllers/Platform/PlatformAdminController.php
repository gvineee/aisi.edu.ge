<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Academics\Models\Student;
use App\Domain\Platform\Actions\ManageSupportAccess;
use App\Domain\Platform\Actions\ManageTenants;
use App\Domain\Platform\Actions\OnboardingWizard;
use App\Domain\Platform\DomainVerifier;
use App\Domain\Platform\Models\PlatformAuditEvent;
use App\Domain\Platform\Models\SubscriptionPlan;
use App\Domain\Platform\Models\SupportAccessRequest;
use App\Domain\Platform\Models\TenantOnboardingStep;
use App\Domain\Platform\Models\TenantSubscription;
use App\Domain\Platform\PlatformAudit;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform operator console (`/platform-admin`, behind `platform`
 * middleware). Cross-tenant by design, so every query names its tenant
 * explicitly and never relies on the request host's tenant scope. It shows
 * configuration and aggregate counts only — no school's people data;
 * deeper diagnosis needs an approved, time-limited support session.
 */
class PlatformAdminController extends Controller
{
    public function index(): Response
    {
        $tenants = Tenant::query()->with('brandSetting')->orderBy('name')->get();

        return Inertia::render('platform/index', [
            'tenants' => $tenants->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'isActive' => $tenant->is_active,
                'primaryDomain' => TenantDomain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->value('domain'),
                'pendingSteps' => TenantOnboardingStep::query()->where('tenant_id', $tenant->id)->whereNull('completed_at')->count(),
            ])->values(),
            'plans' => SubscriptionPlan::query()->with('features')->orderBy('name')->get()->map(fn (SubscriptionPlan $plan) => [
                'id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name, 'priceMinor' => $plan->price_minor,
                'currency' => $plan->currency, 'billingPeriod' => $plan->billing_period,
                'features' => $plan->features->pluck('feature')->values()->all(),
            ])->values(),
            'features' => FeatureEntitlement::ALL,
        ]);
    }

    public function storeTenant(Request $request, ManageTenants $tenants): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:253'],
        ]);

        $tenant = $tenants->create($request->user(), $data['slug'], $data['name'], $data['domain']);

        return redirect()->route('platform.tenants.show', $tenant);
    }

    public function show(Tenant $tenant, ManageSupportAccess $support, Request $request): Response
    {
        $tenant->load('brandSetting');

        return Inertia::render('platform/tenant', [
            'tenant' => [
                'id' => $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->name, 'isActive' => $tenant->is_active,
                'locale' => $tenant->locale, 'timezone' => $tenant->timezone,
                'brand' => $tenant->brandSetting === null ? null : [
                    'displayName' => $tenant->brandSetting->display_name,
                    'shortName' => $tenant->brandSetting->short_name,
                    'colors' => $tenant->brandSetting->colors,
                    'hasLogo' => $tenant->brandSetting->logo_path !== null,
                    'hasDarkLogo' => $tenant->brandSetting->logo_dark_path !== null,
                    'hasIcon' => $tenant->brandSetting->icon_path !== null,
                ],
            ],
            'domains' => TenantDomain::query()->where('tenant_id', $tenant->id)->get()->map(fn (TenantDomain $d) => [
                'id' => $d->id, 'domain' => $d->domain, 'isPrimary' => $d->is_primary,
                'verified' => $d->verified_at !== null,
                'txtName' => DomainVerifier::RECORD_PREFIX.$d->domain,
                'token' => $d->verified_at === null ? $d->verification_token : null,
            ])->values(),
            'steps' => collect(TenantOnboardingStep::STEPS)->map(fn (string $step) => [
                'step' => $step,
                'done' => TenantOnboardingStep::query()->where('tenant_id', $tenant->id)->where('step', $step)->whereNotNull('completed_at')->exists(),
            ])->values(),
            'features' => collect(FeatureEntitlement::ALL)->map(fn (string $feature) => [
                'feature' => $feature, 'enabled' => FeatureEntitlement::isEnabled($tenant->id, $feature),
            ])->values(),
            'subscription' => ($sub = TenantSubscription::query()->where('tenant_id', $tenant->id)->whereIn('status', ['trial', 'active', 'suspended'])->with('plan')->latest('id')->first()) === null ? null : [
                'plan' => $sub->plan->name, 'status' => $sub->status, 'startsOn' => $sub->starts_on->toDateString(),
            ],
            'plans' => SubscriptionPlan::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'supportRequests' => SupportAccessRequest::query()->where('tenant_id', $tenant->id)->latest('id')->limit(20)->get()->map(fn (SupportAccessRequest $r) => [
                'id' => $r->id, 'status' => $r->status, 'reason' => $r->reason, 'minutes' => $r->requested_minutes,
                'expiresAt' => $r->expires_at?->toIso8601String(), 'isActive' => $r->isActive(),
            ])->values(),
            'activeSupport' => $support->activeFor($request->user(), $tenant) !== null,
            'invitationLink' => $request->session()->get('invitationLink'),
            'audit' => PlatformAuditEvent::query()->where('tenant_id', $tenant->id)->latest('id')->limit(30)->get()->map(fn (PlatformAuditEvent $e) => [
                'action' => $e->action, 'createdAt' => $e->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function addDomain(Request $request, Tenant $tenant, ManageTenants $tenants): RedirectResponse
    {
        $request->validate(['domain' => ['required', 'string', 'max:253']]);
        $tenants->addDomain($request->user(), $tenant, (string) $request->string('domain'));

        return back();
    }

    public function verifyDomain(Request $request, Tenant $tenant, TenantDomain $domain, ManageTenants $tenants): RedirectResponse
    {
        abort_unless($domain->tenant_id === $tenant->id, 404);
        $ok = $tenants->verifyDomain($request->user(), $domain);

        return back()->with('toast', ['type' => $ok ? 'success' : 'error', 'message' => $ok ? 'დომენი დადასტურდა.' : 'TXT ჩანაწერი ვერ მოიძებნა.']);
    }

    public function onboarding(Request $request, Tenant $tenant, string $step, OnboardingWizard $wizard): RedirectResponse
    {
        $user = $request->user();

        match ($step) {
            'brand' => $wizard->brand($user, $tenant, $request->validate([
                'display_name' => ['required', 'string', 'max:255'],
                'short_name' => ['nullable', 'string', 'max:60'],
                'primary' => ['required', 'string'],
                'accent' => ['required', 'string'],
                'secondary' => ['required', 'string'],
                'logo' => ['nullable', 'file', 'max:2048'],
                'logo_dark' => ['nullable', 'file', 'max:2048'],
                'icon' => ['nullable', 'file', 'max:2048'],
            ]), [
                'logo' => $request->file('logo'),
                'logo_dark' => $request->file('logo_dark'),
                'icon' => $request->file('icon'),
            ]),
            'locale' => $wizard->locale($user, $tenant, (string) $request->string('locale'), (string) $request->string('timezone')),
            'academic_year' => (function () use ($request, $wizard, $user, $tenant) {
                $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date']]);
                $wizard->academicYear($user, $tenant, $data['name'], Carbon::parse($data['starts_on']), Carbon::parse($data['ends_on']));
            })(),
            'staff_invites' => (function () use ($request, $wizard, $user, $tenant) {
                $data = $request->validate(['email' => ['required', 'email']]);
                $invitation = $wizard->staffInvite($user, $tenant, $data['email']);
                $domain = TenantDomain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->value('domain');
                session()->flash('invitationLink', "https://{$domain}/invitations/{$invitation->token}");
            })(),
            'modules' => $wizard->modules($user, $tenant, array_values(array_filter((array) $request->input('features', []), 'is_string'))),
            'review' => $wizard->review($user, $tenant),
            default => abort(404),
        };

        return back();
    }

    public function setFeature(Request $request, Tenant $tenant, ManageTenants $tenants): RedirectResponse
    {
        $request->validate(['feature' => ['required', 'string'], 'enabled' => ['required', 'boolean']]);
        $tenants->setFeature($request->user(), $tenant, (string) $request->string('feature'), $request->boolean('enabled'));

        return back();
    }

    public function assignPlan(Request $request, Tenant $tenant, ManageTenants $tenants): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'integer'], 'status' => ['required', 'string']]);
        $plan = SubscriptionPlan::query()->findOrFail((int) $data['plan_id']);
        $tenants->assignPlan($request->user(), $tenant, $plan, $data['status']);

        return back();
    }

    public function storePlan(Request $request, PlatformAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/', 'unique:subscription_plans,code'],
            'name' => ['required', 'string', 'max:255'],
            'features' => ['array'],
            'features.*' => ['string', 'in:'.implode(',', FeatureEntitlement::ALL)],
        ]);

        DB::transaction(function () use ($data, $request, $audit) {
            // No price: commercial terms are not defined yet.
            $plan = SubscriptionPlan::query()->create(['code' => $data['code'], 'name' => $data['name'], 'is_active' => true]);
            foreach (array_unique($data['features'] ?? []) as $feature) {
                $plan->features()->create(['feature' => $feature]);
            }
            $audit->record($request->user(), 'plan.created', null, ['code' => $plan->code]);
        });

        return back();
    }

    public function requestSupport(Request $request, Tenant $tenant, ManageSupportAccess $support): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'minutes' => ['required', 'integer']]);
        $support->request($request->user(), $tenant, $data['reason'], (int) $data['minutes']);

        return back();
    }

    /**
     * Read-only diagnostics during an approved support session: settings
     * and aggregate counts, shown under a visible banner; every view is
     * audited on both sides.
     */
    public function supportView(Request $request, Tenant $tenant, ManageSupportAccess $support, PlatformAudit $audit): Response
    {
        $grant = $support->activeFor($request->user(), $tenant);
        abort_unless($grant !== null, 403);

        $audit->record($request->user(), 'support.viewed', $tenant->id, ['request_id' => $grant->id]);

        return Inertia::render('platform/support-view', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'expiresAt' => $grant->expires_at?->toIso8601String(),
            'counts' => [
                'activeStudents' => Student::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('is_active', true)->count(),
                'members' => TenantMembership::query()->where('tenant_id', $tenant->id)->where('is_active', true)
                    ->selectRaw('role, count(*) as aggregate')->groupBy('role')->pluck('aggregate', 'role'),
            ],
            'features' => collect(FeatureEntitlement::ALL)->mapWithKeys(fn (string $f) => [$f => FeatureEntitlement::isEnabled($tenant->id, $f)]),
        ]);
    }
}
