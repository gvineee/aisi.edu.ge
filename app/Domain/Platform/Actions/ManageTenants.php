<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\DomainVerifier;
use App\Domain\Platform\Models\SubscriptionPlan;
use App\Domain\Platform\Models\TenantOnboardingStep;
use App\Domain\Platform\Models\TenantSubscription;
use App\Domain\Platform\PlatformAudit;
use App\Domain\Tenancy\Models\BrandSetting;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Platform-side tenant lifecycle: create (inactive, with a pending domain
 * and the onboarding steps), add/verify domains, switch features, assign a
 * plan. Every action is recorded in the platform audit log. Switching a
 * feature off never deletes its data.
 */
class ManageTenants
{
    public function __construct(
        private readonly PlatformAudit $audit,
        private readonly DomainVerifier $verifier,
    ) {}

    public function create(User $operator, string $slug, string $name, string $domain): Tenant
    {
        $slug = Str::lower(trim($slug));
        $domain = Str::lower(trim($domain));

        if (Tenant::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'ეს იდენტიფიკატორი დაკავებულია.']);
        }

        if (TenantDomain::query()->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => 'ეს დომენი უკვე გამოყენებულია.']);
        }

        return DB::transaction(function () use ($operator, $slug, $name, $domain) {
            // Inactive until onboarding is reviewed: serves nothing yet.
            $tenant = Tenant::query()->create(['slug' => $slug, 'name' => $name, 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => false]);

            BrandSetting::query()->create([
                'tenant_id' => $tenant->id,
                'display_name' => $name,
                'colors' => ['primary' => '#132B45', 'accent' => '#F5683C', 'secondary' => '#F4F7FA'],
            ]);

            $this->createDomain($tenant, $domain, true);

            foreach (TenantOnboardingStep::STEPS as $step) {
                TenantOnboardingStep::query()->create([
                    'tenant_id' => $tenant->id,
                    'step' => $step,
                    'completed_at' => $step === 'identity' ? Carbon::now() : null,
                    'completed_by' => $step === 'identity' ? $operator->id : null,
                ]);
            }

            $this->audit->record($operator, 'tenant.created', $tenant->id, ['slug' => $slug, 'domain' => $domain]);

            return $tenant;
        });
    }

    public function addDomain(User $operator, Tenant $tenant, string $domain): TenantDomain
    {
        $domain = Str::lower(trim($domain));

        if (TenantDomain::query()->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => 'ეს დომენი უკვე გამოყენებულია.']);
        }

        $model = $this->createDomain($tenant, $domain, false);
        $this->audit->record($operator, 'tenant.domain_added', $tenant->id, ['domain' => $domain]);

        return $model;
    }

    public function verifyDomain(User $operator, TenantDomain $domain): bool
    {
        if ($domain->verified_at !== null) {
            return true;
        }

        $ok = $domain->verification_token !== null && $this->verifier->verify($domain->domain, $domain->verification_token);

        if ($ok) {
            $domain->verified_at = Carbon::now();
            $domain->save();
            $this->completeStep($domain->tenant_id, 'domain', $operator);
        }

        $this->audit->record($operator, $ok ? 'tenant.domain_verified' : 'tenant.domain_verification_failed', $domain->tenant_id, ['domain' => $domain->domain]);

        return $ok;
    }

    public function setFeature(User $operator, Tenant $tenant, string $feature, bool $enabled): void
    {
        if (! in_array($feature, FeatureEntitlement::ALL, true)) {
            throw ValidationException::withMessages(['feature' => 'უცნობი მოდული.']);
        }

        $entitlement = FeatureEntitlement::query()->firstOrNew(['tenant_id' => $tenant->id, 'feature' => $feature]);
        $entitlement->is_enabled = $enabled;
        $entitlement->save();

        $this->audit->record($operator, 'tenant.feature_changed', $tenant->id, ['feature' => $feature, 'enabled' => $enabled]);
    }

    public function assignPlan(User $operator, Tenant $tenant, SubscriptionPlan $plan, string $status): TenantSubscription
    {
        if (! in_array($status, ['trial', 'active', 'suspended', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => 'სტატუსი არასწორია.']);
        }

        return DB::transaction(function () use ($operator, $tenant, $plan, $status) {
            TenantSubscription::query()->where('tenant_id', $tenant->id)->whereIn('status', ['trial', 'active'])
                ->update(['status' => 'cancelled', 'ends_on' => Carbon::now()->toDateString()]);

            $subscription = TenantSubscription::query()->create([
                'tenant_id' => $tenant->id,
                'subscription_plan_id' => $plan->id,
                'status' => $status,
                'starts_on' => Carbon::now()->toDateString(),
            ]);

            $this->audit->record($operator, 'tenant.plan_assigned', $tenant->id, ['plan' => $plan->code, 'status' => $status]);

            return $subscription;
        });
    }

    public function completeStep(int $tenantId, string $step, User $operator): void
    {
        TenantOnboardingStep::query()
            ->where('tenant_id', $tenantId)
            ->where('step', $step)
            ->whereNull('completed_at')
            ->update(['completed_at' => Carbon::now(), 'completed_by' => $operator->id]);
    }

    private function createDomain(Tenant $tenant, string $domain, bool $primary): TenantDomain
    {
        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw ValidationException::withMessages(['domain' => 'დომენის ფორმატი არასწორია.']);
        }

        return TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'domain' => $domain,
            'is_primary' => $primary,
            'verification_token' => 'pv-'.Str::random(40),
            'verified_at' => null,
        ]);
    }
}
