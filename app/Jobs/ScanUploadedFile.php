<?php

namespace App\Jobs;

use App\Domain\Uploads\FileScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scans one stored upload and records the result on its row. Queued after
 * the upload commits; the file stays unservable (`pending`) until then.
 */
class ScanUploadedFile implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $table,
        public readonly int $id,
        public readonly string $path,
    ) {
        $this->afterCommit();
    }

    public function handle(FileScanner $scanner): void
    {
        abort_unless(in_array($this->table, ['portfolio_assets', 'care_plan_documents', 'message_attachments'], true), 500);

        DB::table($this->table)->where('id', $this->id)->update([
            'scan_status' => $scanner->scan('local', $this->path),
            'scanned_at' => Carbon::now(),
        ]);
    }
}
