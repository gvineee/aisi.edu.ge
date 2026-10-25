<?php

namespace App\Console\Commands;

use App\Domain\Content\Actions\PlaceContentCategories;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Previews (default) or applies the category placement plan. Writes a
 * per-record report (source → category → target URL, disposition, parity
 * flags) when --report is given.
 */
class PlaceContentCategoriesCommand extends Command
{
    protected $signature = 'content:place-categories
        {--tenant= : Tenant slug (required)}
        {--plan=content-migration/category-placement-plan.json : Placement plan}
        {--report= : Write the per-record JSON report here}
        {--commit : Save the placements (default is a preview)}';

    protected $description = 'Place imported pages/posts into site categories from the placement plan (preview by default)';

    public function handle(PlaceContentCategories $action): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();

        if ($tenant === null) {
            $this->error('სკოლა (--tenant) ვერ მოიძებნა.');

            return self::FAILURE;
        }

        $planPath = base_path((string) $this->option('plan'));
        $plan = is_file($planPath) ? json_decode((string) file_get_contents($planPath), true) : null;

        if (! is_array($plan)) {
            $this->error('გეგმის ფაილი ვერ წაიკითხა.');

            return self::FAILURE;
        }

        try {
            $result = $action->handle($tenant, $plan, (bool) $this->option('commit'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('commit') ? 'განთავსება შენახულია.' : 'Preview — არაფერი შენახულა.');
        $this->table(['disposition', 'რაოდენობა'], collect($result['counts'])->map(fn ($count, $key) => [$key, $count])->values()->all());

        $attention = collect($result['rows'])->filter(fn (array $row) => in_array($row['disposition'], ['conflict', 'pending', 'error'], true));

        if ($attention->isNotEmpty()) {
            $this->warn('გადასახედი:');
            $this->table(['source', 'კატეგორია', 'disposition', 'მიზეზი'], $attention->map(fn (array $row) => [$row['source_key'], $row['category'], $row['disposition'], $row['reason']])->values()->all());
        }

        if ($this->option('report')) {
            $report = [
                'generated_at' => now()->toIso8601String(),
                'tenant' => $tenant->slug,
                'mode' => $this->option('commit') ? 'commit' : 'preview',
                'plan' => (string) $this->option('plan'),
                'counts' => $result['counts'],
                'rows' => $result['rows'],
            ];
            file_put_contents(base_path((string) $this->option('report')), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('ანგარიში: '.$this->option('report'));
        }

        return self::SUCCESS;
    }
}
