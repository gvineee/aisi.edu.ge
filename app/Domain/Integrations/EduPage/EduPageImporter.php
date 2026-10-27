<?php

namespace App\Domain\Integrations\EduPage;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\ClassStaffRole;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Governance\AuditLogger;
use App\Domain\Integrations\Models\ImportBatch;
use App\Domain\Integrations\Models\ImportIdentityMap;
use App\Domain\Integrations\Models\ImportIssue;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Room;
use App\Domain\Timetable\Models\Subject;
use App\Models\User;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Imports EduPage exports into one tenant (CLAUDE-EDUPAGE-MIGRATION.md).
 *
 * Dry-run by default: the whole import runs inside a transaction that is
 * rolled back, so the preview counts are exactly what an apply would do,
 * and only the batch record and its quarantined issues are kept.
 *
 * Rules that hold for every type:
 * - Every source record is pinned in `import_identity_maps` by tenant +
 *   source + type + year scope + key; a re-run updates, never duplicates.
 * - An unchanged source row is left alone. A changed row updates its target
 *   only if nobody edited the target after the last import; otherwise the
 *   local edit is kept and the row is quarantined as a conflict.
 * - Nothing is merged by name. A same-named record the import did not
 *   create is reported as a duplicate, not adopted. People are never
 *   matched by name at all: staff link to an existing account only through
 *   an exact e-mail address, and accounts are never created here — staff
 *   without one are listed for an invitation. No passwords are imported.
 * - Issues hold row numbers, source keys and field names only, never the
 *   raw values.
 */
class EduPageImporter
{
    use ImportsAscStudents;
    use ImportsAscTimetable;

    public const SOURCE = 'edupage';

    public const TYPES = ['rooms', 'subjects', 'staff', 'classes', 'timetable-summary', 'asc-timetable', 'asc-students'];

    /** Types whose source key is scoped to one academic year. */
    private const YEAR_SCOPED = ['classes', 'timetable-summary', 'asc-timetable', 'asc-students'];

    /** Recognised header spellings per field (compared case-insensitively). */
    private const COLUMNS = [
        'rooms' => [
            'key' => ['id', 'კოდი'],
            'name' => ['სახელი', 'name', 'ოთახი'],
            'short_name' => ['მოკლე სახელი', 'short name', 'short'],
        ],
        'subjects' => [
            'key' => ['id', 'კოდი'],
            'name' => ['სახელი', 'name', 'საგანი'],
            'short_name' => ['მოკლე სახელი', 'short name', 'short'],
        ],
        'staff' => [
            'key' => ['id', 'კოდი'],
            'name' => ['სრული სახელი', 'full name', 'name', 'მასწავლებელი'],
            'first_name' => ['სახელი', 'first name', 'firstname'],
            'last_name' => ['გვარი', 'last name', 'lastname', 'surname'],
            'email' => ['ელფოსტა', 'ელ. ფოსტა', 'ელ.ფოსტა', 'e-mail', 'email'],
        ],
        'classes' => [
            'key' => ['id', 'კოდი'],
            'name' => ['სახელი', 'name', 'კლასი'],
            'short_name' => ['მოკლე სახელი', 'short name', 'short'],
            'grade_level' => ['საფეხური', 'grade'],
            'room' => ['ოთახი', 'room', 'classroom'],
            'homeroom' => ['დამრიგებელი', 'class teacher', 'form teacher'],
            'co_homeroom' => ['დამრიგებელი 2', 'class teacher 2', 'form teacher 2'],
            'student_count' => ['მოსწავლეთა რაოდენობა', 'მოსწავლეები', 'students'],
        ],
    ];

    private const ROMAN = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10, 'XI' => 11, 'XII' => 12];

    private const DAYS = ['ორშაბათი' => 1, 'სამშაბათი' => 2, 'ოთხშაბათი' => 3, 'ხუთშაბათი' => 4, 'პარასკევი' => 5, 'შაბათი' => 6, 'კვირა' => 7];

    private ImportBatch $batch;

    /** @var array<string, array<string, int>> source keys already handled in this run → row */
    private array $seenKeys = [];

    /** What the last upsert did: created | updated | unchanged | skipped. */
    private string $lastOutcome = 'skipped';

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<array{row_number: int|null, source_key: string|null, kind: string, field: string|null, message: string}> */
    private array $issues = [];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $context  export scope / version / validity as the operator states it
     */
    public function run(Tenant $tenant, string $type, string $path, ?AcademicYear $year, bool $apply, ?User $actor = null, array $context = []): ImportBatch
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("უცნობი ტიპი: {$type}");
        }

        if (in_array($type, self::YEAR_SCOPED, true) && $year === null) {
            throw new InvalidArgumentException('ამ ტიპს სასწავლო წელი სჭირდება (--year).');
        }

        if ($year !== null && $year->tenant_id !== $tenant->id) {
            throw new InvalidArgumentException('სასწავლო წელი ამ სკოლას არ ეკუთვნის.');
        }

        if ($type === 'timetable-summary' && $apply) {
            throw new InvalidArgumentException('კლასების შეჯამებული ცხრილი მასწავლებელს, ოთახსა და ჯგუფს არ შეიცავს — მისი იმპორტი მხოლოდ preview-ია.');
        }

        $isAsc = in_array($type, ['asc-timetable', 'asc-students'], true);
        $table = $isAsc ? null : SourceTable::fromFile($path);
        $asc = $isAsc ? json_decode((string) file_get_contents($path), true) : null;

        if ($isAsc && ! is_array($asc)) {
            throw new InvalidArgumentException('ფაილი JSON არ არის.');
        }

        $this->counts = ['rows' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $this->issues = [];
        $this->seenKeys = [];

        $this->batch = ImportBatch::query()->create([
            'tenant_id' => $tenant->id,
            'source' => self::SOURCE,
            'type' => $type,
            'academic_year_id' => $year?->id,
            'mode' => $apply ? ImportBatch::MODE_APPLY : ImportBatch::MODE_DRY_RUN,
            'status' => 'running',
            'file_name' => basename($path),
            'file_sha256' => hash_file('sha256', $path) ?: '',
            'context' => $context ?: null,
            'created_by' => $actor?->id,
        ]);

        DB::beginTransaction();

        try {
            if (is_array($asc) && $type === 'asc-students') {
                $this->importAscStudents($tenant, $asc, $year);
            } elseif (is_array($asc)) {
                $this->importAscTimetable($tenant, $asc, $year, $context);
            } elseif ($table !== null) {
                match ($type) {
                    'rooms' => $this->importNamed($tenant, $table, 'rooms', 'room', Room::class),
                    'subjects' => $this->importNamed($tenant, $table, 'subjects', 'subject', Subject::class),
                    'staff' => $this->importStaff($tenant, $table),
                    'classes' => $this->importClasses($tenant, $table, $year),
                    default => $this->previewTimetableSummary($tenant, $table, $year),
                };
            }

            $apply ? DB::commit() : DB::rollBack();
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->batch->update(['status' => 'failed', 'counts' => $this->counts]);

            throw $exception;
        }

        foreach ($this->issues as $issue) {
            ImportIssue::query()->create($issue + ['tenant_id' => $tenant->id, 'import_batch_id' => $this->batch->id]);
        }

        $this->counts['issues'] = count($this->issues);
        $this->batch->update(['status' => $apply ? 'applied' : 'previewed', 'counts' => $this->counts]);

        $this->auditLogger->record($tenant->id, $apply ? 'import.applied' : 'import.previewed', $this->batch, $actor?->id, [
            'source' => self::SOURCE,
            'type' => $type,
            'counts' => $this->counts,
        ]);

        return $this->batch;
    }

    /**
     * Rooms and subjects: a name, an optional short name, an optional id.
     *
     * @param  class-string<Room|Subject>  $model
     */
    private function importNamed(Tenant $tenant, SourceTable $table, string $type, string $entity, string $model): void
    {
        foreach ($this->mappedRecords($table, $type, [['name']]) as [$row, $fields]) {
            $name = $fields['name'];

            if ($name === '') {
                $this->issue($row, null, 'validation', 'name', 'სახელი ცარიელია.');

                continue;
            }

            $values = $type === 'rooms' ? ['name' => $name] : ['name' => $name, 'short_name' => $fields['short_name'] ?: null];

            $this->upsert(
                tenant: $tenant,
                entity: $entity,
                scopeId: 0,
                key: $fields['key'] ?: $name,
                row: $row,
                values: $values,
                model: $model,
                sameNamed: fn () => $model::query()->where('tenant_id', $tenant->id)->where('name', $name)->exists(),
                create: fn () => $this->createFor($tenant, new $model($values)),
            );
        }
    }

    /**
     * Staff never create accounts. A row links to an existing member of this
     * school only through an exact e-mail address; everyone else is listed
     * for an invitation. A display name that is unique in the file is kept
     * as a label so the classes import can resolve „დამრიგებელი“ — a name
     * that two rows share is never used.
     */
    private function importStaff(Tenant $tenant, SourceTable $table): void
    {
        $records = $this->mappedRecords($table, 'staff', [['name'], ['first_name', 'last_name']]);
        $labels = [];
        $keys = [];

        foreach ($records as [$row, $fields]) {
            $label = $this->staffLabel($fields);
            $key = $fields['key'] ?: $label;

            if ($label !== '') {
                $labels[$label] = ($labels[$label] ?? 0) + 1;
            }

            if ($key !== '') {
                $keys[$key] = ($keys[$key] ?? 0) + 1;
            }
        }

        foreach ($records as [$row, $fields]) {
            $label = $this->staffLabel($fields);
            $key = $fields['key'] ?: $label;

            if ($key === '') {
                $this->issue($row, null, 'validation', 'name', 'სახელი ცარიელია.');

                continue;
            }

            // Without a source id two people with one name cannot be told
            // apart, so neither is linked.
            if ($keys[$key] > 1) {
                $this->issue($row, $fields['key'] ?: null, 'duplicate', $fields['key'] !== '' ? 'key' : 'name', 'იგივე იდენტიფიკატორი ფაილში რამდენჯერმეა — ჩანაწერი არ დაუკავშირდა.');
                $this->counts['skipped']++;

                continue;
            }

            $email = mb_strtolower(trim($fields['email']));
            $user = $email === '' ? null : User::query()
                ->where('email', $email)
                ->whereIn('id', TenantMembership::query()->where('tenant_id', $tenant->id)->select('user_id'))
                ->first();

            if ($user === null) {
                $this->issue($row, $fields['key'] ?: null, 'needs_invitation', 'email', $email === ''
                    ? 'ელფოსტა არ არის — ანგარიში მოწვევით უნდა შეიქმნას.'
                    : 'ამ ელფოსტით სკოლის წევრი ვერ მოიძებნა — საჭიროა მოწვევა.');
                $this->counts['skipped']++;

                continue;
            }

            $this->pin($tenant, 'staff', 0, $key, $user, $this->hash(['email' => $email]), $row);
            $this->counts['linked'] = ($this->counts['linked'] ?? 0) + 1;

            if ($label === '' || $labels[$label] > 1) {
                if ($label !== '') {
                    $this->issue($row, $fields['key'] ?: null, 'duplicate', 'name', 'ეს სახელი ფაილში რამდენჯერმეა — დამრიგებლად სახელით ვერ დაუკავშირდება.');
                }

                continue;
            }

            $existingLabel = $this->findMap($tenant, 'staff_label', 0, $label);

            if ($existingLabel !== null && $existingLabel->target_id !== $user->id) {
                $this->issue($row, $fields['key'] ?: null, 'duplicate', 'name', 'იგივე სახელი სხვა ანგარიშზეა მიბმული — გადაამოწმეთ ხელით.');

                continue;
            }

            $this->pin($tenant, 'staff_label', 0, $label, $user, $this->hash([$user->id]), $row);
        }
    }

    private function importClasses(Tenant $tenant, SourceTable $table, ?AcademicYear $year): void
    {
        assert($year !== null);

        foreach ($this->mappedRecords($table, 'classes', [['name']]) as [$row, $fields]) {
            $name = $fields['name'];

            if ($name === '') {
                $this->issue($row, null, 'validation', 'name', 'კლასის სახელი ცარიელია.');

                continue;
            }

            $key = $fields['key'] ?: $name;
            $homeroom = $this->resolveStaff($tenant, $fields['homeroom'], $row, $key, 'homeroom');
            $coHomeroom = $this->resolveStaff($tenant, $fields['co_homeroom'], $row, $key, 'co_homeroom');
            $roomId = $this->resolveRoom($tenant, $fields['room'], $row, $key);

            $values = [
                'name' => $name,
                'short_name' => $fields['short_name'] ?: null,
                'grade_level' => $this->gradeLevel($fields['grade_level']),
                'homeroom_teacher_id' => $homeroom?->id,
                'home_room_id' => $roomId,
            ];

            if ($fields['grade_level'] !== '' && $values['grade_level'] === null) {
                $this->issue($row, $key, 'validation', 'grade_level', 'საფეხური ვერ წაიკითხა.');
            }

            if ($fields['student_count'] !== '') {
                $this->counts['source_students'] = ($this->counts['source_students'] ?? 0) + (int) $fields['student_count'];
            }

            $class = $this->upsert(
                tenant: $tenant,
                entity: 'class',
                scopeId: $year->id,
                key: $key,
                row: $row,
                values: $values + ['co_homeroom' => $coHomeroom?->id],
                model: SchoolClass::class,
                sameNamed: fn () => SchoolClass::query()->where('tenant_id', $tenant->id)->where('academic_year_id', $year->id)->where('name', $name)->exists(),
                create: fn () => $this->createFor($tenant, new SchoolClass($values + ['academic_year_id' => $year->id])),
                update: function (Model $class) use ($values) {
                    $class->fill($values)->save();
                },
            );

            if ($class instanceof SchoolClass && $coHomeroom !== null && in_array($this->lastOutcome, ['created', 'updated'], true)) {
                $exists = ClassStaffRole::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('school_class_id', $class->id)
                    ->where('user_id', $coHomeroom->id)
                    ->where('role', ClassStaffRole::ROLE_CO_HOMEROOM)
                    ->exists();

                if (! $exists) {
                    $this->createFor($tenant, new ClassStaffRole([
                        'school_class_id' => $class->id,
                        'user_id' => $coHomeroom->id,
                        'role' => ClassStaffRole::ROLE_CO_HOMEROOM,
                    ]));
                }
            }
        }
    }

    /**
     * Reads the class × (day, period) summary: counts cells and lesson
     * slots, and lists every class and subject label that has no target
     * yet. Writes nothing but the batch and its issues.
     */
    private function previewTimetableSummary(Tenant $tenant, SourceTable $table, ?AcademicYear $year): void
    {
        assert($year !== null);
        $rows = $table->rows;

        if (count($rows) < 3) {
            throw new InvalidArgumentException('ცხრილი ცარიელია ან ფორმატი უცნობია.');
        }

        // Row 0: days, each spanning its periods. Row 1: period numbers.
        $days = [];

        foreach (array_slice($rows[0], 1) as $cell) {
            $day = self::DAYS[$cell['text']] ?? null;

            if ($day === null) {
                throw new InvalidArgumentException('დღის სათაური ვერ წაიკითხა.');
            }

            array_push($days, ...array_fill(0, $cell['span'], $day));
        }

        $periods = array_map(fn (array $cell) => (int) $cell['text'], array_slice($rows[1], 1));

        if (count($periods) !== count($days)) {
            throw new InvalidArgumentException('დღეებისა და პერიოდების სვეტები ერთმანეთს არ ემთხვევა.');
        }

        $labels = [];
        $slots = 0;
        $cells = 0;

        foreach (array_slice($rows, 2) as $index => $row) {
            $className = $row[0]['text'] ?? '';

            if ($className === '') {
                continue;
            }

            $this->counts['rows']++;
            $rowNumber = $index + 3;
            $known = $this->findMap($tenant, 'class', $year->id, $className) !== null
                || SchoolClass::query()->where('tenant_id', $tenant->id)->where('academic_year_id', $year->id)->where('name', $className)->exists();

            if (! $known) {
                $this->issue($rowNumber, $className, 'unresolved_reference', 'class', 'კლასი ამ სასწავლო წელში არ არსებობს — ჯერ კლასების იმპორტი.');
            }

            $position = 0;

            foreach (array_slice($row, 1) as $cell) {
                if ($cell['text'] !== '') {
                    $cells++;
                    $slots += $cell['span'];
                    $labels[$cell['text']] = ($labels[$cell['text']] ?? 0) + $cell['span'];
                }

                $position += $cell['span'];
            }

            if ($position !== count($days)) {
                $this->issue($rowNumber, $className, 'validation', 'slots', 'სტრიქონის სიგანე სათაურს არ ემთხვევა.');
            }
        }

        foreach (array_keys($labels) as $label) {
            $mapped = $this->findMap($tenant, 'subject', 0, (string) $label) !== null
                || Subject::query()->where('tenant_id', $tenant->id)->where('name', (string) $label)->exists();

            if (! $mapped) {
                $this->issue(null, (string) $label, 'unmapped', 'subject', 'საგნის წარწერა Subject-ზე მიბმული არ არის — საჭიროა დადასტურებული რუკა.');
            }
        }

        $this->counts['cells'] = $cells;
        $this->counts['lesson_slots'] = $slots;
        $this->counts['subject_labels'] = count($labels);
        $this->counts['periods_per_day'] = count(array_unique($periods));
        $this->counts['skipped'] = $this->counts['rows'];
    }

    /**
     * Create, update or leave one target according to the identity map.
     *
     * @param  array<string, mixed>  $values
     * @param  class-string<Model>  $model
     * @param  Closure(): bool  $sameNamed
     * @param  Closure(): Model  $create
     * @param  (Closure(Model): void)|null  $update
     */
    private function upsert(Tenant $tenant, string $entity, int $scopeId, string $key, int $row, array $values, string $model, Closure $sameNamed, Closure $create, ?Closure $update = null): ?Model
    {
        $this->lastOutcome = 'skipped';

        if (isset($this->seenKeys[$entity][$key])) {
            $this->issue($row, $key, 'duplicate', 'key', "იგივე ჩანაწერი ფაილში უკვე იყო (სტრიქონი {$this->seenKeys[$entity][$key]}) — მეორე გამოტოვდა.");
            $this->counts['skipped']++;

            return null;
        }

        $this->seenKeys[$entity][$key] = $row;
        $hash = $this->hash($values);
        $map = $this->findMap($tenant, $entity, $scopeId, $key);

        if ($map === null) {
            if ($sameNamed()) {
                $this->issue($row, $key, 'duplicate', 'name', 'იგივე სახელის ჩანაწერი უკვე არსებობს და იმპორტით არ შექმნილა — ავტომატურად არ გაერთიანდება.');
                $this->counts['skipped']++;

                return null;
            }

            $target = $create();
            $this->pin($tenant, $entity, $scopeId, $key, $target, $hash, $row);
            $this->counts['created']++;
            $this->lastOutcome = 'created';

            return $target;
        }

        $target = $model::query()->where('tenant_id', $tenant->id)->whereKey($map->target_id)->first();

        if ($target === null) {
            $this->issue($row, $key, 'conflict', null, 'იმპორტით შექმნილი ჩანაწერი წაშლილია — თავიდან არ შეიქმნება.');
            $this->counts['skipped']++;

            return null;
        }

        if ($map->source_hash === $hash) {
            $this->counts['unchanged']++;
            $this->lastOutcome = 'unchanged';

            return $target;
        }

        $updatedAt = $this->updatedAt($target);

        if ($updatedAt !== null && $updatedAt->greaterThan($map->synced_at)) {
            $this->issue($row, $key, 'conflict', null, 'ჩანაწერი იმპორტის შემდეგ ხელით შეიცვალა — ცვლილება შენარჩუნდა, წყაროს ახალი მნიშვნელობა არ ჩაიწერა.');
            $this->counts['skipped']++;

            return $target;
        }

        $update ??= function (Model $target) use ($values) {
            $target->fill($values)->save();
        };
        $update($target);
        $this->pin($tenant, $entity, $scopeId, $key, $target->refresh(), $hash, $row);
        $this->counts['updated']++;
        $this->lastOutcome = 'updated';

        return $target;
    }

    /**
     * `tenant_id` is never mass-assignable, and console runs have no
     * request tenant for BelongsToTenant to fill in — so set it explicitly.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function createFor(Tenant $tenant, Model $model): Model
    {
        $model->setAttribute('tenant_id', $tenant->id);
        $model->save();

        return $model;
    }

    private function pin(Tenant $tenant, string $entity, int $scopeId, string $key, Model $target, string $hash, int $row): void
    {
        $map = $this->findMap($tenant, $entity, $scopeId, $key) ?? new ImportIdentityMap([
            'tenant_id' => $tenant->id,
            'source' => self::SOURCE,
            'entity_type' => $entity,
            'scope_id' => $scopeId,
            'source_key' => $key,
            'first_batch_id' => $this->batch->id,
        ]);

        $map->fill([
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
            'source_hash' => $hash,
            'synced_at' => $this->updatedAt($target) ?? now(),
            'last_batch_id' => $this->batch->id,
        ])->save();
    }

    private function updatedAt(Model $model): ?Carbon
    {
        $value = $model->getAttribute($model->getUpdatedAtColumn() ?? 'updated_at');

        return $value instanceof DateTimeInterface ? Carbon::instance($value) : null;
    }

    private function findMap(Tenant $tenant, string $entity, int $scopeId, string $key): ?ImportIdentityMap
    {
        return ImportIdentityMap::query()
            ->where('tenant_id', $tenant->id)
            ->where('source', self::SOURCE)
            ->where('entity_type', $entity)
            ->where('scope_id', $scopeId)
            ->where('source_key', $key)
            ->first();
    }

    private function resolveStaff(Tenant $tenant, string $label, int $row, string $key, string $field): ?User
    {
        if ($label === '') {
            return null;
        }

        $map = $this->findMap($tenant, 'staff', 0, $label) ?? $this->findMap($tenant, 'staff_label', 0, $this->normalizeLabel($label));
        $user = $map === null ? null : User::query()->whereKey($map->target_id)->first();

        if ($user === null) {
            $this->issue($row, $key, 'unresolved_reference', $field, 'დამრიგებელი იმპორტირებულ პერსონალში ვერ მოიძებნა — კლასი მის გარეშე ჩაიწერება.');
        }

        return $user;
    }

    private function resolveRoom(Tenant $tenant, string $label, int $row, string $key): ?int
    {
        if ($label === '') {
            return null;
        }

        $map = $this->findMap($tenant, 'room', 0, $label);
        $roomId = $map->target_id ?? Room::query()->where('tenant_id', $tenant->id)->where('name', $label)->value('id');

        if ($roomId === null) {
            $this->issue($row, $key, 'unresolved_reference', 'room', 'ოთახი ვერ მოიძებნა — ჯერ ოთახების იმპორტი.');
        }

        return $roomId === null ? null : (int) $roomId;
    }

    /**
     * Map each record's cells to the type's fields, failing before anything
     * is written when a required column is missing. `$required` lists field
     * sets of which at least one must be fully present.
     *
     * @param  list<list<string>>  $required
     * @return list<array{0: int, 1: array<string, string>}>
     */
    private function mappedRecords(SourceTable $table, string $type, array $required): array
    {
        ['headers' => $headers, 'records' => $records] = $table->records();
        $columns = [];

        foreach (self::COLUMNS[$type] as $field => $aliases) {
            foreach ($headers as $header) {
                if (in_array(mb_strtolower(trim($header)), $aliases, true)) {
                    $columns[$field] = $header;

                    break;
                }
            }
        }

        $satisfied = collect($required)->contains(fn (array $set) => array_diff($set, array_keys($columns)) === []);

        if (! $satisfied) {
            throw new InvalidArgumentException('სავალდებულო სვეტი ვერ მოიძებნა. ფაილის სვეტები: '.implode(', ', $headers));
        }

        $mapped = [];

        foreach ($records as $record) {
            $this->counts['rows']++;
            $fields = [];

            foreach (array_keys(self::COLUMNS[$type]) as $field) {
                $fields[$field] = isset($columns[$field]) ? ($record['cells'][$columns[$field]] ?? '') : '';
            }

            $mapped[] = [$record['row'], $fields];
        }

        return $mapped;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function staffLabel(array $fields): string
    {
        $name = $fields['name'] !== '' ? $fields['name'] : trim($fields['first_name'].' '.$fields['last_name']);

        return $this->normalizeLabel($name);
    }

    private function normalizeLabel(string $label): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $label) ?? $label));
    }

    private function gradeLevel(string $value): ?int
    {
        $value = trim($value);

        if (preg_match('/^\d{1,2}$/', $value) === 1) {
            return (int) $value;
        }

        return self::ROMAN[strtoupper($value)] ?? null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function hash(array $values): string
    {
        return hash('sha256', (string) json_encode($values));
    }

    private function issue(?int $row, ?string $key, string $kind, ?string $field, string $message): void
    {
        $this->issues[] = ['row_number' => $row, 'source_key' => $key, 'kind' => $kind, 'field' => $field, 'message' => $message];
    }
}
