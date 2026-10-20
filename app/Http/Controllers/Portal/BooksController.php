<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\Student;
use App\Domain\Library\Actions\ManageLibraryCirculation;
use App\Domain\Library\LibraryAccess;
use App\Domain\Library\Models\BookListItem;
use App\Domain\Library\Models\LibraryCopy;
use App\Domain\Library\Models\LibraryLoan;
use App\Domain\Library\Models\LibraryReservation;
use App\Domain\Library\Models\LibraryResource;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * „წიგნები“ for guardians and students: each child's class book list and,
 * when the library module is on, their loans with due dates, their
 * reservations, and the loanable catalog with availability to reserve
 * from. Only the user's own children (can_view_academic) or themselves.
 */
class BooksController extends Controller
{
    public function __construct(private readonly LibraryAccess $access) {}

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $students = $this->access->familyStudents($tenant->id, $request->user());
        abort_if($students->isEmpty(), 403);

        $libraryOn = FeatureEntitlement::isEnabled($tenant->id, FeatureEntitlement::FEATURE_LIBRARY);
        $today = Carbon::now($tenant->timezone ?: 'Asia/Tbilisi')->toDateString();
        $q = trim((string) $request->string('q'));

        $items = BookListItem::query()->where('tenant_id', $tenant->id)
            ->whereIn('school_class_id', $students->pluck('school_class_id')->filter()->unique())
            ->orderBy('position')->orderBy('id')->get()->groupBy('school_class_id');

        $loans = $libraryOn ? LibraryLoan::query()->where('tenant_id', $tenant->id)->whereIn('student_id', $students->pluck('id'))
            ->where('status', LibraryLoan::STATUS_ACTIVE)->with('copy.resource')->orderBy('due_on')->get()->groupBy('student_id') : collect();
        $reservations = $libraryOn ? LibraryReservation::query()->where('tenant_id', $tenant->id)->whereIn('student_id', $students->pluck('id'))
            ->whereIn('status', [LibraryReservation::STATUS_WAITING, LibraryReservation::STATUS_READY])->with('resource')->orderBy('id')->get()->groupBy('student_id') : collect();

        $catalog = [];

        if ($libraryOn) {
            $catalog = LibraryResource::query()->where('tenant_id', $tenant->id)->where('access_scope', LibraryResource::SCOPE_LOAN)
                ->whereHas('copies', fn ($c) => $c->whereNotIn('status', [LibraryCopy::STATUS_LOST, LibraryCopy::STATUS_WITHDRAWN]))
                ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('title', 'like', "%{$q}%")->orWhere('author', 'like', "%{$q}%")))
                ->withCount(['copies as copies_available' => fn ($c) => $c->where('status', LibraryCopy::STATUS_AVAILABLE)])
                ->orderBy('title')->limit(50)->get()
                ->map(fn (LibraryResource $r) => [
                    'id' => $r->id, 'title' => $r->title, 'author' => $r->author, 'grade' => $r->grade,
                    'available' => (int) $r->getAttribute('copies_available'),
                ])->values()->all();
        }

        return Inertia::render('portal/books/index', [
            'libraryOn' => $libraryOn,
            'query' => $q,
            'catalog' => $catalog,
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->fullName(),
                'className' => $s->schoolClass?->name,
                'bookList' => ($items->get($s->school_class_id) ?? collect())->map(fn (BookListItem $i) => BookListController::itemRow($i))->values()->all(),
                'loans' => ($loans->get($s->id) ?? collect())->map(fn (LibraryLoan $l) => [
                    'id' => $l->id, 'resourceId' => $l->copy->library_resource_id, 'title' => $l->copy->resource->title, 'dueOn' => $l->due_on->toDateString(), 'overdue' => $l->isOverdue($today),
                ])->values()->all(),
                'reservations' => ($reservations->get($s->id) ?? collect())->map(fn (LibraryReservation $r) => [
                    'id' => $r->id, 'resourceId' => $r->library_resource_id, 'title' => $r->resource->title, 'status' => $r->status, 'readyUntil' => $r->ready_until?->toIso8601String(),
                    'position' => $r->status === LibraryReservation::STATUS_WAITING
                        ? LibraryReservation::query()->where('tenant_id', $r->tenant_id)->where('library_resource_id', $r->library_resource_id)
                            ->where('status', LibraryReservation::STATUS_WAITING)->where('id', '<=', $r->id)->count()
                        : null,
                ])->values()->all(),
            ])->values(),
        ]);
    }

    public function reserve(Request $request, CurrentTenant $currentTenant, ManageLibraryCirculation $circulation): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $data = $request->validate(['resource_id' => ['required', 'integer'], 'student_id' => ['required', 'integer']]);
        $student = $this->access->familyStudents($tenant->id, $request->user())->firstWhere('id', (int) $data['student_id']);
        abort_if($student === null, 403);
        $resource = LibraryResource::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['resource_id'])->firstOrFail();

        $reservation = $circulation->reserve($resource, $student, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => $reservation->status === LibraryReservation::STATUS_READY
            ? 'წიგნი გადადებულია — აიღეთ ბიბლიოთეკაში '.LibraryReservation::HOLD_DAYS.' დღეში.'
            : 'ჩაეწერეთ რიგში; წიგნის დაბრუნებისას შეგატყობინებთ.']);
    }

    public function cancel(Request $request, CurrentTenant $currentTenant, LibraryReservation $reservation, ManageLibraryCirculation $circulation): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($reservation->tenant_id === $tenant->id, 404);
        abort_unless($this->access->familyStudents($tenant->id, $request->user())->contains('id', $reservation->student_id), 403);

        $circulation->cancelReservation($reservation, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ჯავშანი გაუქმდა.']);
    }
}
