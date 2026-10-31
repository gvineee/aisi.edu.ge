<?php
// Synthetic accounts and data for scripts/qa/acceptance.mjs. Refuses to run
// unless the default connection is a SQLite file whose path contains "qa".
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || ! str_contains((string) config('database.connections.sqlite.database'), 'qa')) {
    throw new RuntimeException('QA database required');
}

use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;

$tenant = Tenant::where('slug', 'aisi')->firstOrFail();
foreach (['finance', 'library'] as $feature) {
    FeatureEntitlement::updateOrCreate(['tenant_id' => $tenant->id, 'feature' => $feature], ['is_enabled' => true]);
}
// Test-only TOTP secret for local synthetic accounts.
$mfa = fn (User $u) => $u->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['qa-recovery'])), 'two_factor_confirmed_at' => now()])->save();
foreach (['academic_manager', 'accountant', 'editor', 'librarian'] as $role) {
    $user = User::factory()->create(['name' => 'QA '.$role, 'email' => $role.'@aisi.test']);
    TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);
}
foreach (['admin', 'director', 'accountant'] as $role) {
    $mfa(User::where('email', $role.'@aisi.test')->firstOrFail());
}
$operator = User::factory()->create(['name' => 'QA პლატფორმის ოპერატორი', 'email' => 'platform@aisi.test']);
$mfa($operator);
App\Domain\Platform\Models\PlatformOperator::create(['user_id' => $operator->id, 'is_active' => true]);
User::factory()->create(['name' => 'QA ახალი მომხმარებელი', 'email' => 'newcomer@aisi.test']);

// 25+ library resources (A02).
for ($i = 1; $i <= 27; $i++) {
    $r = new App\Domain\Library\Models\LibraryResource(['title' => sprintf('QA წიგნი %02d', $i), 'author' => 'QA ავტორი', 'grade' => 'VI', 'subject' => $i % 2 ? 'ქართული' : 'მათემატიკა', 'access_scope' => $i === 27 ? 'digital' : 'loan', 'external_url' => $i === 27 ? 'https://example.org/qa-digital-book' : null]);
    $r->tenant_id = $tenant->id;
    $r->save();
}

// One single-seat visit slot (A01).
$director = User::where('email', 'director@aisi.test')->firstOrFail();
$slot = new App\Domain\Admissions\Models\AdmissionVisitSlot(['starts_at' => now()->addDays(3)->setTime(8, 0), 'ends_at' => now()->addDays(3)->setTime(9, 0), 'capacity' => 1, 'booked_count' => 0, 'label' => 'QA ღია კარი', 'status' => 'open', 'created_by' => $director->id]);
$slot->tenant_id = $tenant->id;
$slot->save();

// Teacher → parent conversation (A04) and a date-only invoice (A15).
$teacher = User::where('email', 'teacher@aisi.test')->firstOrFail();
$parent = User::where('email', 'parent@aisi.test')->firstOrFail();
app(App\Domain\Communications\Actions\StartConversation::class)->handle($tenant->id, $teacher, 'teacher', $parent, 'guardian', 'QA საკლასო შეხვედრა', str_repeat('სინთეზური შეტყობინება ტესტისთვის. ', 8));
$student = App\Domain\Academics\Models\Student::where('tenant_id', $tenant->id)->firstOrFail();
$invoices = app(App\Domain\Finance\Actions\ManageInvoices::class);
$invoices->issue($invoices->accountFor($student), [['description' => 'QA სწავლის საფასური', 'quantity' => 1, 'unit_minor' => 10000]], Illuminate\Support\Carbon::parse('2026-11-10', 'Asia/Tbilisi'), User::where('email', 'accountant@aisi.test')->firstOrFail());

// A second single-seat slot for the "two families, last seat" race.
$race = new App\Domain\Admissions\Models\AdmissionVisitSlot(['starts_at' => now()->addDays(4)->setTime(8, 0), 'ends_at' => now()->addDays(4)->setTime(9, 0), 'capacity' => 1, 'booked_count' => 0, 'label' => 'QA კონკურენტული', 'status' => 'open', 'created_by' => $director->id]);
$race->tenant_id = $tenant->id;
$race->save();

echo "QA fixture ready\n";
