<?php

namespace App\Domain\Communications\Actions;

use App\Domain\Communications\Models\Message;
use App\Domain\Communications\Models\MessageAttachment;
use App\Domain\Portfolio\PortfolioFileStorage;
use App\Domain\Uploads\ScanStatus;
use App\Jobs\ScanUploadedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores one attachment on the private disk under the tenant/message
 * path, with the same MIME/size allow-list as portfolio uploads, and queues
 * the scan; it is served only once the scan allows it.
 */
class AttachFileToMessage
{
    public function __construct(private readonly PortfolioFileStorage $rules) {}

    public function handle(Message $message, UploadedFile $file): MessageAttachment
    {
        $this->rules->assertAllowed($file);

        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $path = "messages/{$message->tenant_id}/{$message->id}/".bin2hex(random_bytes(8)).".{$extension}";
        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        $attachment = new MessageAttachment;
        $attachment->tenant_id = $message->tenant_id;
        $attachment->message_id = $message->id;
        $attachment->storage_path = $path;
        $attachment->original_filename = mb_substr($file->getClientOriginalName(), 0, 255);
        $attachment->mime = (string) $file->getMimeType();
        $attachment->size = $file->getSize() ?: 0;
        $attachment->checksum = (string) hash_file('sha256', $file->getRealPath());
        $attachment->scan_status = ScanStatus::PENDING;
        $attachment->save();

        ScanUploadedFile::dispatch('message_attachments', $attachment->id, $path);

        return $attachment;
    }
}
