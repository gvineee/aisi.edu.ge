<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Content\Models\Page;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $locale
 * @property string $timezone
 * @property bool $is_active
 */
#[Fillable(['slug', 'name', 'locale', 'timezone', 'is_active'])]
class Tenant extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TenantDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /**
     * @return HasOne<BrandSetting, $this>
     */
    public function brandSetting(): HasOne
    {
        return $this->hasOne(BrandSetting::class);
    }

    /**
     * @return HasMany<FeatureEntitlement, $this>
     */
    public function featureEntitlements(): HasMany
    {
        return $this->hasMany(FeatureEntitlement::class);
    }

    /**
     * @return HasMany<Page, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /**
     * @return HasMany<AdmissionLead, $this>
     */
    public function admissionLeads(): HasMany
    {
        return $this->hasMany(AdmissionLead::class);
    }

    public function hasFeature(string $feature): bool
    {
        return $this->featureEntitlements()
            ->where('feature', $feature)
            ->where('is_enabled', true)
            ->exists();
    }
}
