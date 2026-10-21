<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentAcknowledgement;
use App\Domain\Documents\Models\DocumentAcknowledgementRequest;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Mail\DocumentAcknowledgementMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Acknowledgement of a published version (docs/07 §5 "გაცნობის
 * დადასტურება"):
 * - someone who manages the document asks staff in chosen roles to read
 *   the current published version; only people who can actually see the
 *   document become recipients, and they are told by e-mail;
 * - a recipient confirms "გავეცანი" for exactly that version — opening or
 *   downloading is not a confirmation;
 * - publishing a newer version supersedes the open request and opens a
 *   new one for the same roles, so earlier confirmations do not carry over;
 * - confirmations are receipts, not a disciplinary judgement.
 */
class ManageDocumentAcknowledgements
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<int, string>  $roles
     */
    public function request(Document $document, array $roles, ?Carbon $dueOn, ?string $note, User $actor): DocumentAcknowledgementRequest
    {
        $roles = array_values(array_intersect(DocumentAccess::STAFF_ROLES, $roles));

        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => 'აირჩიეთ სულ მცირე ერთი როლი.']);
        }

        return DB::transaction(function () use ($document, $roles, $dueOn, $note, $actor) {
            $locked = Document::query()->where('tenant_id', $document->tenant_id)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $version = $locked->published_version_id === null ? null
                : DocumentVersion::query()->where('tenant_id', $locked->tenant_id)->whereKey($locked->published_version_id)->first();

            if ($locked->archived_at !== null || $version === null || ! $version->isUsable()) {
                throw ValidationException::withMessages(['roles' => 'გაცნობა შეიძლება მხოლოდ გამოქვეყნებულ, შემოწმებულ ვერსიაზე.']);
            }

            DocumentAcknowledgementRequest::query()->where('tenant_id', $locked->tenant_id)->where('document_id', $locked->id)
                ->where('status', DocumentAcknowledgementRequest::STATUS_OPEN)
                ->update(['status' => DocumentAcknowledgementRequest::STATUS_CLOSED]);

            $request = $this->open($locked, $version, $roles, $dueOn, $note, $actor->id, $actor->id);

            if ($request->acknowledgements()->count() === 0) {
                throw ValidationException::withMessages(['roles' => 'ამ როლებში დოკუმენტის ნახვის უფლების მქონე თანამშრომელი არ არის.']);
            }

            return $request;
        });
    }

    public function acknowledge(DocumentAcknowledgement $acknowledgement, User $user): void
    {
        abort_unless($acknowledgement->user_id === $user->id, 403);

        DB::transaction(function () use ($acknowledgement, $user) {
            $locked = DocumentAcknowledgement::query()->where('tenant_id', $acknowledgement->tenant_id)->whereKey($acknowledgement->id)->lockForUpdate()->firstOrFail();
            $request = DocumentAcknowledgementRequest::query()->where('tenant_id', $locked->tenant_id)->whereKey($locked->document_acknowledgement_request_id)->firstOrFail();
            $document = Document::query()->where('tenant_id', $locked->tenant_id)->whereKey($request->document_id)->firstOrFail();

            if ($locked->acknowledged_at !== null) {
                return;
            }

            if ($request->status !== DocumentAcknowledgementRequest::STATUS_OPEN || $document->published_version_id !== $locked->document_version_id) {
                throw ValidationException::withMessages(['acknowledgement' => 'ეს მოთხოვნა აღარ მოქმედებს — დოკუმენტის ახალი ვერსია გამოქვეყნდა.']);
            }

            $version = DocumentVersion::query()->where('tenant_id', $locked->tenant_id)->whereKey($locked->document_version_id)->firstOrFail();

            if (! $version->isUsable()) {
                throw ValidationException::withMessages(['acknowledgement' => 'ფაილი ჯერ მოწმდება — დადასტურება შემოწმების შემდეგ შეგიძლიათ.']);
            }

            if (! $this->access->canView($document, $user)) {
                throw ValidationException::withMessages(['acknowledgement' => 'დოკუმენტის ნახვის უფლება აღარ გაქვთ.']);
            }

            $locked->acknowledged_at = Carbon::now();
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'document.acknowledged', $document, $user->id, [
                'request_id' => $request->id,
                'version_id' => $locked->document_version_id,
            ]);
        });

        $acknowledgement->refresh();
    }

    public function close(DocumentAcknowledgementRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor) {
            $locked = DocumentAcknowledgementRequest::query()->where('tenant_id', $request->tenant_id)->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DocumentAcknowledgementRequest::STATUS_OPEN) {
                return;
            }

            $locked->status = DocumentAcknowledgementRequest::STATUS_CLOSED;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'document.acknowledgement_closed', $locked, $actor->id, ['document_id' => $locked->document_id]);
        });

        $request->refresh();
    }

    /**
     * Called inside the transaction that changed the document's published
     * version: open requests for an older version are superseded and
     * re-opened for the new one with the same roles.
     */
    public function onPublished(Document $document, DocumentVersion $version, ?int $actorId): void
    {
        $open = DocumentAcknowledgementRequest::query()->where('tenant_id', $document->tenant_id)->where('document_id', $document->id)
            ->where('status', DocumentAcknowledgementRequest::STATUS_OPEN)->where('document_version_id', '!=', $version->id)
            ->lockForUpdate()->get();

        foreach ($open as $previous) {
            $previous->status = DocumentAcknowledgementRequest::STATUS_SUPERSEDED;
            $previous->save();

            $this->open($document, $version, $previous->audience_roles, null, $previous->note, $previous->requested_by, $actorId);
        }
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function open(Document $document, DocumentVersion $version, array $roles, ?Carbon $dueOn, ?string $note, int $requestedBy, ?int $actorId): DocumentAcknowledgementRequest
    {
        $request = new DocumentAcknowledgementRequest([
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'audience_roles' => array_values($roles),
            'due_on' => $dueOn?->toDateString(),
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            'status' => DocumentAcknowledgementRequest::STATUS_OPEN,
            'requested_by' => $requestedBy,
        ]);
        $request->tenant_id = $document->tenant_id;
        $request->save();

        $recipients = User::query()
            ->whereIn('id', TenantMembership::query()->where('tenant_id', $document->tenant_id)->where('is_active', true)
                ->whereIn('role', $roles)->select('user_id'))
            ->where('id', '!=', $requestedBy)
            ->orderBy('id')->get()
            ->filter(fn (User $user) => $this->access->canView($document, $user))
            ->values();

        $tenantName = (string) Tenant::query()->whereKey($document->tenant_id)->value('name');
        // Built now: the queue worker has no tenant domain to build it from.
        $url = route('documents.show', $document);

        foreach ($recipients as $user) {
            $row = new DocumentAcknowledgement([
                'document_acknowledgement_request_id' => $request->id,
                'document_version_id' => $version->id,
                'user_id' => $user->id,
                'notified_at' => Carbon::now(),
            ]);
            $row->tenant_id = $document->tenant_id;
            $row->save();

            Mail::to($user)->queue((new DocumentAcknowledgementMail(
                $tenantName, $document->title, $version->ordinal, $request->due_on?->format('d.m.Y'), $url,
            ))->afterCommit());
        }

        $this->auditLogger->record($document->tenant_id, 'document.acknowledgement_requested', $document, $actorId, [
            'request_id' => $request->id,
            'version_id' => $version->id,
            'roles' => $roles,
            'recipients' => $recipients->count(),
        ]);

        return $request;
    }
}
