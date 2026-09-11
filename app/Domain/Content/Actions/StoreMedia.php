<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\MediaFileStorage;
use App\Domain\Content\Models\Media;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class StoreMedia
{
    public function __construct(
        private readonly MediaFileStorage $storage,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(int $tenantId, UploadedFile $file, User $actor, ?string $altText): Media
    {
        $stored = $this->storage->store($file, $tenantId);

        $media = new Media([
            'disk' => 'public',
            'path' => $stored['path'],
            'mime' => $stored['mime'],
            'size' => $stored['size'],
            'original_filename' => $stored['original_filename'],
            'alt_text' => $altText,
            'created_by' => $actor->id,
        ]);
        $media->tenant_id = $tenantId;
        $media->save();

        $this->auditLogger->record($tenantId, 'media.uploaded', $media, $actor->id, ['original_filename' => $media->original_filename]);

        return $media;
    }
}
