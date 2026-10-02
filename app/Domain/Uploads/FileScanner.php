<?php

namespace App\Domain\Uploads;

/**
 * Malware scanning for private uploads (CLAUDE.md #5). Returns one of
 * `clean`, `infected` or `skipped` (no engine configured).
 */
interface FileScanner
{
    public function scan(string $disk, string $path): string;
}
