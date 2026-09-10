<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\Document;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitForReview
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Document $document, User $initiator): ApprovalRequest
    {
        return DB::transaction(function () use ($document, $initiator) {
            $document = Document::query()->where('id', $document->id)->lockForUpdate()->firstOrFail();

            if (! in_array($document->status, [Document::STATUS_DRAFT, Document::STATUS_CHANGES_REQUESTED], true)) {
                throw ValidationException::withMessages(['document' => 'დოკუმენტი უკვე განხილვაშია ან დამტკიცებულია.']);
            }

            $version = $document->latestVersion;

            if ($version === null || ! $version->isUsable()) {
                throw ValidationException::withMessages(['document' => 'აქტიური ვერსია ვერ მოიძებნა ან დაბლოკილია.']);
            }

            if (ApprovalRequest::query()->where('document_version_id', $version->id)->exists()) {
                throw ValidationException::withMessages(['document' => 'ეს ვერსია უკვე გაგზავნილია განსახილველად.']);
            }

            $request = new ApprovalRequest([
                'document_version_id' => $version->id,
                'initiator_id' => $initiator->id,
                'state' => ApprovalRequest::STATE_PENDING,
                'lock_version' => 0,
            ]);
            $request->tenant_id = $document->tenant_id;
            $request->save();

            $document->status = Document::STATUS_IN_REVIEW;
            $document->save();

            $this->auditLogger->record($document->tenant_id, 'document.submitted_for_review', $document, $initiator->id, [
                'version_id' => $version->id,
                'approval_request_id' => $request->id,
            ]);

            return $request;
        });
    }
}
