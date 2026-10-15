<?php

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Uploads\FileScanner;
use App\Domain\Uploads\ScanStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scans one stored document version (CLAUDE.md #5). Queued after the upload
 * commits; the version stays `pending` — never downloadable, never
 * submittable — until the scanner answers. An infected file becomes
 * `quarantined`, which the download and approval paths already refuse.
 */
class ScanDocumentVersion implements ShouldQueue
{
    use Queueable;

    // A scanner outage retries; the file stays `pending` meanwhile.
    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $versionId,
    ) {
        $this->afterCommit();
    }

    public function handle(FileScanner $scanner): void
    {
        $version = DocumentVersion::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->versionId)
            ->first();

        if ($version === null || $version->scan_state !== DocumentVersion::SCAN_PENDING) {
            return;
        }

        $version->scan_state = match ($scanner->scan('local', $version->storage_path)) {
            ScanStatus::CLEAN => DocumentVersion::SCAN_CLEAN,
            ScanStatus::INFECTED => DocumentVersion::SCAN_QUARANTINED,
            default => DocumentVersion::SCAN_SKIPPED,
        };
        $version->save();
    }
}
