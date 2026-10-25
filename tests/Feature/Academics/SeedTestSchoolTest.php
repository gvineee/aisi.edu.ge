<?php

namespace Tests\Feature\Academics;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeedTestSchoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_marked_test_school_and_removes_only_what_it_made(): void
    {
        Storage::fake('local');
        $tenant = Tenant::query()->where('slug', 'test-tenant')->firstOrFail();
        // A class the school made itself: never touched, never removed.
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '3ბ']);
        $existingClasses = SchoolClass::query()->where('tenant_id', $tenant->id)->count();
        $existingLessons = Lesson::query()->where('tenant_id', $tenant->id)->count();

        $this->assertSame(0, Artisan::call('school:test-fixtures', ['--tenant' => 'test-tenant', '--force' => true]));
        $output = Artisan::output();

        $created = SchoolClass::query()->where('tenant_id', $tenant->id)->whereIn('name', ['1ა', '5გ', '10დ'])->count();
        $this->assertSame(3, $created);
        $this->assertSame($existingClasses + 39, SchoolClass::query()->where('tenant_id', $tenant->id)->count());
        $this->assertStringContainsString('3ბ', $output);
        $this->assertSame(117, Student::query()->where('tenant_id', $tenant->id)->where('last_name', 'like', '%(ტესტი)')->count());
        $this->assertSame(1, Student::query()->where('tenant_id', $tenant->id)->where('first_name', 'ტესტ')->count());
        $this->assertSame(48, User::query()->where('email', 'like', '%@test-test-tenant.invalid')->count());

        // Grades 1–4 four lessons a day, 5–6 five, 7–10 six: (15·4 + 8·5 + 16·6) · 5 days, 3ბ skipped.
        $this->assertSame($existingLessons + 980, Lesson::query()->where('tenant_id', $tenant->id)->count());

        $doubleBooked = Lesson::query()
            ->where('tenant_id', $tenant->id)
            ->select('teacher_id', 'day_of_week', 'starts_at', DB::raw('count(*) as n'))
            ->groupBy('teacher_id', 'day_of_week', 'starts_at')
            ->havingRaw('count(*) > 1')
            ->count();
        $this->assertSame(0, $doubleBooked);

        // The printed parent login really signs in and sees the test pupil.
        $this->assertSame(1, preg_match('/parent@test-test-tenant\.invalid\s*\|\s*([A-Za-z0-9]{16})/', $output, $m));
        $this->post('/login', ['email' => 'parent@test-test-tenant.invalid', 'password' => $m[1]]);
        $this->assertAuthenticated();
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->where('children.0.name', 'ტესტ მოსწავლე')
            ->where('children.0.className', '1ა'));
        auth()->logout();

        // A second run never duplicates.
        $this->assertSame(1, Artisan::call('school:test-fixtures', ['--tenant' => 'test-tenant', '--force' => true]));

        $this->assertSame(0, Artisan::call('school:test-fixtures', ['--tenant' => 'test-tenant', '--remove' => true, '--force' => true]));
        $this->assertSame($existingClasses, SchoolClass::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame($existingLessons, Lesson::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, Student::query()->where('tenant_id', $tenant->id)->count());
        $this->assertTrue($tenant->schoolClasses()->where('name', '3ბ')->exists());
        $this->assertTrue($year->fresh()->is_current);
        $this->assertSame(0, User::query()->where('email', 'like', '%@test-test-tenant.invalid')->count());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'action' => 'test_fixtures.removed']);
    }

    public function test_it_requires_an_existing_tenant(): void
    {
        $this->assertSame(1, Artisan::call('school:test-fixtures', ['--force' => true]));
        $this->assertSame(1, Artisan::call('school:test-fixtures', ['--tenant' => 'nope', '--force' => true]));
    }
}
