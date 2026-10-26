<?php

namespace App\Console\Commands;

use App\Domain\Content\Actions\ImportLegacyTranslations;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Previews (default) or imports the old site's English/Russian page
 * translations as drafts.
 */
class ImportLegacyTranslationsCommand extends Command
{
    protected $signature = 'content:import-translations
        {--tenant= : Tenant slug (required)}
        {--file=content-migration/legacy-translations.json : Extracted translations}
        {--commit : Save the drafts (default is a preview)}';

    protected $description = 'Import the old site\'s English/Russian page translations as drafts (preview by default)';

    public function handle(ImportLegacyTranslations $action): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();

        if ($tenant === null) {
            $this->error('სკოლა (--tenant) ვერ მოიძებნა.');

            return self::FAILURE;
        }

        $path = base_path((string) $this->option('file'));
        $file = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($file)) {
            $this->error('ფაილი ვერ წაიკითხა.');

            return self::FAILURE;
        }

        try {
            $rows = $action->handle($tenant, $file, (bool) $this->option('commit'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('commit') ? 'თარგმანები draft-ად შეინახა.' : 'Preview — არაფერი შენახულა.');
        $this->table(
            ['source', 'ენა', 'სათაური', 'სათაური გადასახედია', 'target', 'disposition', 'მიზეზი'],
            collect($rows)->map(fn (array $row) => [
                $row['source_key'], $row['locale'], mb_substr($row['title'], 0, 40), $row['title_needs_review'] ? 'კი' : '', $row['target'], $row['disposition'], $row['reason'],
            ])->all(),
        );

        return self::SUCCESS;
    }
}
