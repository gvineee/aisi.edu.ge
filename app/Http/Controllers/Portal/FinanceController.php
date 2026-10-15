<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Finance\Actions\ManageInvoices;
use App\Domain\Finance\Actions\NotifyInvoiceGuardians;
use App\Domain\Finance\Actions\ProcessPayments;
use App\Domain\Finance\Actions\ReconcileFinance;
use App\Domain\Finance\FinanceReports;
use App\Domain\Finance\Models\BillingAccount;
use App\Domain\Finance\Models\FinanceSetting;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\InvoiceItem;
use App\Domain\Finance\Models\InvoiceReminder;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentIntent;
use App\Domain\Finance\Models\Refund;
use App\Domain\Finance\Providers\ProviderRegistry;
use App\Domain\Operations\Models\Club;
use App\Domain\Operations\Models\ClubEnrollment;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * "ფინანსები" (CLAUDE-PLATFORM-MODULES §12). Payments are recorded by the
 * accountant (FinanceBookkeepingController); online checkout exists only
 * as a sandbox behind `finance.online_payments` until a real provider
 * contract and credentials exist. Not production-ready before accountant UAT.
 *
 * Finance staff (accountant/director/admin) need confirmed 2FA for every
 * screen (CLAUDE.md #4). Guardians see only accounts of children whose
 * active link carries `can_view_financial`.
 */
class FinanceController extends Controller
{
    private const SOURCE_CLUB_ENROLLMENT = 'club_enrollment';

    /**
     * @var array<int, string>
     */
    public const STAFF_ROLES = [
        TenantMembership::ROLE_ACCOUNTANT,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $isStaff = $this->isStaffRole($tenant->id, $user);
        $children = $this->financialChildren($tenant->id, $user);
        abort_unless($isStaff || $children->isNotEmpty(), 403);

        $staffView = null;

        $today = Carbon::now('Asia/Tbilisi');

        if ($isStaff && $user->two_factor_confirmed_at !== null) {
            $status = in_array($request->query('status'), [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID, Invoice::STATUS_PAID, Invoice::STATUS_VOID, 'overdue'], true)
                ? (string) $request->query('status') : null;
            $search = mb_substr(trim((string) $request->query('q', '')), 0, 60);

            $invoices = Invoice::query()->where('tenant_id', $tenant->id)
                ->when($status === 'overdue', fn ($query) => $query->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])->where('due_on', '<', $today->toDateString()))
                ->when($status !== null && $status !== 'overdue', fn ($query) => $query->where('status', $status))
                ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('account.student', fn ($s) => $s->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))))
                ->with('account.student')->latest('id')->limit(200)->get();

            $open = Invoice::query()->where('tenant_id', $tenant->id)->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])->get();

            $staffView = [
                'filters' => ['status' => $status, 'q' => $search],
                'summary' => [
                    'outstandingMinor' => (int) $open->sum(fn (Invoice $i) => $i->outstandingMinor()),
                    'overdueMinor' => (int) $open->filter(fn (Invoice $i) => $i->due_on->toDateString() < $today->toDateString())->sum(fn (Invoice $i) => $i->outstandingMinor()),
                    'collectedThisMonthMinor' => (int) Payment::query()->where('tenant_id', $tenant->id)->whereNull('reversed_at')
                        ->where('received_at', '>=', $today->copy()->startOfMonth()->utc())->sum('amount_minor'),
                    'openInvoices' => $open->count(),
                ],
                'hasPaymentInfo' => FinanceSetting::forTenant($tenant->id) !== null,
                'invoices' => $invoices->map(fn (Invoice $invoice) => $this->invoiceRow($invoice))->values(),
                'students' => Student::query()->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('last_name')->get()
                    ->map(fn (Student $s) => ['id' => $s->id, 'name' => $s->fullName()])->values(),
                'billableClubFees' => $this->billableClubFees($tenant->id),
            ];
        }

        return Inertia::render('portal/finance/index', [
            'onlinePayments' => (bool) config('finance.online_payments'),
            'needsMfa' => $isStaff && $user->two_factor_confirmed_at === null,
            'staff' => $staffView,
            'paymentInfo' => $children->isNotEmpty() ? FinanceSetting::forTenant($tenant->id)?->toPublicArray() : null,
            'children' => $children->map(function (Student $child) use ($tenant, $today) {
                $account = BillingAccount::query()->where('tenant_id', $tenant->id)->where('student_id', $child->id)->first();
                $invoices = $account === null ? collect() : Invoice::query()->where('tenant_id', $tenant->id)->where('billing_account_id', $account->id)
                    ->where('status', '!=', Invoice::STATUS_VOID)->latest('due_on')->latest('id')->get();
                $payments = $account === null ? collect() : Payment::query()->where('tenant_id', $tenant->id)->where('billing_account_id', $account->id)
                    ->whereNull('reversed_at')->latest('received_at')->limit(50)->get();
                $open = $invoices->filter(fn (Invoice $i) => $i->isOpen());

                return [
                    'id' => $child->id,
                    'name' => $child->fullName(),
                    'creditMinor' => (int) $payments->sum('unallocated_minor'),
                    'outstandingMinor' => (int) $open->sum(fn (Invoice $i) => $i->outstandingMinor()),
                    'overdueMinor' => (int) $open->filter(fn (Invoice $i) => $i->due_on->toDateString() < $today->toDateString())->sum(fn (Invoice $i) => $i->outstandingMinor()),
                    'invoices' => $invoices->map(fn (Invoice $invoice) => $this->invoiceRow($invoice))->values()->all(),
                    'payments' => $payments->map(fn (Payment $p) => [
                        'id' => $p->id,
                        'receiptNumber' => $p->receiptNumber(),
                        'receivedOn' => $p->received_at->copy()->setTimezone('Asia/Tbilisi')->toDateString(),
                        'methodLabel' => FinanceReports::methodLabel($p->method, $p->provider),
                        'amountMinor' => $p->amount_minor,
                        'currency' => $p->currency,
                    ])->values()->all(),
                ];
            })->values(),
        ]);
    }

    public function storeInvoice(Request $request, CurrentTenant $currentTenant, ManageInvoices $invoices, NotifyInvoiceGuardians $notifier): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeStaff($tenant->id, $request->user());

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'due_on' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.unit' => ['required', 'string', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
        ]);

        $student = Student::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['student_id'])->firstOrFail();

        $invoice = $invoices->issue(
            $invoices->accountFor($student),
            array_map(fn (array $item) => [
                'description' => $item['description'],
                'quantity' => (int) $item['quantity'],
                'unit_minor' => self::toMinor($item['unit']),
            ], $data['items']),
            Carbon::parse($data['due_on'], 'Asia/Tbilisi'),
            $request->user(),
        );
        $notifier->issued($invoice);

        return redirect()->route('finance.invoices.show', $invoice);
    }

    public function invoiceFromClub(Request $request, CurrentTenant $currentTenant, Club $club, ManageInvoices $invoices, NotifyInvoiceGuardians $notifier): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($club->tenant_id === $tenant->id, 404);
        $this->authorizeStaff($tenant->id, $request->user());
        $request->validate(['student_id' => ['required', 'integer'], 'due_on' => ['required', 'date']]);

        abort_unless($club->fee_minor !== null && $club->fee_minor > 0, 422);

        // One invoice per enrollment (until voided): the enrollment row is
        // locked so a double click cannot bill the same child twice.
        // Recurring/term club billing waits for the school's fee policy.
        $invoice = DB::transaction(function () use ($request, $tenant, $club, $invoices) {
            $enrollment = ClubEnrollment::query()->where('tenant_id', $tenant->id)->where('club_id', $club->id)
                ->where('student_id', $request->integer('student_id'))->where('status', ClubEnrollment::STATUS_ENROLLED)
                ->lockForUpdate()->firstOrFail();

            if ($this->enrollmentInvoiced($tenant->id, $enrollment)) {
                throw ValidationException::withMessages(['student_id' => 'ამ ჩარიცხვაზე ინვოისი უკვე გამოწერილია.']);
            }

            return $invoices->issue($invoices->accountFor(Student::query()->where('tenant_id', $tenant->id)->whereKey($enrollment->student_id)->firstOrFail()), [[
                'description' => "კლუბი: {$club->name}",
                'quantity' => 1,
                'unit_minor' => (int) $club->fee_minor,
                'source_type' => self::SOURCE_CLUB_ENROLLMENT,
                'source_id' => $enrollment->id,
            ]], Carbon::parse((string) $request->string('due_on'), 'Asia/Tbilisi'), $request->user());
        });
        $notifier->issued($invoice);

        return redirect()->route('finance.invoices.show', $invoice);
    }

    public function showInvoice(Request $request, CurrentTenant $currentTenant, Invoice $invoice): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        $user = $request->user();
        $invoice->load('account.student', 'items');

        $isStaff = $this->isStaffWithMfa($tenant->id, $user);
        abort_unless($isStaff || $this->financialChildren($tenant->id, $user)->contains('id', $invoice->account->student_id), 403);

        $allocations = PaymentAllocation::query()->where('tenant_id', $tenant->id)->where('invoice_id', $invoice->id)->get();
        $payments = Payment::query()->where('tenant_id', $tenant->id)->where('billing_account_id', $invoice->billing_account_id)->latest('id')->get();
        $receipts = $payments->keyBy('id');

        return Inertia::render('portal/finance/invoice', [
            'onlinePayments' => (bool) config('finance.online_payments'),
            'sandboxEnabled' => (bool) config('finance.providers.sandbox.enabled'),
            'isStaff' => $isStaff,
            'paymentInfo' => $isStaff ? null : FinanceSetting::forTenant($tenant->id)?->toPublicArray(),
            'invoice' => $this->invoiceRow($invoice) + [
                'studentId' => $invoice->account->student_id,
                'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                    'description' => $item->description, 'quantity' => $item->quantity, 'unitMinor' => $item->unit_minor, 'amountMinor' => $item->amount_minor,
                ])->values(),
                'voidReason' => $invoice->void_reason,
                'allocations' => $allocations->map(fn (PaymentAllocation $a) => [
                    'paymentId' => $a->payment_id,
                    'receiptNumber' => $receipts->get($a->payment_id)?->receiptNumber(),
                    'amountMinor' => $a->amount_minor,
                    'reversed' => $a->reversed_at !== null,
                    'createdAt' => $a->created_at?->toIso8601String(),
                ])->values(),
                'reminders' => $isStaff ? InvoiceReminder::query()->where('tenant_id', $tenant->id)->where('invoice_id', $invoice->id)->latest('sent_at')->get()
                    ->map(fn (InvoiceReminder $r) => ['sentAt' => $r->sent_at->toIso8601String(), 'recipients' => $r->recipients])->values() : [],
            ],
            'payments' => $isStaff ? $payments->map(fn (Payment $p) => [
                'id' => $p->id,
                'receiptNumber' => $p->receiptNumber(),
                'methodLabel' => FinanceReports::methodLabel($p->method, $p->provider),
                'isManual' => $p->isManual(),
                'reversed' => $p->isReversed(),
                'provider' => $p->provider,
                'amountMinor' => $p->amount_minor,
                'creditMinor' => $p->unallocated_minor,
                'refundedMinor' => $p->refunded_minor,
                'receivedAt' => $p->received_at->toIso8601String(),
                'refunds' => Refund::query()->where('tenant_id', $p->tenant_id)->where('payment_id', $p->id)->get()
                    ->map(fn (Refund $r) => ['id' => $r->id, 'amountMinor' => $r->amount_minor, 'status' => $r->status, 'method' => $r->method])->values()->all(),
            ])->values() : [],
        ]);
    }

    public function voidInvoice(Request $request, CurrentTenant $currentTenant, Invoice $invoice, ManageInvoices $invoices): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        $this->authorizeStaff($tenant->id, $request->user());
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $invoices->void($invoice, $request->string('reason')->toString(), $request->user());

        return back();
    }

    public function pay(Request $request, CurrentTenant $currentTenant, Invoice $invoice, ProcessPayments $payments, ProviderRegistry $providers): HttpResponse
    {
        $tenant = $currentTenant->get();
        // No online checkout without a real bank contract (or the sandbox
        // in a non-production environment explicitly switched on).
        abort_unless((bool) config('finance.online_payments'), 404);
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        $user = $request->user();
        $invoice->loadMissing('account');
        abort_unless($this->financialChildren($tenant->id, $user)->contains('id', $invoice->account->student_id) || $this->isStaffWithMfa($tenant->id, $user), 403);

        $provider = $providers->default();
        $intent = $payments->startIntent($invoice, $provider, $user);

        return Inertia::location($provider->checkoutUrl($intent));
    }

    /**
     * The page the payer lands on after checkout. It shows what the server
     * knows; a redirect alone is never treated as a successful payment.
     */
    public function returnPage(Request $request, CurrentTenant $currentTenant, PaymentIntent $intent): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($intent->tenant_id === $tenant->id, 404);
        abort_unless($intent->created_by === $request->user()->id || $this->isStaffWithMfa($tenant->id, $request->user()), 403);

        return Inertia::render('portal/finance/return', [
            'intent' => ['id' => $intent->id, 'status' => $intent->status, 'amountMinor' => $intent->amount_minor, 'currency' => $intent->currency, 'invoiceId' => $intent->invoice_id],
        ]);
    }

    public function allocate(Request $request, CurrentTenant $currentTenant, Payment $payment, ProcessPayments $payments): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($payment->tenant_id === $tenant->id, 404);
        $this->authorizeStaff($tenant->id, $request->user());
        $data = $request->validate(['invoice_id' => ['required', 'integer'], 'amount' => ['required', 'string', 'regex:/^\d{1,7}(\.\d{1,2})?$/']]);

        $invoice = Invoice::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['invoice_id'])->firstOrFail();
        $payments->allocateCredit($payment, $invoice, self::toMinor($data['amount']), $request->user());

        return back();
    }

    public function refund(Request $request, CurrentTenant $currentTenant, Payment $payment, ProcessPayments $payments, ProviderRegistry $providers): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($payment->tenant_id === $tenant->id, 404);
        $this->authorizeStaff($tenant->id, $request->user());
        $data = $request->validate(['amount' => ['required', 'string', 'regex:/^\d{1,7}(\.\d{1,2})?$/'], 'reason' => ['required', 'string', 'max:500']]);

        $provider = $providers->get($payment->provider) ?? throw ValidationException::withMessages(['amount' => 'პროვაიდერი მიუწვდომელია.']);
        $payments->requestRefund($payment, self::toMinor($data['amount']), $data['reason'], $provider, $request->user());

        return back();
    }

    public function reconciliation(Request $request, CurrentTenant $currentTenant, ReconcileFinance $reconcile): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeStaff($tenant->id, $request->user());

        return Inertia::render('portal/finance/reconciliation', $reconcile->handle($tenant->id) + ['onlinePayments' => (bool) config('finance.online_payments')]);
    }

    /**
     * "45.50" → 4550 without floating point.
     */
    public static function toMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'studentName' => $invoice->account?->student?->fullName(),
            'issuedOn' => $invoice->issued_on->toDateString(),
            'dueOn' => $invoice->due_on->toDateString(),
            'currency' => $invoice->currency,
            'totalMinor' => $invoice->total_minor,
            'paidMinor' => $invoice->paid_minor,
            'outstandingMinor' => $invoice->outstandingMinor(),
        ];
    }

    /**
     * @return Collection<int, Student>
     */
    private function financialChildren(int $tenantId, User $user): Collection
    {
        if (! TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_GUARDIAN)) {
            return collect();
        }

        return GuardianLink::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)
            ->where('is_active', true)->where('can_view_financial', true)
            ->with('student')->get()->pluck('student')->filter()->values();
    }

    /**
     * Enrolled students in fee-bearing clubs not yet billed by a non-void
     * invoice line.
     *
     * @return array<int, array<string, mixed>>
     */
    private function billableClubFees(int $tenantId): array
    {
        $enrollments = ClubEnrollment::query()->where('tenant_id', $tenantId)->where('status', ClubEnrollment::STATUS_ENROLLED)
            ->whereHas('club', fn ($query) => $query->where('fee_minor', '>', 0))
            ->with('club', 'student')->orderBy('id')->limit(200)->get();

        // Latest non-void invoice line per enrollment row.
        $lastBilled = InvoiceItem::query()->where('tenant_id', $tenantId)->where('source_type', self::SOURCE_CLUB_ENROLLMENT)
            ->whereIn('source_id', $enrollments->pluck('id'))
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', Invoice::STATUS_VOID))
            ->get()->groupBy('source_id')->map(fn ($lines) => $lines->max('created_at'));

        return $enrollments
            ->reject(fn (ClubEnrollment $e) => $this->billedSince($lastBilled->get($e->id), $e))
            ->map(fn (ClubEnrollment $e) => [
                'clubId' => $e->club_id,
                'clubName' => $e->club->name,
                'studentId' => $e->student_id,
                'studentName' => $e->student->fullName(),
                'feeMinor' => (int) $e->club->fee_minor,
            ])->values()->all();
    }

    /**
     * A child who leaves and rejoins reuses the same enrollment row, so only
     * a line billed since the (re)enrollment counts.
     */
    private function enrollmentInvoiced(int $tenantId, ClubEnrollment $enrollment): bool
    {
        $last = InvoiceItem::query()->where('tenant_id', $tenantId)->where('source_type', self::SOURCE_CLUB_ENROLLMENT)->where('source_id', $enrollment->id)
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', Invoice::STATUS_VOID))->max('created_at');

        return $this->billedSince($last, $enrollment);
    }

    private function billedSince(mixed $lastBilledAt, ClubEnrollment $enrollment): bool
    {
        if ($lastBilledAt === null) {
            return false;
        }

        return $enrollment->updated_at === null || Carbon::parse($lastBilledAt)->gte($enrollment->updated_at);
    }

    private function isStaffRole(int $tenantId, User $user): bool
    {
        return TenantMembership::userHasAnyActiveRole($tenantId, $user->id, self::STAFF_ROLES);
    }

    private function isStaffWithMfa(int $tenantId, User $user): bool
    {
        return $this->isStaffRole($tenantId, $user) && $user->two_factor_confirmed_at !== null;
    }

    private function authorizeStaff(int $tenantId, User $user): void
    {
        abort_unless($this->isStaffWithMfa($tenantId, $user), 403);
    }
}
