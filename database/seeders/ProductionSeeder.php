<?php

namespace Database\Seeders;

use App\Domain\Content\AboutPageBlueprint;
use App\Domain\Content\HomePageBlueprint;
use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\BrandSetting;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Production-only seed data. Deliberately the opposite of TenantSeeder: no
 * fictional students/teachers/lessons/documents, no "დემო" accounts, no
 * placeholder posts explicitly marked as concept copy. Seeds only what is
 * real: the tenant, its real domains and brand, feature flags, one real
 * admin account (password generated at seed time, printed once), and the
 * five real marketing pages already treated as production copy since Phase 1
 * (see TenantSeeder's own doc-comment and docs/04-design-handoff.md).
 *
 * Run once, on first launch: `php artisan db:seed --class=ProductionSeeder --force`.
 * Safe to re-run: every step is `firstOrCreate`/`updateOrCreate`-based (keyed
 * on natural unique columns — slug, domain, email, tenant+feature,
 * tenant+slug+locale), so an interrupted first run can simply be re-run
 * rather than requiring a manual rollback of partial data. Re-running does
 * NOT regenerate the admin password once that user already exists.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $adminPassword = str()->password(20);
        $adminExistedAlready = User::query()->where('email', 'admin@aisi.edu.ge')->exists();

        DB::transaction(function () use ($adminPassword): void {
            $this->seedTenant($adminPassword);
        });

        if ($adminExistedAlready) {
            $this->command->newLine();
            $this->command->comment('admin@aisi.edu.ge already existed — password left unchanged, not reprinted.');

            return;
        }

        $this->command->newLine();
        $this->command->warn('=== ADMIN LOGIN — save this now, it is only printed once ===');
        $this->command->line('  email:    admin@aisi.edu.ge');
        $this->command->line("  password: {$adminPassword}");
        $this->command->warn('=============================================================');
    }

    private function seedTenant(string $adminPassword): void
    {
        $aisi = Tenant::firstOrCreate(
            ['slug' => 'aisi'],
            [
                'name' => 'აისი',
                'locale' => 'ka',
                'timezone' => 'Asia/Tbilisi',
                'is_active' => true,
            ],
        );

        foreach (['aisi.edu.ge', 'www.aisi.edu.ge'] as $i => $domain) {
            TenantDomain::firstOrCreate(
                ['domain' => $domain],
                ['tenant_id' => $aisi->id, 'is_primary' => $i === 0],
            );
        }

        BrandSetting::firstOrCreate(
            ['tenant_id' => $aisi->id],
            [
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
            ],
        );

        foreach ([
            FeatureEntitlement::FEATURE_WEB => true,
            FeatureEntitlement::FEATURE_SCHOOL_PORTAL => true,
            FeatureEntitlement::FEATURE_FINANCE => false,
            FeatureEntitlement::FEATURE_NETWORK => false,
        ] as $feature => $isEnabled) {
            FeatureEntitlement::firstOrCreate(
                ['tenant_id' => $aisi->id, 'feature' => $feature],
                ['is_enabled' => $isEnabled],
            );
        }

        $admin = User::query()->where('email', 'admin@aisi.edu.ge')->first();

        if (! $admin) {
            $admin = User::create([
                'name' => 'აისის ადმინისტრატორი',
                'email' => 'admin@aisi.edu.ge',
                'password' => Hash::make($adminPassword),
            ]);
            $admin->forceFill(['email_verified_at' => now()])->save();
        }

        TenantMembership::firstOrCreate(
            ['tenant_id' => $aisi->id, 'user_id' => $admin->id, 'role' => TenantMembership::ROLE_ADMIN],
            ['is_active' => true],
        );

        foreach ([
            [
                'slug' => 'home',
                'title' => 'მთავარი',
                'excerpt' => 'აქ იწყება შენი ხვალ.',
                'seo_title' => 'სკოლა აისი — აქ იწყება შენი ხვალ',
                'seo_description' => 'გაიცანით სკოლა აისი, აღმოაჩინეთ სასწავლო გარემო და დაგეგმეთ თქვენი პირველი ვიზიტი.',
                'blocks' => HomePageBlueprint::blocks(),
            ],
            [
                'slug' => 'about',
                'title' => 'სკოლის შესახებ',
                'excerpt' => 'აისის მისია, ისტორია და გარემო.',
                'seo_title' => 'სკოლის შესახებ — აისი',
                'seo_description' => 'აისის მისია, ისტორია და სასწავლო გარემო.',
                'blocks' => AboutPageBlueprint::blocks(),
            ],
            [
                'slug' => 'learning',
                'title' => 'სწავლა',
                'excerpt' => 'საფეხურები და სასწავლო მიდგომა.',
                'seo_title' => 'სწავლა და საფეხურები — აისი',
                'seo_description' => 'აისის სასწავლო საფეხურები და მიდგომა.',
                'blocks' => [
                    [
                        'type' => 'hero',
                        'eyebrow' => 'სწავლა და განვითარება',
                        'heading' => 'ყოველ ეტაპს — თავისი აღმოჩენა.',
                        'body' => 'დაწყებითი, საბაზო და საშუალო საფეხურები — თითოეული მორგებული ასაკობრივ საჭიროებებზე.',
                    ],
                    [
                        'type' => 'programs',
                        'heading' => 'სასწავლო საფეხურები',
                        'items' => [
                            ['title' => 'პირველი ნაბიჯები', 'grade' => 'დაწყებითი საფეხური', 'body' => 'კითხვის სიხარული, პირველი აღმოჩენები და სწავლის სიყვარული.'],
                            ['title' => 'ინტერესების აღმოჩენა', 'grade' => 'საბაზო საფეხური', 'body' => 'კითხვებიდან იდეებამდე — მეტი დამოუკიდებლობა და თანამშრომლობა.'],
                            ['title' => 'საკუთარი გზა', 'grade' => 'საშუალო საფეხური', 'body' => 'გაცნობიერებული არჩევანი და მომავლისთვის მზადება.'],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'school-life',
                'title' => 'სასკოლო ცხოვრება',
                'excerpt' => 'დღეები, რომლებიც გვზრდის.',
                'seo_title' => 'სასკოლო ცხოვრება — აისი',
                'seo_description' => 'აისის სასკოლო ცხოვრება, კლუბები და ღონისძიებები.',
                'blocks' => [
                    [
                        'type' => 'hero',
                        'eyebrow' => 'სასკოლო ცხოვრება',
                        'heading' => 'დღეები, რომლებიც გვზრდის.',
                        'body' => 'პროექტები, კლუბები და ერთად შექმნილი ამბები.',
                    ],
                ],
            ],
            [
                'slug' => 'contact',
                'title' => 'კონტაქტი',
                'excerpt' => 'დაგვიკავშირდით.',
                'seo_title' => 'კონტაქტი — აისი',
                'seo_description' => 'დაუკავშირდით სკოლა აისის გუნდს.',
                'blocks' => [
                    ['type' => 'contact_cta', 'heading' => 'გავიცნოთ ერთმანეთი.', 'body' => 'აირჩიეთ დრო სკოლასთან სასაუბროდ, ან დაგვიკავშირდით პირდაპირ.'],
                ],
            ],
        ] as $page) {
            $aisi->pages()->updateOrCreate(
                ['slug' => $page['slug'], 'locale' => 'ka'],
                [
                    'title' => $page['title'],
                    'excerpt' => $page['excerpt'],
                    'blocks' => $page['blocks'],
                    'status' => Page::STATUS_PUBLISHED,
                    'published_at' => now(),
                    'seo_title' => $page['seo_title'],
                    'seo_description' => $page['seo_description'],
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ],
            );
        }
    }
}
