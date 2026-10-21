<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\ManageDocumentAcknowledgements;
use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentAcknowledgement;
use App\Domain\Documents\Models\DocumentAcknowledgementRequest;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Asking staff to read a published version, confirming "გავეცანი", and
 * closing a request. The document page shows the request and receipts to
 * whoever manages the document, and the pending confirmation to each
 * recipient (props built in documentProps()).
 */
class DocumentAcknowledgementController extends Controller
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function store(Request $request, CurrentTenant $currentTenant, Document $document, ManageDocumentAcknowledgements $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($document->tenant_id === $tenant->id, 404);
        abort_unless($this->access->canManage($document, $request->user()), 403);
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(DocumentAccess::STAFF_ROLES)],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $ackRequest = $action->request(
            $document,
            $data['roles'],
            isset($data['due_on']) ? Carbon::createFromFormat('Y-m-d', $data['due_on']) : null,
            $data['note'] ?? null,
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'გაცნობის მოთხოვნა გაეგზავნა '.$ackRequest->acknowledgements()->count().' თანამშრომელს.']);
    }

    public function close(Request $request, CurrentTenant $currentTenant, DocumentAcknowledgementRequest $acknowledgementRequest, ManageDocumentAcknowledgements $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($acknowledgementRequest->tenant_id === $tenant->id, 404);
        abort_unless($this->access->canManage($acknowledgementRequest->document, $request->user()), 403);

        $action->close($acknowledgementRequest, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'მოთხოვნა დაიხურა.']);
    }

    public function acknowledge(Request $request, CurrentTenant $currentTenant, DocumentAcknowledgement $acknowledgement, ManageDocumentAcknowledgements $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($acknowledgement->tenant_id === $tenant->id, 404);

        $action->acknowledge($acknowledgement, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'დადასტურებულია: გაეცანით დოკუმენტს.']);
    }

    /**
     * Extra props for the document page.
     *
     * @return array<string, mixed>
     */
    public static function documentProps(Document $document, User $user, bool $canManage): array
    {
        $mine = DocumentAcknowledgement::query()->where('tenant_id', $document->tenant_id)->where('user_id', $user->id)
            ->whereNull('acknowledged_at')
            ->whereHas('request', fn ($q) => $q->where('document_id', $document->id)->where('status', DocumentAcknowledgementRequest::STATUS_OPEN))
            ->with('request')->latest('id')->first();
        $mineVersion = $mine === null ? null : DocumentVersion::query()->where('tenant_id', $document->tenant_id)->find($mine->document_version_id);

        $open = $canManage
            ? DocumentAcknowledgementRequest::query()->where('tenant_id', $document->tenant_id)->where('document_id', $document->id)
                ->where('status', DocumentAcknowledgementRequest::STATUS_OPEN)->with(['acknowledgements.user', 'version', 'requester'])->latest('id')->first()
            : null;

        return [
            'myAcknowledgement' => $mine === null ? null : [
                'id' => $mine->id,
                'versionOrdinal' => $mineVersion?->ordinal,
                'dueOn' => $mine->request->due_on?->toDateString(),
                'note' => $mine->request->note,
                'fileReady' => $mineVersion?->isUsable() ?? false,
            ],
            'acknowledgementRequest' => $open === null ? null : [
                'id' => $open->id,
                'versionOrdinal' => $open->version->ordinal,
                'roles' => $open->audience_roles,
                'dueOn' => $open->due_on?->toDateString(),
                'note' => $open->note,
                'requestedBy' => $open->requester->name,
                'createdAt' => $open->created_at?->toIso8601String(),
                'recipients' => $open->acknowledgements->sortBy(fn (DocumentAcknowledgement $a) => [$a->acknowledged_at === null ? 0 : 1, $a->user->name])
                    ->map(fn (DocumentAcknowledgement $a) => ['name' => $a->user->name, 'acknowledgedAt' => $a->acknowledged_at?->toIso8601String()])->values()->all(),
            ],
            'canRequestAcknowledgement' => $canManage && $document->archived_at === null && $document->published_version_id !== null,
            'isTemplate' => $document->is_template,
        ];
    }
}
