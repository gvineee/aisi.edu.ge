<?php

namespace Database\Seeders;

use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\BrandSetting;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local/dev seed data only — never run against a production database.
 *
 * Creates the real "aisi" tenant plus a second, unrelated tenant whose only
 * purpose is to prove cross-tenant isolation (see docs/02 critical check #1
 * and tests/Feature/Tenancy/TenantIsolationTest.php). Contact details for
 * "aisi" are sourced from docs/01-existing-site-audit.md's footer capture —
 * the audit explicitly flags these as needing school confirmation before any
 * production release; they are placeholders here for local development.
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $aisi = Tenant::create([
            'slug' => 'aisi',
            'name' => 'აისი',
            'locale' => 'ka',
            'timezone' => 'Asia/Tbilisi',
            'is_active' => true,
        ]);

        foreach (['localhost', '127.0.0.1', 'aisi.test'] as $i => $domain) {
            TenantDomain::create([
                'tenant_id' => $aisi->id,
                'domain' => $domain,
                'is_primary' => $i === 0,
            ]);
        }

        BrandSetting::create([
            'tenant_id' => $aisi->id,
            'display_name' => 'აისი',
            'short_name' => 'აისი',
            'logo_path' => 'tenants/aisi/logo.png',
            'favicon_path' => null,
            'colors' => [
                'primary' => '#132B45',
                'accent' => '#F5683C',
                'secondary' => '#F4F7FA',
                'muted' => '#526579',
                'teal' => '#147D78',
            ],
            'font_family' => 'Noto Sans Georgian',
            // Sourced from docs/01 footer capture — pending school confirmation.
            'contact_email' => 'info@aisi.edu.ge',
            'contact_phone' => '+995 599 159 800',
            'contact_address' => 'თბილისი, დიდი დიღომი, დავარის 64',
            'social_links' => [],
        ]);

        foreach ([
            FeatureEntitlement::FEATURE_WEB,
            FeatureEntitlement::FEATURE_SCHOOL_PORTAL,
        ] as $feature) {
            FeatureEntitlement::create([
                'tenant_id' => $aisi->id,
                'feature' => $feature,
                'is_enabled' => true,
            ]);
        }

        foreach ([
            FeatureEntitlement::FEATURE_FINANCE,
            FeatureEntitlement::FEATURE_NETWORK,
        ] as $feature) {
            FeatureEntitlement::create([
                'tenant_id' => $aisi->id,
                'feature' => $feature,
                'is_enabled' => false,
            ]);
        }

        $admin = User::factory()->create([
            'name' => 'აისის ადმინისტრატორი',
            'email' => 'admin@aisi.test',
        ]);

        TenantMembership::create([
            'tenant_id' => $aisi->id,
            'user_id' => $admin->id,
            'role' => TenantMembership::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $aisi->pages()->create([
            'slug' => 'home',
            'locale' => 'ka',
            'title' => 'მთავარი',
            'excerpt' => 'დიდი აღმოჩენები იწყება აისით.',
            'blocks' => [
                [
                    'type' => 'hero',
                    'eyebrow' => 'შენი მომავალი აქ იწყება',
                    'heading' => 'დიდი აღმოჩენები იწყება აისით.',
                    'body' => 'სივრცე, სადაც ცნობისმოყვარეობა ცოდნად იქცევა, ბავშვები კი საკუთარი გზის პოვნას სწავლობენ.',
                ],
                [
                    'type' => 'programs',
                    'heading' => 'ყოველ ეტაპს — თავისი აღმოჩენა.',
                    'items' => [
                        ['title' => 'პირველი ნაბიჯები', 'grade' => 'დაწყებითი საფეხური', 'body' => 'კითხვის სიხარული, პირველი აღმოჩენები და სწავლის სიყვარული.'],
                        ['title' => 'ინტერესების აღმოჩენა', 'grade' => 'საბაზო საფეხური', 'body' => 'კითხვებიდან იდეებამდე — მეტი დამოუკიდებლობა და თანამშრომლობა.'],
                        ['title' => 'საკუთარი გზა', 'grade' => 'საშუალო საფეხური', 'body' => 'გაცნობიერებული არჩევანი და მომავლისთვის მზადება.'],
                    ],
                ],
                [
                    'type' => 'life',
                    'heading' => 'დღეები, რომლებიც გვზრდის.',
                ],
                [
                    'type' => 'contact_cta',
                    'heading' => 'გავიცნოთ ერთმანეთი.',
                    'body' => 'აირჩიეთ დრო სკოლასთან სასაუბროდ.',
                ],
            ],
            'status' => Page::STATUS_PUBLISHED,
            'published_at' => now(),
            'seo_title' => 'სკოლა აისი — ყოველი დღე ახალი შესაძლებლობაა',
            'seo_description' => 'გაიცანით სკოლა აისი, აღმოაჩინეთ სასწავლო გარემო და დაგეგმეთ თქვენი პირველი ვიზიტი.',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        // --- Second, unrelated tenant: exists ONLY to prove isolation ---
        $second = Tenant::create([
            'slug' => 'second-school-demo',
            'name' => 'Second School (isolation fixture)',
            'locale' => 'en',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        TenantDomain::create([
            'tenant_id' => $second->id,
            'domain' => 'second-school.test',
            'is_primary' => true,
        ]);

        BrandSetting::create([
            'tenant_id' => $second->id,
            'display_name' => 'Second School',
            'short_name' => 'Second',
            'logo_path' => null,
            'favicon_path' => null,
            'colors' => [
                'primary' => '#0F172A',
                'accent' => '#059669',
                'secondary' => '#F1F5F9',
                'muted' => '#475569',
                'teal' => '#0EA5E9',
            ],
            'font_family' => 'Noto Sans Georgian',
            'contact_email' => 'info@second-school.test',
            'contact_phone' => null,
            'contact_address' => null,
            'social_links' => [],
        ]);

        FeatureEntitlement::create([
            'tenant_id' => $second->id,
            'feature' => FeatureEntitlement::FEATURE_WEB,
            'is_enabled' => true,
        ]);

        $secondAdmin = User::factory()->create([
            'name' => 'Second School Admin',
            'email' => 'admin@second-school.test',
        ]);

        TenantMembership::create([
            'tenant_id' => $second->id,
            'user_id' => $secondAdmin->id,
            'role' => TenantMembership::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $second->pages()->create([
            'slug' => 'home',
            'locale' => 'en',
            'title' => 'Home',
            'excerpt' => 'This page exists only to prove tenant isolation.',
            'blocks' => [
                ['type' => 'hero', 'eyebrow' => 'Isolation fixture', 'heading' => 'Second School', 'body' => 'Not aisi.'],
            ],
            'status' => Page::STATUS_PUBLISHED,
            'published_at' => now(),
            'seo_title' => 'Second School',
            'seo_description' => 'Isolation fixture tenant.',
            'created_by' => $secondAdmin->id,
            'updated_by' => $secondAdmin->id,
        ]);
    }
}
