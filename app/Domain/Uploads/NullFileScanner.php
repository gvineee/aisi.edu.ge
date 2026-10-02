<?php

namespace App\Domain\Uploads;

/**
 * Used while no antivirus engine (e.g. ClamAV) is installed on the server.
 * It never claims a file is clean — it reports `skipped`, and whether
 * skipped files may be served is a separate, explicit setting
 * (`uploads.allow_unscanned`).
 */
class NullFileScanner implements FileScanner
{
    public function scan(string $disk, string $path): string
    {
        return 'skipped';
    }
}
