<?php

namespace App\Domain\Integrations\EduPage;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\StaffMember;
use App\Domain\Academics\Models\StudentGroup;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Timetable\Models\BellPeriod;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\Room;
use App\Domain\Timetable\Models\Subject;
use App\Domain\Timetable\Models\TimetableVersion;
use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The `asc-timetable` type: EduPage's complete regular timetable (the aSc
 * data its own timetable viewer loads), saved as JSON without pupils' names.
 * One file brings, in dependency order: bells, subjects, rooms, the staff
 * directory, classes with their homeroom teacher, the groups lessons are
 * taught to, each teacher's weekly workload, and the weekly lesson grid as
 * a draft timetable version (activated separately, after review).
 *
 * Staff land in the staff directory, not as accounts — the file has no
 * e-mail addresses, and accounts are made by invitation. Only subjects and
 * groups that a lesson uses are imported; EduPage's own housekeeping rows
 * (unused subjects, the "entire class" group) are counted, not copied.
 * A lesson taught to several classes at once becomes one row per class
 * sharing a `joint_key`, so conflicts and worked hours count it once.
 *
 * Source keys are EduPage's internal ids, stable between exports. Context
 * `adopt_same_named` lets subjects and rooms that the school already typed
 * by hand be reused instead of reported as duplicates — never people.
 *
 * Used by EduPageImporter (it relies on that class's upsert/pin/issue).
 */
trait ImportsAscTimetable
{
    /** @var array<string, array<string, mixed>> source id → row, per table */
    private array $asc = [];

    /** @var array<string, int> "{entity}:{source id}" → local id */
    private array $resolved = [];

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    private function importAscTimetable(Tenant $tenant, array $data, AcademicYear $year, array $context): void
    {
        foreach (['periods', 'classrooms', 'classes', 'subjects', 'teachers', 'groups', 'lessons', 'cards'] as $table) {
            if (! is_array($data[$table] ?? null)) {
                throw new InvalidArgumentException("ფაილს „{$table}“ ცხრილი აკლია — EduPage-ის სრული ცხრილი (asc) მოსალოდნელია.");
            }

            $this->asc[$table] = [];

            foreach ($data[$table] as $row) {
                $this->asc[$table][(string) $row['id']] = $row;
            }
        }

        $lessons = $this->asc['lessons'];
        $usedSubjects = array_unique(array_map(fn (array $l) => (string) $l['subjectid'], $lessons));
        $usedGroups = array_unique(array_merge(...array_values(array_map(fn (array $l) => array_map('strval', $l['groupids']), $lessons))));
        $adopt = (bool) ($context['adopt_same_named'] ?? false);
        $source = [
            'periods' => count($this->asc['periods']), 'subjects' => count($this->asc['subjects']), 'subjects_used' => count($usedSubjects),
            'classrooms' => count($this->asc['classrooms']), 'teachers' => count($this->asc['teachers']), 'classes' => count($this->asc['classes']),
            'groups' => count($this->asc['groups']), 'lessons' => count($lessons), 'cards' => count($this->asc['cards']),
            'students_omitted' => (int) ($data['students_omitted'] ?? 0),
        ];

        foreach ($source as $name => $count) {
            $this->counts["source_{$name}"] = $count;
        }

        $this->ascBells($tenant, $year);

        foreach ($usedSubjects as $id) {
            $subject = $this->asc['subjects'][$id] ?? null;

            if ($subject === null) {
                $this->issue(null, "subject:{$id}", 'unresolved_reference', 'subject', 'გაკვეთილის საგანი ფაილში არ არის.');

                continue;
            }

            $name = trim((string) $subject['name']);
            $short = trim((string) $subject['short']);
            $values = ['name' => $name, 'short_name' => $short !== '' && $short !== $name ? $short : null];
            $this->ascUpsert($tenant, 'subject', 0, $id, $values, Subject::class, $adopt,
                fn () => Subject::query()->where('tenant_id', $tenant->id)->where('name', $name)->first(),
                fn () => $this->createFor($tenant, new Subject($values)));
        }

        foreach ($this->asc['classrooms'] as $id => $room) {
            $name = trim((string) $room['name']);
            $this->ascUpsert($tenant, 'room', 0, $id, ['name' => $name], Room::class, $adopt,
                fn () => Room::query()->where('tenant_id', $tenant->id)->where('name', $name)->first(),
                fn () => $this->createFor($tenant, new Room(['name' => $name])));
        }

        foreach ($this->asc['teachers'] as $id => $teacher) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $teacher['name']) ?? '');

            if ($name === '') {
                $this->issue(null, "teacher:{$id}", 'validation', 'name', 'მასწავლებლის სახელი ცარიელია.');

                continue;
            }

            $short = trim((string) $teacher['short']);
            // EduPage's stand-in for a post nobody holds yet.
            $placeholder = in_array(mb_strtolower($name), ['ახალი', 'new', 'vacancy', 'ვაკანსია'], true);
            $values = ['full_name' => $name, 'short_name' => $short !== '' && $short !== $name ? mb_substr($short, 0, 40) : null, 'is_placeholder' => $placeholder];

            if ($placeholder) {
                $this->issue(null, "teacher:{$id}", 'placeholder', 'name', 'მასწავლებლის ადგილი ჯერ შეუვსებელია („ახალი“) — მისი გაკვეთილები ვაკანსიად ჩაიწერება.');
            }

            // People are never adopted by name: always a new directory entry.
            $this->ascUpsert($tenant, 'staff_member', 0, $id, $values, StaffMember::class, false,
                fn () => null,
                fn () => $this->createFor($tenant, new StaffMember($values + ['status' => StaffMember::STATUS_ACTIVE])));
        }

        foreach ($this->asc['classes'] as $id => $class) {
            $name = trim((string) $class['name']);
            $short = trim((string) $class['short']);
            $homerooms = array_values(array_filter(array_map('strval', $class['teacherids'] ?? [])));
            $values = [
                'name' => $name,
                'short_name' => $short !== '' && $short !== $name ? $short : null,
                'grade_level' => preg_match('/^(\d{1,2})/u', $name, $m) === 1 ? (int) $m[1] : null,
                'homeroom_staff_member_id' => $homerooms === [] ? null : ($this->resolved["staff_member:{$homerooms[0]}"] ?? null),
            ];

            if (count($homerooms) > 1) {
                $this->issue(null, "class:{$id}", 'needs_review', 'homeroom', 'კლასს EduPage-ში რამდენიმე დამრიგებელი ჰყავს — მეორე ანგარიშის შექმნის შემდეგ ხელით მიუთითეთ.');
            }

            $this->ascUpsert($tenant, 'class', $year->id, $id, $values, SchoolClass::class, false,
                fn () => SchoolClass::query()->where('tenant_id', $tenant->id)->where('academic_year_id', $year->id)->where('name', $name)->first(),
                fn () => $this->createFor($tenant, new SchoolClass($values + ['academic_year_id' => $year->id])));
        }

        foreach ($usedGroups as $id) {
            $group = $this->asc['groups'][$id] ?? null;

            if ($group === null || ($group['entireclass'] ?? false)) {
                continue; // the whole class: no group row
            }

            $classId = $this->resolved['class:'.$group['classid']] ?? null;

            if ($classId === null) {
                $this->issue(null, "group:{$id}", 'unresolved_reference', 'class', 'ჯგუფის კლასი ვერ ჩაიწერა.');

                continue;
            }

            $values = ['school_class_id' => $classId, 'name' => trim((string) $group['name'])];
            $this->ascUpsert($tenant, 'group', $year->id, $id, $values, StudentGroup::class, false,
                fn () => StudentGroup::query()->where('tenant_id', $tenant->id)->where('school_class_id', $classId)->where('name', $values['name'])->first(),
                fn () => $this->createFor($tenant, new StudentGroup($values)));
        }

        $version = $this->ascVersion($tenant, $year, $context);
        $this->ascWorkloads($tenant, $year);

        if ($version !== null) {
            $this->ascLessons($tenant, $year, $version);
        }
    }

    private function ascBells(Tenant $tenant, AcademicYear $year): void
    {
        foreach ($this->asc['periods'] as $id => $period) {
            $number = (int) $period['period'];
            $start = $this->ascTime((string) $period['starttime']);
            $end = $this->ascTime((string) $period['endtime']);

            if ($number < 1 || $start === null || $end === null || $end <= $start) {
                $this->issue(null, "period:{$id}", 'validation', 'time', 'ზარის დრო ვერ წაიკითხა.');

                continue;
            }

            $values = ['academic_year_id' => $year->id, 'number' => $number, 'starts_at' => $start, 'ends_at' => $end];
            $this->ascUpsert($tenant, 'bell', $year->id, (string) $number, $values, BellPeriod::class, false,
                fn () => BellPeriod::query()->where('tenant_id', $tenant->id)->where('academic_year_id', $year->id)->where('number', $number)->first(),
                fn () => $this->createFor($tenant, new BellPeriod($values)));
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function ascVersion(Tenant $tenant, AcademicYear $year, array $context): ?TimetableVersion
    {
        $validFrom = (string) ($context['valid_from'] ?? '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) !== 1) {
            throw new InvalidArgumentException('ცხრილის მოქმედების დაწყების თარიღი (--valid-from) აუცილებელია.');
        }

        $values = [
            'academic_year_id' => $year->id,
            'label' => (string) ($context['label'] ?? '') ?: 'EduPage — '.$year->name,
            'source_version' => ($context['source_version'] ?? null) ?: null,
            'valid_from' => $validFrom,
            'valid_to' => ($context['valid_to'] ?? null) ?: null,
        ];

        // Key: the validity start — a later EduPage timetable is a new version.
        $version = $this->ascUpsert($tenant, 'timetable_version', $year->id, $validFrom, $values, TimetableVersion::class, false,
            fn () => null,
            fn () => $this->createFor($tenant, new TimetableVersion($values + ['status' => TimetableVersion::STATUS_DRAFT])));

        return $version instanceof TimetableVersion ? $version : null;
    }

    /**
     * Each EduPage lesson × class: who teaches what to whom, and how many
     * lessons a week. This is the workload the hours report plans against.
     */
    private function ascWorkloads(Tenant $tenant, AcademicYear $year): void
    {
        foreach ($this->asc['lessons'] as $id => $lesson) {
            foreach ($this->ascTargets($lesson, "lesson:{$id}") as [$classId, $groupId, $subject, $staff]) {
                $values = [
                    'staff_member_id' => $staff->id,
                    'user_id' => $staff->user_id,
                    'school_class_id' => $classId,
                    'subject_id' => $subject->id,
                    'subject' => $subject->name,
                    'student_group_id' => $groupId,
                    'weekly_hours' => (int) $lesson['count'] * max(1, (int) $lesson['durationperiods']),
                    'joint_key' => count($lesson['classids']) > 1 ? "edupage-lesson:{$id}" : null,
                ];
                $this->ascUpsert($tenant, 'workload', $year->id, "{$id}:{$classId}", $values, TeacherAssignment::class, false,
                    fn () => null,
                    fn () => $this->createFor($tenant, new TeacherAssignment($values)));
            }
        }
    }

    private function ascLessons(Tenant $tenant, AcademicYear $year, TimetableVersion $version): void
    {
        $bells = BellPeriod::query()->where('tenant_id', $tenant->id)->where('academic_year_id', $year->id)->get()->keyBy('number');

        foreach ($this->asc['cards'] as $id => $card) {
            $lesson = $this->asc['lessons'][(string) $card['lessonid']] ?? null;

            if ($lesson === null) {
                $this->issue(null, "card:{$id}", 'unresolved_reference', 'lesson', 'გაკვეთილის ბარათს სასწავლო დატვირთვა არ აქვს.');

                continue;
            }

            $period = (int) $card['period'];
            $duration = max(1, (int) $lesson['durationperiods']);
            $first = $bells->get($period);
            $last = $bells->get($period + $duration - 1);
            $days = $this->ascDays((string) $card['days']);
            $rooms = array_values(array_map('strval', $card['classroomids'] ?? []));
            $roomId = $rooms === [] ? null : ($this->resolved["room:{$rooms[0]}"] ?? null);

            if ($first === null || $last === null || $days === []) {
                $this->issue(null, "card:{$id}", 'validation', 'period', 'გაკვეთილის დღე ან ზარი ვერ განისაზღვრა.');

                continue;
            }

            $targets = $this->ascTargets($lesson, "card:{$id}");
            $joint = count($lesson['classids']) > 1 ? "edupage:{$id}" : null;

            foreach ($days as $day) {
                foreach ($targets as [$classId, $groupId, $subject, $staff]) {
                    $values = [
                        'academic_year_id' => $year->id,
                        'timetable_version_id' => $version->id,
                        'school_class_id' => $classId,
                        'student_group_id' => $groupId,
                        'subject_id' => $subject->id,
                        'staff_member_id' => $staff->id,
                        'teacher_id' => $staff->user_id,
                        'room_id' => $roomId,
                        'day_of_week' => $day,
                        'period_number' => $period,
                        'starts_at' => $first->starts_at,
                        'ends_at' => $last->ends_at,
                        'joint_key' => $joint === null ? null : "{$joint}:{$day}",
                        'status' => Lesson::STATUS_PUBLISHED,
                    ];
                    $this->ascUpsert($tenant, 'lesson', $year->id, "{$id}:{$day}:{$classId}", $values, Lesson::class, false,
                        fn () => null,
                        fn () => $this->createFor($tenant, new Lesson($values)));
                }
            }
        }

        $this->ascConflicts($tenant, $version);
    }

    /**
     * The local class/group/subject/teacher of each class an EduPage lesson
     * is taught to; anything unresolved is reported and that class skipped.
     *
     * @param  array<string, mixed>  $lesson
     * @return list<array{0: int, 1: int|null, 2: Subject, 3: StaffMember}>
     */
    private function ascTargets(array $lesson, string $key): array
    {
        $subjectId = $this->resolved['subject:'.$lesson['subjectid']] ?? null;
        $staffId = $this->resolved['staff_member:'.($lesson['teacherids'][0] ?? '')] ?? null;
        $subject = $subjectId === null ? null : Subject::query()->find($subjectId);
        $staff = $staffId === null ? null : StaffMember::query()->find($staffId);

        if (count($lesson['teacherids']) > 1) {
            $this->issue(null, $key, 'needs_review', 'teacher', 'გაკვეთილს რამდენიმე მასწავლებელი ჰყავს — ჩაიწერა პირველი.');
        }

        if ($subject === null || $staff === null) {
            $this->issue(null, $key, 'unresolved_reference', $subject === null ? 'subject' : 'teacher', 'საგანი ან მასწავლებელი ვერ ჩაიწერა — გაკვეთილი გამოტოვდა.');

            return [];
        }

        $targets = [];

        foreach (array_values($lesson['classids']) as $index => $classSourceId) {
            $classId = $this->resolved["class:{$classSourceId}"] ?? null;
            $groupSourceId = (string) ($lesson['groupids'][$index] ?? '');
            $group = $this->asc['groups'][$groupSourceId] ?? null;
            $groupId = $group === null || ($group['entireclass'] ?? false) ? null : ($this->resolved["group:{$groupSourceId}"] ?? null);

            if ($classId === null || ($group !== null && ! ($group['entireclass'] ?? false) && $groupId === null)) {
                $this->issue(null, $key, 'unresolved_reference', 'class', 'კლასი ან ჯგუფი ვერ ჩაიწერა — ამ კლასისთვის გამოტოვდა.');

                continue;
            }

            $targets[] = [$classId, $groupId, $subject, $staff];
        }

        return $targets;
    }

    /**
     * Double bookings inside the imported version, reported (EduPage's own
     * grid can hold them, e.g. after a manual edit): a teacher in two places
     * at once — a joint lesson is one place — and a class whose whole-class
     * lesson overlaps another lesson of the same class.
     */
    private function ascConflicts(Tenant $tenant, TimetableVersion $version): void
    {
        $rows = Lesson::query()->where('tenant_id', $tenant->id)->where('timetable_version_id', $version->id)
            ->get(['id', 'school_class_id', 'student_group_id', 'staff_member_id', 'day_of_week', 'period_number', 'joint_key']);

        foreach ($rows->groupBy(fn (Lesson $l) => "{$l->staff_member_id}|{$l->day_of_week}|{$l->period_number}") as $slot => $same) {
            if ($same->map(fn (Lesson $l) => $l->joint_key ?? "lesson:{$l->id}")->unique()->count() > 1) {
                $this->issue(null, "teacher-slot:{$slot}", 'conflict', 'teacher', 'მასწავლებელს ერთ დროს რამდენიმე გაკვეთილი აქვს.');
            }
        }

        foreach ($rows->groupBy(fn (Lesson $l) => "{$l->school_class_id}|{$l->day_of_week}|{$l->period_number}") as $slot => $same) {
            $groups = $same->pluck('student_group_id');

            if ($same->count() > 1 && ($groups->contains(null) || $groups->duplicates()->isNotEmpty())) {
                $this->issue(null, "class-slot:{$slot}", 'conflict', 'class', 'კლასს ერთ დროს რამდენიმე გაკვეთილი აქვს.');
            }
        }

        $this->counts['conflicts'] = collect($this->issues)->where('kind', 'conflict')->count();
    }

    /**
     * Upsert one source record and remember its local id. With `$adopt`, an
     * existing record of the same name that no import created is pinned and
     * reused (subjects and rooms only) instead of reported as a duplicate.
     *
     * @param  array<string, mixed>  $values
     * @param  class-string<Model>  $model
     * @param  Closure(): ?Model  $sameNamed
     * @param  Closure(): Model  $create
     */
    private function ascUpsert(Tenant $tenant, string $entity, int $scopeId, string $id, array $values, string $model, bool $adopt, Closure $sameNamed, Closure $create): ?Model
    {
        if ($adopt && $this->findMap($tenant, $entity, $scopeId, $id) === null && ($existing = $sameNamed()) !== null) {
            $this->pin($tenant, $entity, $scopeId, $id, $existing, $this->hash($values), 0);
            $this->counts['adopted'] = ($this->counts['adopted'] ?? 0) + 1;
            $this->resolved["{$entity}:{$id}"] = (int) $existing->getKey();

            return $existing;
        }

        $this->counts['rows']++;
        $target = $this->upsert(
            tenant: $tenant,
            entity: $entity,
            scopeId: $scopeId,
            key: $id,
            row: 0,
            values: $values,
            model: $model,
            sameNamed: fn () => $sameNamed() !== null,
            create: $create,
        );
        $this->counts["{$entity}_{$this->lastOutcome}"] = ($this->counts["{$entity}_{$this->lastOutcome}"] ?? 0) + 1;

        if ($target !== null) {
            $this->resolved["{$entity}:{$id}"] = (int) $target->getKey();
        }

        return $target;
    }

    /**
     * EduPage day masks: "01000" = Tuesday. Monday is 1.
     *
     * @return list<int>
     */
    private function ascDays(string $mask): array
    {
        $days = [];

        foreach (str_split($mask) as $index => $bit) {
            if ($bit === '1') {
                $days[] = $index + 1;
            }
        }

        return $days;
    }

    private function ascTime(string $value): ?string
    {
        return preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m) === 1 ? sprintf('%02d:%02d:00', $m[1], $m[2]) : null;
    }
}
