<?php

namespace App\Console\Commands;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds a clearly marked, removable test school inside a real tenant so
 * every role can be tried on a live site before real people are invited:
 * classes 1ა–10დ with three account-less pupils each, 40 test teachers, a
 * conflict-free weekly timetable and one login per role.
 *
 * Everything it creates is identifiable: accounts use the reserved
 * `test-{tenant}.invalid` mail domain (nothing can be delivered there, so no
 * real person is ever e-mailed), pupils are named "(ტესტი)", and the ids of
 * created classes are kept in a registry file so `--remove` deletes exactly
 * this data and nothing the school made. Existing classes with the same
 * name are never touched. Passwords are printed once and never stored.
 */
class SeedTestSchool extends Command
{
    protected $signature = 'school:test-fixtures
        {--tenant= : Tenant slug (required)}
        {--remove : Delete everything a previous run created}
        {--rotate-passwords : Print new passwords for the per-role test logins}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Create (or --remove) a marked test school: classes 1ა–10დ, test pupils and teachers, a weekly timetable and one login per role';

    private const SECTIONS = ['ა', 'ბ', 'გ', 'დ'];

    private const SUBJECTS = [
        'ქართული ენა', 'მათემატიკა', 'ინგლისური ენა', 'ბუნებისმეტყველება', 'ისტორია',
        'გეოგრაფია', 'ფიზიკური აღზრდა', 'ხელოვნება', 'მუსიკა', 'ინფორმატიკა',
    ];

    /** Wall-clock slots in the school's timezone. */
    private const PERIODS = [
        ['09:00:00', '09:45:00'], ['09:55:00', '10:40:00'], ['10:50:00', '11:35:00'],
        ['11:55:00', '12:40:00'], ['12:50:00', '13:35:00'], ['13:45:00', '14:30:00'],
    ];

    private const TEACHER_COUNT = 40;

    /** One login per tenant role; the teacher login is test teacher 01. */
    private const ROLE_LOGINS = [
        TenantMembership::ROLE_ADMIN => 'admin',
        TenantMembership::ROLE_DIRECTOR => 'director',
        TenantMembership::ROLE_ACADEMIC_MANAGER => 'academic-manager',
        TenantMembership::ROLE_ACCOUNTANT => 'accountant',
        TenantMembership::ROLE_EDITOR => 'editor',
        TenantMembership::ROLE_LIBRARIAN => 'librarian',
        TenantMembership::ROLE_GUARDIAN => 'parent',
        TenantMembership::ROLE_STUDENT => 'student',
    ];

    private const ROLE_LABELS = [
        TenantMembership::ROLE_ADMIN => 'ადმინისტრატორი',
        TenantMembership::ROLE_DIRECTOR => 'დირექტორი',
        TenantMembership::ROLE_ACADEMIC_MANAGER => 'სასწავლო მენეჯერი',
        TenantMembership::ROLE_ACCOUNTANT => 'ბუღალტერი',
        TenantMembership::ROLE_EDITOR => 'რედაქტორი',
        TenantMembership::ROLE_LIBRARIAN => 'ბიბლიოთეკარი',
        TenantMembership::ROLE_GUARDIAN => 'მშობელი',
        TenantMembership::ROLE_STUDENT => 'მოსწავლე',
        TenantMembership::ROLE_TEACHER => 'მასწავლებელი',
    ];

    public function handle(AuditLogger $auditLogger): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();

        if (! $tenant) {
            $this->error('--tenant=<slug> is required and must exist — this command never guesses the school.');

            return self::FAILURE;
        }

        $registry = $this->readRegistry($tenant);

        if ($this->option('remove')) {
            return $this->remove($tenant, $registry, $auditLogger);
        }

        if ($this->option('rotate-passwords')) {
            if ($registry === null) {
                $this->error('No test school exists for this tenant yet.');

                return self::FAILURE;
            }

            $this->printLogins($this->resetLoginPasswords($tenant));

            return self::SUCCESS;
        }

        if ($registry !== null) {
            $this->error('A test school already exists for this tenant. Use --rotate-passwords to get new passwords, or --remove first.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Create the test school in tenant \"{$tenant->slug}\"?")) {
            $this->warn('Aborted — nothing was created.');

            return self::SUCCESS;
        }

        [$registry, $skipped, $lessonCount] = DB::transaction(fn () => $this->create($tenant));
        $this->writeRegistry($tenant, $registry);
        $logins = $this->resetLoginPasswords($tenant);

        $auditLogger->record(
            tenantId: $tenant->id,
            action: 'test_fixtures.created',
            subject: $tenant,
            actorId: null,
            meta: [
                'classes' => count($registry['class_ids']),
                'lessons' => $lessonCount,
                'teachers' => self::TEACHER_COUNT,
                'skipped_existing_classes' => $skipped,
                'performed_via' => 'school:test-fixtures',
            ],
        );

        $this->info(sprintf('Created %d classes, %d pupils, %d teachers, %d lessons.', count($registry['class_ids']), count($registry['class_ids']) * 3 + 1, self::TEACHER_COUNT, $lessonCount));

        if ($skipped !== []) {
            $this->warn('Left untouched (a class with this name already exists): '.implode(', ', $skipped));
        }

        $disabled = FeatureEntitlement::query()->where('tenant_id', $tenant->id)->where('is_enabled', false)->pluck('feature')->all();

        if ($disabled !== []) {
            $this->warn('Switched-off modules (their pages stay closed until enabled): '.implode(', ', $disabled));
        }

        $this->printLogins($logins);

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>, 2: int}
     */
    private function create(Tenant $tenant): array
    {
        $registry = ['class_ids' => [], 'academic_year_id' => null];

        $year = $tenant->academicYears()->where('is_current', true)->first();

        if (! $year) {
            $year = $tenant->academicYears()->create([
                'name' => '2026-2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-15',
                'is_current' => true,
            ]);
            $registry['academic_year_id'] = $year->id;
        }

        $subjectIds = [];
        foreach (self::SUBJECTS as $name) {
            $subjectIds[] = $tenant->subjects()->firstOrCreate(['name' => $name])->id;
        }

        $teachers = [];
        foreach (range(1, self::TEACHER_COUNT) as $n) {
            $number = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $teachers[] = $this->testUser($tenant, "teacher{$number}", "ტესტ მასწავლებელი {$number}", TenantMembership::ROLE_TEACHER);
        }

        $classes = [];
        $skipped = [];
        foreach (range(1, 10) as $grade) {
            foreach (self::SECTIONS as $section) {
                $name = "{$grade}{$section}";

                if ($tenant->schoolClasses()->where('academic_year_id', $year->id)->where('name', $name)->exists()) {
                    $skipped[] = $name;

                    continue;
                }

                $class = $tenant->schoolClasses()->create([
                    'academic_year_id' => $year->id,
                    'name' => $name,
                    'homeroom_teacher_id' => $teachers[count($classes) % self::TEACHER_COUNT]->id,
                ]);
                $classes[] = ['model' => $class, 'grade' => $grade];
                $registry['class_ids'][] = $class->id;

                foreach (range(1, 3) as $i) {
                    $tenant->students()->create([
                        'school_class_id' => $class->id,
                        'first_name' => 'მოსწავლე',
                        'last_name' => "{$name}-{$i} (ტესტი)",
                        'is_active' => true,
                    ]);
                }
            }
        }

        // Teacher index (class + period + day) mod 40 is different for every
        // class in the same slot, so no teacher is ever double-booked; each
        // teacher always teaches the same subject.
        $rows = [];
        $assignments = [];
        $now = now();
        foreach ($classes as $c => $entry) {
            $periods = $entry['grade'] <= 4 ? 4 : ($entry['grade'] <= 6 ? 5 : 6);

            foreach (range(1, 5) as $day) {
                foreach (array_slice(self::PERIODS, 0, $periods) as $p => [$startsAt, $endsAt]) {
                    $t = ($c + $p + $day) % self::TEACHER_COUNT;
                    $subject = $t % count(self::SUBJECTS);
                    $rows[] = [
                        'tenant_id' => $tenant->id,
                        'academic_year_id' => $year->id,
                        'school_class_id' => $entry['model']->id,
                        'subject_id' => $subjectIds[$subject],
                        'teacher_id' => $teachers[$t]->id,
                        'room_id' => null,
                        'day_of_week' => $day,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'status' => Lesson::STATUS_PUBLISHED,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $assignments[$teachers[$t]->id.'|'.$entry['model']->id] = [$teachers[$t]->id, $entry['model']->id, self::SUBJECTS[$subject]];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Lesson::query()->insert($chunk);
        }

        foreach ($assignments as [$userId, $classId, $subject]) {
            $tenant->teacherAssignments()->create(['user_id' => $userId, 'school_class_id' => $classId, 'subject' => $subject]);
        }

        // The per-role logins; the pupil and parent belong to the first class.
        $logins = [];
        foreach (self::ROLE_LOGINS as $role => $local) {
            $logins[$role] = $this->testUser($tenant, $local, 'ტესტ '.self::ROLE_LABELS[$role], $role);
        }

        if ($classes !== []) {
            $student = $tenant->students()->create([
                'school_class_id' => $classes[0]['model']->id,
                'user_id' => $logins[TenantMembership::ROLE_STUDENT]->id,
                'first_name' => 'ტესტ',
                'last_name' => 'მოსწავლე',
                'is_active' => true,
            ]);

            $tenant->guardianLinks()->create([
                'user_id' => $logins[TenantMembership::ROLE_GUARDIAN]->id,
                'student_id' => $student->id,
                'can_view_academic' => true,
                'can_view_financial' => true,
                'can_pickup' => true,
                'can_receive_notifications' => true,
                'is_active' => true,
            ]);
        }

        return [$registry, $skipped, count($rows)];
    }

    private function testUser(Tenant $tenant, string $local, string $name, string $role): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => "{$local}@{$this->mailDomain($tenant)}"],
            ['name' => $name, 'password' => Str::password(32)],
        );
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        TenantMembership::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role],
            ['is_active' => true],
        );

        return $user;
    }

    /**
     * @return list<array{role: string, email: string, password: string}>
     */
    private function resetLoginPasswords(Tenant $tenant): array
    {
        $logins = [];
        $locals = [TenantMembership::ROLE_TEACHER => 'teacher01'] + self::ROLE_LOGINS;

        foreach ($locals as $role => $local) {
            $user = User::query()->where('email', "{$local}@{$this->mailDomain($tenant)}")->first();

            if (! $user) {
                continue;
            }

            $password = Str::password(16, symbols: false);
            $user->password = $password;
            $user->setRememberToken(Str::random(60));
            $user->save();
            $logins[] = ['role' => self::ROLE_LABELS[$role], 'email' => $user->email, 'password' => $password];
        }

        return $logins;
    }

    /**
     * @param  list<array{role: string, email: string, password: string}>  $logins
     */
    private function printLogins(array $logins): void
    {
        $this->newLine();
        $this->info('Test logins — shown once, not stored anywhere:');
        $this->table(['role', 'email', 'password'], $logins);
        $this->warn('Admin, director and accountant must set up two-factor authentication at first sign-in.');
    }

    /**
     * @param  array<string, mixed>|null  $registry
     */
    private function remove(Tenant $tenant, ?array $registry, AuditLogger $auditLogger): int
    {
        if ($registry === null) {
            $this->error('No test school registry for this tenant — nothing to remove.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Delete the test school in tenant \"{$tenant->slug}\"?")) {
            $this->warn('Aborted — nothing was deleted.');

            return self::SUCCESS;
        }

        $users = User::query()->where('email', 'like', '%@'.$this->mailDomain($tenant))->get();

        DB::transaction(function () use ($tenant, $registry, $users) {
            // Pupils first (a class delete only nulls their class); their
            // guardian links, grades etc. cascade. Classes then cascade to
            // their lessons and teacher assignments.
            $tenant->students()->whereIn('school_class_id', $registry['class_ids'])->delete();
            $tenant->students()->whereIn('user_id', $users->pluck('id'))->delete();
            SchoolClass::query()->where('tenant_id', $tenant->id)->whereIn('id', $registry['class_ids'])->delete();
            TenantMembership::query()->where('tenant_id', $tenant->id)->whereIn('user_id', $users->pluck('id'))->delete();

            if ($registry['academic_year_id'] !== null) {
                $year = AcademicYear::query()->where('tenant_id', $tenant->id)->whereKey((int) $registry['academic_year_id'])->first();

                if ($year && ! $year->schoolClasses()->exists()) {
                    $year->delete();
                }
            }
        });

        $kept = 0;
        foreach ($users as $user) {
            try {
                $user->delete();
            } catch (Throwable) {
                // Still referenced elsewhere (e.g. a message they sent): it
                // has no membership left, so it can no longer reach the
                // school; make the password unusable as well.
                $user->password = Str::password(64);
                $user->save();
                $kept++;
            }
        }

        Storage::disk('local')->delete($this->registryPath($tenant));

        $auditLogger->record(
            tenantId: $tenant->id,
            action: 'test_fixtures.removed',
            subject: $tenant,
            actorId: null,
            meta: ['classes' => count($registry['class_ids']), 'users' => $users->count(), 'users_kept_without_access' => $kept, 'performed_via' => 'school:test-fixtures'],
        );

        $this->info(sprintf('Removed %d classes and %d test accounts (%d kept without any access because other records point to them).', count($registry['class_ids']), $users->count() - $kept, $kept));

        return self::SUCCESS;
    }

    private function mailDomain(Tenant $tenant): string
    {
        return "test-{$tenant->slug}.invalid";
    }

    private function registryPath(Tenant $tenant): string
    {
        return "test-fixtures/{$tenant->slug}.json";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readRegistry(Tenant $tenant): ?array
    {
        $raw = Storage::disk('local')->get($this->registryPath($tenant));
        $data = $raw === null ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $registry
     */
    private function writeRegistry(Tenant $tenant, array $registry): void
    {
        Storage::disk('local')->put($this->registryPath($tenant), (string) json_encode($registry));
    }
}
