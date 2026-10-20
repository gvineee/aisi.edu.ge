<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Governance\AuditLogger;
use App\Domain\Library\LibraryAccess;
use App\Domain\Library\Models\BookListItem;
use App\Domain\Library\Models\LibraryResource;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * „წიგნების სიები“ — class book lists (docs/02 §5.5). The desk edits every
 * class; a teacher the classes they teach or lead. Families read them on
 * „წიგნები“ (BooksController).
 */
class BookListController extends Controller
{
    public function __construct(private readonly LibraryAccess $access) {}

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $ids = $this->access->editableClassIds($tenant->id, $request->user());
        abort_if($ids->isEmpty(), 403);

        $classes = SchoolClass::query()->where('tenant_id', $tenant->id)->whereIn('id', $ids)
            ->with('academicYear')->withCount(['bookListItems'])->get()
            ->sortBy([fn ($a, $b) => (int) $b->academicYear->is_current <=> (int) $a->academicYear->is_current, fn ($a, $b) => strnatcmp($a->name, $b->name)]);

        return Inertia::render('portal/book-lists/index', [
            'classes' => $classes->map(fn (SchoolClass $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'yearName' => $c->academicYear->name,
                'isCurrentYear' => $c->academicYear->is_current,
                'itemsCount' => (int) $c->getAttribute('book_list_items_count'),
            ])->values(),
        ]);
    }

    public function show(Request $request, CurrentTenant $currentTenant, SchoolClass $schoolClass): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($schoolClass->tenant_id === $tenant->id, 404);
        abort_unless($this->access->canEditList($tenant->id, $request->user(), $schoolClass), 403);
        $schoolClass->load('academicYear');

        return Inertia::render('portal/book-lists/show', [
            'schoolClass' => ['id' => $schoolClass->id, 'name' => $schoolClass->name, 'yearName' => $schoolClass->academicYear?->name],
            'items' => BookListItem::query()->where('tenant_id', $tenant->id)->where('school_class_id', $schoolClass->id)
                ->orderBy('position')->orderBy('id')->get()->map(fn (BookListItem $item) => self::itemRow($item))->values(),
            'catalog' => LibraryResource::query()->where('tenant_id', $tenant->id)->orderBy('title')->limit(1000)
                ->get(['id', 'title', 'author'])->map(fn (LibraryResource $r) => ['id' => $r->id, 'label' => $r->title.($r->author ? ' — '.$r->author : '')])->values(),
        ]);
    }

    public function store(Request $request, CurrentTenant $currentTenant, SchoolClass $schoolClass, AuditLogger $audit): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($schoolClass->tenant_id === $tenant->id, 404);
        abort_unless($this->access->canEditList($tenant->id, $request->user(), $schoolClass), 403);

        $item = new BookListItem($this->validated($request, $tenant->id) + [
            'school_class_id' => $schoolClass->id,
            'position' => (int) BookListItem::query()->where('tenant_id', $tenant->id)->where('school_class_id', $schoolClass->id)->max('position') + 1,
            'created_by' => $request->user()->id,
        ]);
        $item->tenant_id = $tenant->id;
        $item->save();
        $audit->record($tenant->id, 'book_list_item.created', $item, $request->user()->id, ['class_id' => $schoolClass->id, 'title' => $item->title]);

        return back()->with('toast', ['type' => 'success', 'message' => 'წიგნი სიას დაემატა.']);
    }

    public function update(Request $request, CurrentTenant $currentTenant, BookListItem $item, AuditLogger $audit): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeItem($request, $tenant->id, $item);

        $item->fill($this->validated($request, $tenant->id))->save();
        $audit->record($tenant->id, 'book_list_item.updated', $item, $request->user()->id, ['changed' => array_keys($item->getChanges())]);

        return back()->with('toast', ['type' => 'success', 'message' => 'შენახულია.']);
    }

    public function destroy(Request $request, CurrentTenant $currentTenant, BookListItem $item, AuditLogger $audit): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeItem($request, $tenant->id, $item);

        $audit->record($tenant->id, 'book_list_item.deleted', $item, $request->user()->id, ['class_id' => $item->school_class_id, 'title' => $item->title]);
        $item->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'წიგნი სიიდან ამოიშალა.']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function itemRow(BookListItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'author' => $item->author,
            'edition' => $item->edition,
            'isbn' => $item->isbn,
            'subject' => $item->subject,
            'requirement' => $item->requirement,
            'acquisition' => $item->acquisition,
            'note' => $item->note,
            'resourceId' => $item->library_resource_id,
        ];
    }

    private function authorizeItem(Request $request, int $tenantId, BookListItem $item): void
    {
        abort_unless($item->tenant_id === $tenantId, 404);
        $class = SchoolClass::query()->where('tenant_id', $tenantId)->findOrFail($item->school_class_id);
        abort_unless($this->access->canEditList($tenantId, $request->user(), $class), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $tenantId): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'edition' => ['nullable', 'string', 'max:120'],
            'isbn' => ['nullable', 'string', 'max:20', 'regex:/^[0-9Xx-]+$/'],
            'subject' => ['nullable', 'string', 'max:120'],
            'requirement' => ['required', Rule::in(BookListItem::REQUIREMENTS)],
            'acquisition' => ['required', Rule::in(BookListItem::ACQUISITIONS)],
            'note' => ['nullable', 'string', 'max:500'],
            'library_resource_id' => ['nullable', 'integer', Rule::exists('library_resources', 'id')->where('tenant_id', $tenantId)],
        ]);

        return $data + ['library_resource_id' => null];
    }
}
