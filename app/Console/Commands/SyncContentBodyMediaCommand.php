<?php

namespace App\Console\Commands;

use App\Domain\Content\Actions\SyncContentBodyMedia;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Previews (default) or links the inline images, galleries and file
 * attachments of imported pages/posts from the local legacy collection.
 */
class SyncContentBodyMediaCommand extends Command
{
    protected $signature = 'content:sync-body-media
        {--tenant= : Tenant slug (required)}
        {--report= : Write the per-record JSON report here}
        {--commit : Copy files and save the links (default is a preview)}';

    protected $description = 'Carry inline images, galleries and attachments of imported pages/posts (preview by default)';

    public function handle(SyncContentBodyMedia $action): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();

        if ($tenant === null) {
            $this->error('სკოლა (--tenant) ვერ მოიძებნა.');

            return self::FAILURE;
        }

        $rows = $action->run($tenant, (bool) $this->option('commit'));

        $this->info($this->option('commit') ? 'მედია მიბმულია.' : 'Preview — არაფერი შენახულა.');
        $this->table(
            ['source', 'სტატუსი', 'სურათი', 'გალერეა', 'დანართი', 'variant', 'emoji', 'პრობლემა', 'disposition'],
            collect($rows)->map(fn (array $row) => [
                $row['source_key'], $row['target_status'] ?? '—', $row['images'], $row['galleries'] ?? 0, $row['attachments'],
                $row['variant_only'] ?? 0, $row['emoji_skipped'] ?? 0, count($row['issues']), $row['disposition'],
            ])->all(),
        );

        if ($this->option('report')) {
            file_put_contents(base_path((string) $this->option('report')), json_encode([
                'generated_at' => now()->toIso8601String(),
                'tenant' => $tenant->slug,
                'mode' => $this->option('commit') ? 'commit' : 'preview',
                'rows' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
