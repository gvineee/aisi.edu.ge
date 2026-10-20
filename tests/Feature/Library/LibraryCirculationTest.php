<?php

namespace Tests\Feature\Library;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Library\Models\BookListItem;
use App\Domain\Library\Models\LibraryCopy;
use App\Domain\Library\Models\LibraryLoan;
use App\Domain\Library\Models\LibraryReservation;
use App\Domain\Library\Models\LibraryResource;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Mail\LibraryNoticeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * docs/02 §5.5: class book lists and library circulation — copies, one
 * active loan per copy, due dates, renewals, the reservation queue with
 * holds, overdue reminders, the librarian role, family visibility,
 * teacher-scoped list editing, feature gating and tenant isolation.
 */
class LibraryCirculationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private SchoolClass $class;

    private SchoolClass $otherClass;

    private User $librarian;

    private User $parent;

    private User $otherParent;

    private User $teacher;

    private Student $child;

    private Student $otherChild;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        FeatureEntitlement::query()->updateOrCreate(['tenant_id' => $this->tenant->id, 'feature' => FeatureEntitlement::FEATURE_LIBRARY], ['is_enabled' => true]);

        $year = $this->tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $this->class = $this->tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'LIB-III']);
        $this->otherClass = $this->tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'LIB-IV']);

        $this->librarian = $this->member(TenantMembership::ROLE_LIBRARIAN);
        $this->parent = $this->member(TenantMembership::ROLE_GUARDIAN);
        $this->otherParent = $this->member(TenantMembership::ROLE_GUARDIAN);
        $this->teacher = $this->member(TenantMembership::ROLE_TEACHER);
        $assignment = new TeacherAssignment(['user_id' => $this->teacher->id, 'school_class_id' => $this->class->id, 'subject' => 'ქართული']);
        $assignment->tenant_id = $this->tenant->id;
        $assignment->save();

        $this->child = $this->tenant->students()->create(['school_class_id' => $this->class->id, 'first_name' => 'ანა', 'last_name' => 'მკითხველი', 'is_active' => true]);
        $this->otherChild = $this->tenant->students()->create(['school_class_id' => $this->otherClass->id, 'first_name' => 'გიო', 'last_name' => 'მკითხველი', 'is_active' => true]);
        $this->link($this->parent, $this->child);
        $this->link($this->otherParent, $this->otherChild);
    }

    private function member(string $role, ?Tenant $tenant = null): User
    {
        $user = User::factory()->create();
        TenantMembership::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

        return $user;
    }

    private function link(User $guardian, Student $student): void
    {
        $link = new GuardianLink(['user_id' => $guardian->id, 'student_id' => $student->id, 'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $this->tenant->id;
        $link->save();
    }

    private function resource(int $copies = 1): LibraryResource
    {
        $this->actingAs($this->librarian)->post('/portal/library-desk/resources', [
            'title' => 'ვეფხისტყაოსანი', 'author' => 'შოთა რუსთაველი', 'access_scope' => LibraryResource::SCOPE_LOAN, 'is_required' => true,
        ])->assertSessionHasNoErrors();
        $resource = LibraryResource::query()->latest('id')->firstOrFail();
        $this->actingAs($this->librarian)->post("/portal/library-desk/resources/{$resource->id}/copies", ['count' => $copies])->assertSessionHasNoErrors();

        return $resource;
    }

    private function lend(string $inventory, ?Student $student, int $days = 14): TestResponse
    {
        return $this->actingAs($this->librarian)->post('/portal/library-desk/loans', [
            'inventory_number' => $inventory,
            'student_id' => $student?->id,
            'due_on' => Carbon::now('Asia/Tbilisi')->addDays($days)->toDateString(),
        ]);
    }

    public function test_copies_are_numbered_and_one_copy_is_in_one_active_loan(): void
    {
        $resource = $this->resource(2);
        $copies = LibraryCopy::query()->orderBy('id')->get();
        $this->assertSame(["{$resource->id}-001", "{$resource->id}-002"], $copies->pluck('inventory_number')->all());

        $this->actingAs($this->librarian)->post("/portal/library-desk/resources/{$resource->id}/copies", ['inventory_numbers' => "{$resource->id}-001 X-9"])
            ->assertSessionHasErrors('inventory_numbers');

        $this->lend("{$resource->id}-001", $this->child)->assertSessionHasNoErrors();
        $this->lend("{$resource->id}-001", $this->otherChild)->assertSessionHasErrors('inventory_number');
        $this->assertSame(1, LibraryLoan::query()->where('status', LibraryLoan::STATUS_ACTIVE)->count());
        $this->assertSame(LibraryCopy::STATUS_ON_LOAN, $copies[0]->fresh()->status);

        $loan = LibraryLoan::query()->firstOrFail();
        $this->actingAs($this->librarian)->post("/portal/library-desk/loans/{$loan->id}/return")->assertSessionHasNoErrors();
        $this->assertSame(LibraryCopy::STATUS_AVAILABLE, $copies[0]->fresh()->status);
        $this->assertNull($loan->fresh()->active_key);
        $this->lend("{$resource->id}-001", $this->otherChild)->assertSessionHasNoErrors();
        $this->assertTrue(AuditEvent::query()->where('action', 'library_loan.returned')->exists());
    }

    public function test_reservation_queue_holds_a_returned_copy_for_the_first_in_line(): void
    {
        $resource = $this->resource(1);
        $inventory = "{$resource->id}-001";
        $this->lend($inventory, $this->otherChild)->assertSessionHasNoErrors();

        $this->actingAs($this->parent)->post('/portal/books/reservations', ['resource_id' => $resource->id, 'student_id' => $this->child->id])->assertSessionHasNoErrors();
        $this->actingAs($this->parent)->post('/portal/books/reservations', ['resource_id' => $resource->id, 'student_id' => $this->child->id])->assertSessionHasErrors('resource');
        $reservation = LibraryReservation::query()->firstOrFail();
        $this->assertSame(LibraryReservation::STATUS_WAITING, $reservation->status);

        // Someone is waiting: no renewal.
        $loan = LibraryLoan::query()->firstOrFail();
        $this->actingAs($this->librarian)->post("/portal/library-desk/loans/{$loan->id}/renew")->assertSessionHasErrors('loan');

        $this->actingAs($this->librarian)->post("/portal/library-desk/loans/{$loan->id}/return")->assertSessionHasNoErrors();
        $reservation->refresh();
        $this->assertSame(LibraryReservation::STATUS_READY, $reservation->status);
        $this->assertSame(LibraryCopy::STATUS_ON_HOLD, LibraryCopy::query()->firstOrFail()->status);
        Mail::assertQueued(LibraryNoticeMail::class, fn (LibraryNoticeMail $m) => $m->kind === LibraryNoticeMail::KIND_RESERVATION_READY && $m->hasTo($this->parent->email));

        // The held copy goes only to the reserving student.
        $this->lend($inventory, $this->otherChild)->assertSessionHasErrors('inventory_number');
        $this->lend($inventory, $this->child)->assertSessionHasNoErrors();
        $this->assertSame(LibraryReservation::STATUS_FULFILLED, $reservation->fresh()->status);
        $this->assertNull($reservation->fresh()->open_key);
    }

    public function test_an_expired_hold_passes_the_copy_on(): void
    {
        $resource = $this->resource(1);
        $this->actingAs($this->parent)->post('/portal/books/reservations', ['resource_id' => $resource->id, 'student_id' => $this->child->id])->assertSessionHasNoErrors();
        $this->actingAs($this->otherParent)->post('/portal/books/reservations', ['resource_id' => $resource->id, 'student_id' => $this->otherChild->id])->assertSessionHasNoErrors();
        [$first, $second] = LibraryReservation::query()->orderBy('id')->get()->all();
        $this->assertSame(LibraryReservation::STATUS_READY, $first->status);

        Carbon::setTestNow(Carbon::now()->addDays(LibraryReservation::HOLD_DAYS + 1));
        $this->artisan('library:run-notices')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame(LibraryReservation::STATUS_EXPIRED, $first->fresh()->status);
        $this->assertSame(LibraryReservation::STATUS_READY, $second->fresh()->status);
        $this->assertSame(LibraryCopy::STATUS_ON_HOLD, LibraryCopy::query()->firstOrFail()->status);
    }

    public function test_renewals_are_limited_and_overdue_reminders_are_throttled(): void
    {
        $resource = $this->resource(1);
        $this->lend("{$resource->id}-001", $this->child, 1)->assertSessionHasNoErrors();
        $loan = LibraryLoan::query()->firstOrFail();

        for ($i = 0; $i < LibraryLoan::MAX_RENEWALS; $i++) {
            $this->actingAs($this->librarian)->post("/portal/library-desk/loans/{$loan->id}/renew")->assertSessionHasNoErrors();
        }
        $this->actingAs($this->librarian)->post("/portal/library-desk/loans/{$loan->id}/renew")->assertSessionHasErrors('loan');
        $this->assertSame(Carbon::now('Asia/Tbilisi')->addDays(1 + 2 * 14)->toDateString(), $loan->fresh()->due_on->toDateString());

        Carbon::setTestNow(Carbon::now()->addDays(40));
        $this->artisan('library:run-notices')->assertSuccessful();
        $this->artisan('library:run-notices')->assertSuccessful();
        Mail::assertQueuedCount(1);
        Mail::assertQueued(LibraryNoticeMail::class, fn (LibraryNoticeMail $m) => $m->kind === LibraryNoticeMail::KIND_OVERDUE && $m->hasTo($this->parent->email));
        Carbon::setTestNow(Carbon::now()->addDays(4));
        $this->artisan('library:run-notices')->assertSuccessful();
        Carbon::setTestNow();
        Mail::assertQueuedCount(2);
    }

    public function test_families_see_only_their_children_and_cannot_use_the_desk(): void
    {
        $resource = $this->resource(1);
        $this->lend("{$resource->id}-001", $this->child)->assertSessionHasNoErrors();

        $this->actingAs($this->parent)->get('/portal/books')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/books/index')
            ->has('students', 1)
            ->where('students.0.name', 'ანა მკითხველი')
            ->where('students.0.loans.0.title', 'ვეფხისტყაოსანი'));

        $this->actingAs($this->parent)->post('/portal/books/reservations', ['resource_id' => $resource->id, 'student_id' => $this->otherChild->id])->assertForbidden();
        $this->actingAs($this->parent)->get('/portal/library-desk')->assertForbidden();
        $this->actingAs($this->teacher)->get('/portal/library-desk')->assertForbidden();
        $this->actingAs($this->teacher)->get('/portal/books')->assertForbidden();

        $loan = LibraryLoan::query()->firstOrFail();
        $this->actingAs($this->parent)->post("/portal/library-desk/loans/{$loan->id}/return")->assertForbidden();
    }

    public function test_book_lists_are_edited_by_the_classs_teachers_and_read_by_its_families(): void
    {
        $this->actingAs($this->teacher)->get('/portal/book-lists')->assertOk()->assertInertia(fn (Assert $page) => $page->has('classes', 1));
        $this->actingAs($this->teacher)->post("/portal/book-lists/{$this->class->id}/items", [
            'title' => 'ქართული ენა III', 'edition' => '2024', 'requirement' => 'required', 'acquisition' => 'purchase',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post("/portal/book-lists/{$this->otherClass->id}/items", [
            'title' => 'სხვა კლასი', 'requirement' => 'required', 'acquisition' => 'purchase',
        ])->assertForbidden();
        $this->actingAs($this->librarian)->post("/portal/book-lists/{$this->otherClass->id}/items", [
            'title' => 'მათემატიკა IV', 'requirement' => 'optional', 'acquisition' => 'loan',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->parent)->get('/portal/books')->assertInertia(fn (Assert $page) => $page
            ->has('students.0.bookList', 1)
            ->where('students.0.bookList.0.title', 'ქართული ენა III'));
        $this->actingAs($this->parent)->get('/portal/book-lists')->assertForbidden();

        $item = BookListItem::query()->where('school_class_id', $this->class->id)->firstOrFail();
        $this->actingAs($this->teacher)->delete("/portal/book-list-items/{$item->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($item);
        $other = BookListItem::query()->firstOrFail();
        $this->actingAs($this->teacher)->delete("/portal/book-list-items/{$other->id}")->assertForbidden();
    }

    public function test_circulation_is_closed_while_the_feature_is_off(): void
    {
        $resource = $this->resource(1);
        FeatureEntitlement::query()->where('tenant_id', $this->tenant->id)->where('feature', FeatureEntitlement::FEATURE_LIBRARY)->update(['is_enabled' => false]);

        $this->actingAs($this->librarian)->get('/portal/library-desk')->assertNotFound();
        $this->actingAs($this->parent)->post('/portal/books/reservations', ['resource_id' => $resource->id, 'student_id' => $this->child->id])->assertNotFound();
        $this->actingAs($this->parent)->get('/portal/books')->assertOk()->assertInertia(fn (Assert $page) => $page->where('libraryOn', false)->where('students.0.loans', []));
    }

    public function test_librarian_role_lands_on_the_desk_and_can_be_invited(): void
    {
        $this->actingAs($this->librarian)->get('/dashboard')->assertRedirect(route('library-desk.index'));

        $admin = $this->member(TenantMembership::ROLE_ADMIN);
        $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin)->post('/portal/members/invite', ['email' => 'librarian@school.test', 'role' => TenantMembership::ROLE_LIBRARIAN])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenant_invitations', ['email' => 'librarian@school.test', 'role' => TenantMembership::ROLE_LIBRARIAN]);
    }

    public function test_another_tenant_cannot_touch_loans_or_lists(): void
    {
        $resource = $this->resource(1);
        $this->lend("{$resource->id}-001", $this->child)->assertSessionHasNoErrors();
        $loan = LibraryLoan::query()->firstOrFail();

        $other = Tenant::create(['slug' => 'other-lib', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $other->id, 'domain' => 'other.test', 'is_primary' => true]);
        FeatureEntitlement::query()->updateOrCreate(['tenant_id' => $other->id, 'feature' => FeatureEntitlement::FEATURE_LIBRARY], ['is_enabled' => true]);
        $otherLibrarian = $this->member(TenantMembership::ROLE_LIBRARIAN, $other);

        $this->actingAs($otherLibrarian)->post("http://other.test/portal/library-desk/loans/{$loan->id}/return")->assertNotFound();
        $this->actingAs($otherLibrarian)->get("http://other.test/portal/library-desk/resources/{$resource->id}")->assertNotFound();
        $this->actingAs($otherLibrarian)->post('http://other.test/portal/library-desk/loans', [
            'inventory_number' => "{$resource->id}-001", 'student_id' => $this->child->id, 'due_on' => Carbon::now()->addDays(5)->toDateString(),
        ])->assertSessionHasErrors('borrower');
        $this->actingAs($otherLibrarian)->post("http://other.test/portal/book-lists/{$this->class->id}/items", [
            'title' => 'უცხო', 'requirement' => 'required', 'acquisition' => 'purchase',
        ])->assertNotFound();

        $this->assertSame(LibraryLoan::STATUS_ACTIVE, $loan->fresh()->status);
    }
}
