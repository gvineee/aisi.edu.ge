<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Shared upload validation/storage for every Action that writes a
 * DocumentVersion (CreateDraftDocument, UploadDocumentVersion,
 * RestoreAsDraft). Not itself a domain Action — it has no state
 * transition or audit responsibility of its own; callers log the audit
 * event once the version row exists.
 *
 * Files live on the "local" disk, which this app already configures as
 * private (storage/app/private, not web-served) — see config/filesystems.php.
 * MIME is sniffed from real file content (Symfony's UploadedFile::getMimeType
 * uses fileinfo), never trusted from the client-sent extension alone.
 */
class DocumentFileStorage
{
    public const MAX_BYTES = 20 * 1024 * 1024; // 20MB — documented cap, not a framework default.

    /**
     * @var array<int, string>
     */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    /**
     * @return array{storage_path: string, checksum: string, mime: string, size: int, original_filename: string}
     */
    public function store(UploadedFile $file, int $tenantId, int $documentId, int $ordinal): array
    {
        $this->assertAllowed($file);

        $mime = (string) $file->getMimeType();
        $checksum = hash_file('sha256', $file->getRealPath());
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $path = "documents/{$tenantId}/{$documentId}/v{$ordinal}-".bin2hex(random_bytes(8)).".{$extension}";

        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        return [
            'storage_path' => $path,
            'checksum' => (string) $checksum,
            'mime' => $mime,
            'size' => $file->getSize() ?: 0,
            'original_filename' => $file->getClientOriginalName(),
        ];
    }

    /**
     * Used by RestoreAsDraft, which copies an existing version's bytes into
     * a brand-new version rather than accepting a fresh upload — the old
     * version's row (and its bytes) are never mutated.
     *
     * @return array{storage_path: string, checksum: string, mime: string, size: int, original_filename: string}
     */
    public function copyFrom(Models\DocumentVersion $source, int $documentId, int $ordinal): array
    {
        $extension = pathinfo($source->storage_path, PATHINFO_EXTENSION) ?: 'bin';
        $path = "documents/{$source->tenant_id}/{$documentId}/v{$ordinal}-".bin2hex(random_bytes(8)).".{$extension}";

        $bytes = Storage::disk('local')->get($source->storage_path);
        Storage::disk('local')->put($path, (string) $bytes);

        return [
            'storage_path' => $path,
            'checksum' => hash('sha256', (string) $bytes),
            'mime' => $source->mime,
            'size' => strlen((string) $bytes),
            'original_filename' => $source->original_filename,
        ];
    }

    public function assertAllowed(UploadedFile $file): void
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'file' => 'ფაილის ტიპი დაშვებული არ არის (PDF/DOCX/XLSX/PPTX ან სურათი).',
            ]);
        }

        if (($file->getSize() ?: 0) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'ფაილი ძალიან დიდია (მაქსიმუმ 20MB).',
            ]);
        }
    }

    public function signedDownloadUrl(Document $document, Models\DocumentVersion $version, int $minutes = 5): string
    {
        return URL::temporarySignedRoute(
            'documents.versions.download',
            now()->addMinutes($minutes),
            ['document' => $document->id, 'version' => $version->id],
        );
    }
}
