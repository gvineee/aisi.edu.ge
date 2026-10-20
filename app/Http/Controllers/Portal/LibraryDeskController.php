<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\Library\Actions\ManageLibraryCirculation;
use App\Domain\Library\LibraryAccess;
use App\Domain\Library\Models\LibraryCopy;
use App\Domain\Library\Models\LibraryLoan;
use App\Domain\Library\Models\LibraryReservation;
use App\Domain\Library\Models\LibraryResource;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * „ბიბლიოთეკა“ — the librarian's desk (feature:library): lend by inventory
 * number, return, renew, mark lost; the overdue list; the reservation
 * queue; the catalog with copies. Every action re-checks the desk role.
 */
class LibraryDeskController extends Controller
{
    public function __construct(private readonly LibraryAccess $access) {}

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeDesk($tenant->id, $request->user());
        $tab = in_array($request->query('tab'), ['loans', 'catalog', 'reservations'], true) ? (string) $request->query('tab') : 'loans';
        $q = trim((string) $request->string('q'));
        $overdueOnly = $request->boolean('overdue');
        $today = Carbon::now($tenant->timezone ?: 'Asia/Tbilisi')->toDateString();

        $activeLoans = LibraryLoan::query()->where('tenant_id', $tenant->id)->where('status', LibraryLoan::STATUS_ACTIVE);

        $props = [
            'tab' => $tab,
            'filters' => ['q' => $q, 'overdue' => $overdueOnly],
            'today' => $today,
            'loanDays' => ManageLibraryCirculation::LOAN_DAYS,
            'counts' => [
                'active' => (clone $activeLoans)->count(),
                'overdue' => (clone $activeLoans)->whereDate('due_on', '<', $today)->count(),
                'ready' => LibraryReservation::query()->where('tenant_id', $tenant->id)->where('status', LibraryReservation::STATUS_READY)->count(),
                'waiting' => LibraryReservation::query()->where('tenant_id', $tenant->id)->where('status', LibraryReservation::STATUS_WAITING)->count(),
            ],
            'students' => Student::query()->where('tenant_id', $tenant->id)->where('is_active', true)->with('schoolClass')
                ->orderBy('last_name')->orderBy('first_name')->get()
                ->map(fn (Student $s) => ['id' => $s->id, 'name' => $s->fullName(), 'className' => $s->schoolClass?->name])->values(),
            'staff' => User::query()->whereIn('id', TenantMembership::query()->where('tenant_id', $tenant->id)->where('is_active', true)
                ->whereNotIn('role', [TenantMembership::ROLE_GUARDIAN, TenantMembership::ROLE_STUDENT])->select('user_id'))
                ->orderBy('name')->get(['id', 'name'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'loans' => [],
            'resources' => [],
            'reservations' => [],
        ];

        if ($tab === 'loans') {
            $props['loans'] = (clone $activeLoans)
                ->when($overdueOnly, fn ($query) => $query->whereDate('due_on', '<', $today))
                ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner
                    ->whereHas('copy', fn ($c) => $c->where('inventory_number', 'like', "%{$q}%")->orWhereHas('resource', fn ($r) => $r->where('title', 'like', "%{$q}%")))
                    ->orWhereHas('student', fn ($s) => $s->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%"))))
                ->with(['copy.resource', 'student.schoolClass', 'user'])->orderBy('due_on')->limit(200)->get()
                ->map(fn (LibraryLoan $loan) => $this->loanRow($loan, $today))->values();
        }

        if ($tab === 'catalog') {
            $props['resources'] = LibraryResource::query()->where('tenant_id', $tenant->id)
                ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('title', 'like', "%{$q}%")->orWhere('author', 'like', "%{$q}%")->orWhere('isbn', 'like', "%{$q}%")))
                ->withCount([
                    'copies as copies_total' => fn ($c) => $c->whereNotIn('status', [LibraryCopy::STATUS_LOST, LibraryCopy::STATUS_WITHDRAWN]),
                    'copies as copies_available' => fn ($c) => $c->where('status', LibraryCopy::STATUS_AVAILABLE),
                ])
                ->orderBy('title')->limit(200)->get()
                ->map(fn (LibraryResource $r) => [
                    'id' => $r->id, 'title' => $r->title, 'author' => $r->author, 'grade' => $r->grade, 'subject' => $r->subject,
                    'accessScope' => $r->access_scope, 'copiesTotal' => (int) $r->getAttribute('copies_total'), 'copiesAvailable' => (int) $r->getAttribute('copies_available'),
                ])->values();
        }

        if ($tab === 'reservations') {
            $props['reservations'] = LibraryReservation::query()->where('tenant_id', $tenant->id)
                ->whereIn('status', [LibraryReservation::STATUS_READY, LibraryReservation::STATUS_WAITING])
                ->with(['resource', 'student.schoolClass', 'copy'])->orderByRaw("case when status = 'ready' then 0 else 1 end")->orderBy('id')->get()
                ->map(fn (LibraryReservation $r) => $this->reservationRow($r))->values();
        }

        return Inertia::render('portal/library-desk/index', $props);
    }

    public function showResource(Request $request, CurrentTenant $currentTenant, LibraryResource $resource): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($resource->tenant_id === $tenant->id, 404);
        $this->authorizeDesk($tenant->id, $request->user());
        $today = Carbon::now($tenant->timezone ?: 'Asia/Tbilisi')->toDateString();

        $copies = LibraryCopy::query()->where('tenant_id', $tenant->id)->where('library_resource_id', $resource->id)->orderBy('id')->get();
        $loans = LibraryLoan::query()->where('tenant_id', $tenant->id)->whereIn('library_copy_id', $copies->pluck('id'))
            ->with(['copy.resource', 'student.schoolClass', 'user'])->latest('id')->limit(50)->get();
        $activeByCopy = $loans->where('status', LibraryLoan::STATUS_ACTIVE)->keyBy('library_copy_id');

        return Inertia::render('portal/library-desk/resource', [
            'resource' => [
                'id' => $resource->id, 'title' => $resource->title, 'author' => $resource->author, 'isbn' => $resource->isbn,
                'grade' => $resource->grade, 'subject' => $resource->subject, 'isRequired' => $resource->is_required,
                'accessScope' => $resource->access_scope, 'externalUrl' => $resource->external_url,
            ],
            'copies' => $copies->map(fn (LibraryCopy $c) => [
                'id' => $c->id, 'inventoryNumber' => $c->inventory_number, 'status' => $c->status,
                'borrower' => $activeByCopy->get($c->id)?->borrowerName(),
                'dueOn' => $activeByCopy->get($c->id)?->due_on->toDateString(),
            ])->values(),
            'loans' => $loans->map(fn (LibraryLoan $l) => $this->loanRow($l, $today))->values(),
            'reservations' => LibraryReservation::query()->where('tenant_id', $tenant->id)->where('library_resource_id', $resource->id)
                ->whereIn('status', [LibraryReservation::STATUS_READY, LibraryReservation::STATUS_WAITING])
                ->with(['resource', 'student.schoolClass', 'copy'])->orderBy('id')->get()
                ->map(fn (LibraryReservation $r) => $this->reservationRow($r))->values(),
        ]);
    }

    public function storeResource(Request $request, CurrentTenant $currentTenant, AuditLogger $audit): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeDesk($tenant->id, $request->user());

        $resource = new LibraryResource($this->validatedResource($request));
        $resource->tenant_id = $tenant->id;
        $resource->save();
        $audit->record($tenant->id, 'library_resource.created', $resource, $request->user()->id, ['title' => $resource->title]);

        return redirect()->route('library-desk.resources.show', $resource)->with('toast', ['type' => 'success', 'message' => 'წიგნი კატალოგს დაემატა.']);
    }

    public function updateResource(Request $request, CurrentTenant $currentTenant, LibraryResource $resource, AuditLogger $audit): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($resource->tenant_id === $tenant->id, 404);
        $this->authorizeDesk($tenant->id, $request->user());

        $resource->fill($this->validatedResource($request))->save();
        $audit->record($tenant->id, 'library_resource.updated', $resource, $request->user()->id, ['changed' => array_keys($resource->getChanges())]);

        return back()->with('toast', ['type' => 'success', 'message' => 'შენახულია.']);
    }

    public function storeCopies(Request $request, CurrentTenant $currentTenant, LibraryResource $resource, ManageLibraryCirculation $circulation): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($resource->tenant_id === $tenant->id, 404);
        $this->authorizeDesk($tenant->id, $request->user());
        $data = $request->validate([
            'inventory_numbers' => ['nullable', 'string', 'max:4000'],
            'count' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $numbers = preg_split('/[\s,;]+/u', (string) ($data['inventory_numbers'] ?? '')) ?: [];
        $copies = $circulation->addCopies($resource, $numbers, (int) ($data['count'] ?? 1), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'დაემატა '.count($copies).' ეგზემპლარი.']);
    }

    public function withdrawCopy(Request $request, CurrentTenant $currentTenant, LibraryCopy $copy, ManageLibraryCirculation $circulation): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($copy->tenant_id === $tenant->id, 404);
        $this->authorizeDesk($tenant->id, $request->user());
        $circulation->withdrawCopy($copy, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ეგზემპლარი ჩამოიწერა.']);
    }

    public function lend(Request $request, CurrentTenant $currentTenant, ManageLibraryCirculation $circulation): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeDesk($tenant->id, $request->user());
        $data = $request->validate([
            'inventory_number' => ['required', 'string', 'max:40'],
            'student_id' => ['nullable', 'integer', 'required_without:user_id'],
            'user_id' => ['nullable', 'integer', 'required_without:student_id'],
            'due_on' => ['required', 'date_format:Y-m-d'],
        ]);

        $student = isset($data['student_id']) ? Student::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['student_id'])->first() : null;
        $staff = null;

        if (isset($data['user_id'])) {
            $isStaff = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $data['user_id'])->where('is_active', true)
                ->whereNotIn('role', [TenantMembership::ROLE_GUARDIAN, TenantMembership::ROLE_STUDENT])->exists();
            $staff = $isStaff ? User::query()->whereKey((int) $data['user_id'])->first() : null;
        }

        if ($student === null && $staff === null) {
            return back()->withErrors(['borrower' => 'მსესხებელი ვერ მოიძებნა.']);
        }

        $loan = $circulation->lend($tenant, $data['inventory_number'], $student, $student === null ? $staff : null,
            Carbon::createFromFormat('Y-m-d', $data['due_on'], $tenant->timezone ?: 'Asia/Tbilisi')->startOfDay(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'გაცემულია; დაბრუნების ვადა '.$loan->due_on->format('d.m.Y').'.']);
    }

    public function returnLoan(Request $request, CurrentTenant $currentTenant, LibraryLoan $loan, ManageLibraryCirculation $circulation): RedirectResponse
    {
        $this->authorizeLoan($request, $currentTenant, $loan);
        $circulation->returnLoan($loan, $request->user());
        $held = LibraryReservation::query()->where('tenant_id', $loan->tenant_id)->where('library_copy_id', $loan->library_copy_id)
            ->where('status', LibraryReservation::STATUS_READY)->with('student')->first();

        return back()->with('toast', ['type' => 'success', 'message' => $held !== null
            ? 'დაბრუნდა. ეგზემპლარი გადადეთ: '.$held->student->fullName().' (ჯავშანი).'
            : 'დაბრუნდა.']);
    }

    public function renew(Request $request, CurrentTenant $currentTenant, LibraryLoan $loan, ManageLibraryCirculation $circulation): RedirectResponse
    {
        $this->authorizeLoan($request, $currentTenant, $loan);
        $circulation->renew($loan, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ვადა გაგრძელდა: '.$loan->due_on->format('d.m.Y').'.']);
    }

    public function lost(Request $request, CurrentTenant $currentTenant, LibraryLoan $loan, ManageLibraryCirculation $circulation): RedirectResponse
    {
        $this->authorizeLoan($request, $currentTenant, $loan);
        $circulation->markLost($loan, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ეგზემპლარი დაკარგულად მოინიშნა.']);
    }

    public function cancelReservation(Request $request, CurrentTenant $currentTenant, LibraryReservation $reservation, ManageLibraryCirculation $circulation): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($reservation->tenant_id === $tenant->id, 404);
        $this->authorizeDesk($tenant->id, $request->user());
        $circulation->cancelReservation($reservation, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ჯავშანი გაუქმდა.']);
    }

    private function authorizeDesk(int $tenantId, User $user): void
    {
        abort_unless($this->access->isDesk($tenantId, $user), 403);
    }

    private function authorizeLoan(Request $request, CurrentTenant $currentTenant, LibraryLoan $loan): void
    {
        $tenant = $currentTenant->get();
        abort_unless($loan->tenant_id === $tenant->id, 404);
        $this->authorizeDesk($tenant->id, $request->user());
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedResource(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:20', 'regex:/^[0-9Xx-]+$/'],
            'grade' => ['nullable', 'string', 'max:60'],
            'subject' => ['nullable', 'string', 'max:120'],
            'is_required' => ['boolean'],
            'access_scope' => ['required', Rule::in([LibraryResource::SCOPE_CATALOG_ONLY, LibraryResource::SCOPE_LOAN, LibraryResource::SCOPE_DIGITAL])],
            'external_url' => ['nullable', 'url:https,http', 'max:2048'],
        ]);

        return $data + ['is_required' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function loanRow(LibraryLoan $loan, string $today): array
    {
        return [
            'id' => $loan->id,
            'title' => $loan->copy->resource->title,
            'resourceId' => $loan->copy->library_resource_id,
            'inventoryNumber' => $loan->copy->inventory_number,
            'borrower' => $loan->borrowerName(),
            'className' => $loan->student?->schoolClass?->name,
            'isStaff' => $loan->student_id === null,
            'lentOn' => $loan->created_at?->toIso8601String(),
            'dueOn' => $loan->due_on->toDateString(),
            'status' => $loan->status,
            'overdue' => $loan->isOverdue($today),
            'renewals' => $loan->renewals,
            'canRenew' => $loan->status === LibraryLoan::STATUS_ACTIVE && $loan->renewals < LibraryLoan::MAX_RENEWALS,
            'returnedAt' => $loan->returned_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reservationRow(LibraryReservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'title' => $reservation->resource->title,
            'resourceId' => $reservation->library_resource_id,
            'student' => $reservation->student->fullName(),
            'className' => $reservation->student->schoolClass?->name,
            'status' => $reservation->status,
            'inventoryNumber' => $reservation->copy?->inventory_number,
            'readyUntil' => $reservation->ready_until?->toIso8601String(),
            'createdAt' => $reservation->created_at?->toIso8601String(),
        ];
    }
}
