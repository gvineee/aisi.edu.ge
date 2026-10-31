<?php

namespace App\Console\Commands;

use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Uploads\FileScanner;
use App\Domain\Uploads\NullFileScanner;
use App\Domain\Uploads\ScanStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * After an antivirus engine is switched on: check the engine with the
 * standard EICAR test string, then scan every private upload stored while
 * there was none (`skipped`). Infected files become unservable (documents
 * go to quarantine); a file the engine cannot read keeps its state and is
 * listed, never waved through. Prints counts only — no file names.
 */
class ScanUnscannedUploads extends Command
{
    protected $signature = 'uploads:scan-unscanned
        {--check-only : Only test that the engine detects EICAR and passes a clean file}
        {--dry-run : Count what would be scanned, change nothing}';

    protected $description = 'Verify the antivirus engine and scan uploads stored before it was enabled';

    /** The industry-standard, harmless antivirus test file. */
    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    private const TABLES = ['portfolio_assets', 'care_plan_documents', 'message_attachments'];

    public function handle(FileScanner $scanner): int
    {
        if ($scanner instanceof NullFileScanner) {
            $this->error('ანტივირუსი ჩართული არ არის (UPLOADS_SCANNER=null). ჯერ დააყენეთ clamd და .env-ში UPLOADS_SCANNER=clamd.');

            return self::FAILURE;
        }

        if (! $this->engineWorks($scanner)) {
            return self::FAILURE;
        }

        if ($this->option('check-only')) {
            return self::SUCCESS;
        }

        $totals = ['clean' => 0, 'infected' => 0, 'failed' => 0];

        foreach (self::TABLES as $table) {
            $rows = DB::table($table)->where('scan_status', ScanStatus::SKIPPED)->get(['id', 'storage_path']);
            $this->line("{$table}: {$rows->count()} შეუმოწმებელი");

            foreach ($this->option('dry-run') ? [] : $rows as $row) {
                $result = $this->scanOne($scanner, (string) $row->storage_path);

                if ($result !== null) {
                    DB::table($table)->where('id', $row->id)->where('scan_status', ScanStatus::SKIPPED)
                        ->update(['scan_status' => $result, 'scanned_at' => Carbon::now()]);
                }

                $totals[$result === null ? 'failed' : ($result === ScanStatus::INFECTED ? 'infected' : 'clean')]++;
            }
        }

        $versions = DocumentVersion::query()->withoutGlobalScope('tenant')->where('scan_state', DocumentVersion::SCAN_SKIPPED)->get();
        $this->line("document_versions: {$versions->count()} შეუმოწმებელი");

        foreach ($this->option('dry-run') ? [] : $versions as $version) {
            $result = $this->scanOne($scanner, $version->storage_path);

            if ($result !== null) {
                $version->scan_state = $result === ScanStatus::INFECTED ? DocumentVersion::SCAN_QUARANTINED : DocumentVersion::SCAN_CLEAN;
                $version->save();
            }

            $totals[$result === null ? 'failed' : ($result === ScanStatus::INFECTED ? 'infected' : 'clean')]++;
        }

        $this->info("სუფთა: {$totals['clean']} · ინფიცირებული (დაიბლოკა): {$totals['infected']} · ვერ შემოწმდა (უცვლელი): {$totals['failed']}");

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function engineWorks(FileScanner $scanner): bool
    {
        $disk = Storage::disk('local');
        $dir = 'scanner-check-'.bin2hex(random_bytes(4));

        try {
            $disk->put("{$dir}/eicar.txt", self::EICAR);
            $disk->put("{$dir}/clean.txt", 'სუფთა სატესტო ფაილი');
            $eicar = $scanner->scan('local', "{$dir}/eicar.txt");
            $clean = $scanner->scan('local', "{$dir}/clean.txt");
        } catch (Throwable $e) {
            $this->error('ანტივირუსს ვერ დავუკავშირდი: '.$e->getMessage());

            return false;
        } finally {
            $disk->deleteDirectory($dir);
        }

        if ($eicar !== ScanStatus::INFECTED || $clean !== ScanStatus::CLEAN) {
            $this->error("ანტივირუსი არასწორად პასუხობს (EICAR: {$eicar}, სუფთა: {$clean}).");

            return false;
        }

        $this->info('ანტივირუსი მუშაობს: სატესტო ვირუსი დაიჭირა, სუფთა ფაილი გაატარა.');

        return true;
    }

    private function scanOne(FileScanner $scanner, string $path): ?string
    {
        try {
            return $scanner->scan('local', $path);
        } catch (Throwable) {
            return null;
        }
    }
}
