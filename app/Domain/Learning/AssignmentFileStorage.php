<?php

namespace App\Domain\Learning;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Same private-disk, sniffed-MIME pattern as Documents'/Portfolio's file
 * storage helpers — a student's submitted homework file is at least as
 * private as a portfolio asset until the owning teacher opens it to grade.
 */
class AssignmentFileStorage
{
    public const MAX_BYTES = 20 * 1024 * 1024; // 20MB — documented cap, matches Documents/Portfolio.

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

    public function store(UploadedFile $file, int $tenantId, int $assignmentId, int $studentId): string
    {
        $this->assertAllowed($file);

        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $path = "assignment-submissions/{$tenantId}/{$assignmentId}/{$studentId}-".bin2hex(random_bytes(8)).".{$extension}";

        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
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

    public function signedDownloadUrl(int $assignmentId, int $submissionId, int $minutes = 5): string
    {
        return URL::temporarySignedRoute(
            'assignments.submissions.download',
            now()->addMinutes($minutes),
            ['assignment' => $assignmentId, 'submission' => $submissionId],
        );
    }
}
