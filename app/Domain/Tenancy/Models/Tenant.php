<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Library\Models\LibraryResource;
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

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return HasMany<LibraryResource, $this>
     */
    public function libraryResources(): HasMany
    {
        return $this->hasMany(LibraryResource::class);
    }

    /**
     * @return HasMany<AcademicYear, $this>
     */
    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    /**
     * @return HasMany<SchoolClass, $this>
     */
    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    /**
     * @return HasMany<Student, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * @return HasMany<GuardianLink, $this>
     */
    public function guardianLinks(): HasMany
    {
        return $this->hasMany(GuardianLink::class);
    }

    /**
     * @return HasMany<TeacherAssignment, $this>
     */
    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }

    public function hasFeature(string $feature): bool
    {
        return $this->featureEntitlements()
            ->where('feature', $feature)
            ->where('is_enabled', true)
            ->exists();
    }
}
