<?php

namespace App\Domain\Content;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Same private-disk-style sniffed-MIME/size validation pattern as
 * App\Domain\Portfolio\PortfolioFileStorage and App\Domain\Documents\
 * DocumentFileStorage, but writing to the 'public' disk (see Media model
 * docblock) since CMS media is meant to be embedded into published pages.
 * CLAUDE.md's logo/brand upload rule ("დაშვებული ფაილი, ზომა... arbitrary
 * script ან არასანიტიზებული SVG არ მიიღოს") applies here too: no SVG, no
 * script-bearing types — only a fixed raster/PDF allowlist.
 */
class MediaFileStorage
{
    public const MAX_BYTES = 10 * 1024 * 1024; // 10MB — CMS images/PDFs, smaller than the 20MB document/portfolio cap.

    /**
     * @var array<int, string>
     */
    public const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'application/pdf',
    ];

    /**
     * @return array{path: string, mime: string, size: int, original_filename: string}
     */
    public function store(UploadedFile $file, int $tenantId): array
    {
        $this->assertAllowed($file);

        $mime = (string) $file->getMimeType();
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $path = "media/{$tenantId}/".bin2hex(random_bytes(8)).".{$extension}";

        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

        return [
            'path' => $path,
            'mime' => $mime,
            'size' => $file->getSize() ?: 0,
            'original_filename' => $file->getClientOriginalName(),
        ];
    }

    public function assertAllowed(UploadedFile $file): void
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'file' => 'ფაილის ტიპი დაშვებული არ არის (PNG, JPEG, WEBP ან PDF).',
            ]);
        }

        if (($file->getSize() ?: 0) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'ფაილი ძალიან დიდია (მაქსიმუმ 10MB).',
            ]);
        }
    }
}
