<?php

namespace App\Console\Commands;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Integrations\EduPage\EduPageImporter;
use App\Domain\Integrations\Models\ImportIssue;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Previews (default) or applies one EduPage export for one tenant. Import
 * in this order, so later types can resolve earlier ones: rooms → subjects
 * → staff → classes; `timetable-summary` is a preview only. `asc-timetable`
 * (EduPage's complete regular timetable as JSON) brings everything at once.
 *
 * The file should sit in private storage (storage/app/private/…), never in
 * public/ or the repository. Issues are printed with row numbers and field
 * names only — never the raw values.
 */
class EduPageImportCommand extends Command
{
    protected $signature = 'edupage:import
        {type : rooms | subjects | staff | classes | timetable-summary | asc-timetable | asc-students}
        {file : Path to the exported file}
        {--tenant= : Tenant slug (required)}
        {--year= : Academic year name, e.g. 2026-2027 (required for classes / timetable-summary)}
        {--apply : Write the import. Without it nothing is written except the preview batch}
        {--source-version= : Export version as shown in EduPage, e.g. 210}
        {--valid-from= : Export validity start, Y-m-d}
        {--valid-to= : Export validity end, Y-m-d}
        {--scope= : Filters/scope the export was taken with}
        {--label= : asc-timetable: name of the timetable version it creates}
        {--adopt-same-named : asc-timetable: reuse subjects/rooms the school already entered under the same name}
        {--force : Skip the confirmation prompt for --apply}';

    protected $description = 'Preview (default) or apply an EduPage export: rooms, subjects, staff, classes, timetable summary';

    public function handle(EduPageImporter $importer): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();

        if ($tenant === null) {
            $this->error('სკოლა (--tenant) ვერ მოიძებნა.');

            return self::FAILURE;
        }

        $year = null;

        if ($this->option('year') !== null) {
            $year = AcademicYear::query()->where('tenant_id', $tenant->id)->where('name', (string) $this->option('year'))->first();

            if ($year === null) {
                $this->error('სასწავლო წელი (--year) ამ სკოლაში ვერ მოიძებნა.');

                return self::FAILURE;
            }
        }

        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error('ფაილი ვერ მოიძებნა.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        if ($apply && ! $this->option('force') && ! $this->confirm("ჩაიწეროს იმპორტი სკოლაში „{$tenant->slug}“?")) {
            return self::FAILURE;
        }

        $context = array_filter([
            'source_version' => $this->option('source-version'),
            'valid_from' => $this->option('valid-from'),
            'valid_to' => $this->option('valid-to'),
            'scope' => $this->option('scope'),
            'label' => $this->option('label'),
            'adopt_same_named' => $this->option('adopt-same-named') ?: null,
        ], fn ($value) => $value !== null);

        try {
            $batch = $importer->run($tenant, (string) $this->argument('type'), $file, $year, $apply, null, $context);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(($apply ? 'ჩაიწერა' : 'Preview — არაფერი ჩაწერილა').". batch #{$batch->id}, sha256 {$batch->file_sha256}");
        $this->table(['რაოდენობა', 'მნიშვნელობა'], collect($batch->counts ?? [])->map(fn ($value, $key) => [$key, $value])->values()->all());

        $issues = ImportIssue::query()->where('tenant_id', $tenant->id)->where('import_batch_id', $batch->id)->orderBy('id')->get();

        if ($issues->isNotEmpty()) {
            $this->warn('გადასახედი ჩანაწერები:');
            $this->table(
                ['სტრიქონი', 'გასაღები', 'ტიპი', 'ველი', 'შეტყობინება'],
                $issues->map(fn (ImportIssue $issue) => [$issue->row_number, $issue->source_key, $issue->kind, $issue->field, $issue->message])->all(),
            );
        }

        return self::SUCCESS;
    }
}
