<?php

namespace App\Domain\Portfolio;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Same private-disk, sniffed-MIME pattern as
 * App\Domain\Documents\DocumentFileStorage — a student's work is just as
 * private as a staff document until published.
 */
class PortfolioFileStorage
{
    public const MAX_BYTES = 20 * 1024 * 1024; // 20MB — documented cap, matches Documents.

    /**
     * @var array<int, string>
     */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    /**
     * @return array{storage_path: string, checksum: string, mime: string, size: int, original_filename: string}
     */
    public function store(UploadedFile $file, int $tenantId, int $portfolioItemId): array
    {
        $this->assertAllowed($file);

        $mime = (string) $file->getMimeType();
        $checksum = (string) hash_file('sha256', $file->getRealPath());
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $path = "portfolio/{$tenantId}/{$portfolioItemId}/".bin2hex(random_bytes(8)).".{$extension}";

        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        return [
            'storage_path' => $path,
            'checksum' => $checksum,
            'mime' => $mime,
            'size' => $file->getSize() ?: 0,
            'original_filename' => $file->getClientOriginalName(),
        ];
    }

    public function assertAllowed(UploadedFile $file): void
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'file' => 'ფაილის ტიპი დაშვებული არ არის (PDF ან სურათი).',
            ]);
        }

        if (($file->getSize() ?: 0) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'ფაილი ძალიან დიდია (მაქსიმუმ 20MB).',
            ]);
        }
    }

    public function signedDownloadUrl(int $portfolioItemId, int $assetId, int $minutes = 5): string
    {
        return URL::temporarySignedRoute(
            'portfolio.assets.download',
            now()->addMinutes($minutes),
            ['portfolioItem' => $portfolioItemId, 'asset' => $assetId],
        );
    }
}
