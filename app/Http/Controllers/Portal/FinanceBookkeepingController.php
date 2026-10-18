<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\Student;
use App\Domain\Finance\Actions\ManageCreditNotes;
use App\Domain\Finance\Actions\ManageInvoices;
use App\Domain\Finance\Actions\RecordManualPayments;
use App\Domain\Finance\FinancePdf;
use App\Domain\Finance\FinanceReports;
use App\Domain\Finance\Models\CreditNote;
use App\Domain\Finance\Models\FinanceSetting;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\Refund;
use App\Domain\Finance\Models\StudentFeeContract;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\AuthorizesFinance;
use App\Support\CsvDownload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Bookkeeping without a bank API: the payments journal, recording cash /
 * transfer / POS payments, reversing a wrong entry, paying credit back by
 * hand, credit notes correcting an issued invoice, per-student account
 * statements, and invoice/receipt/credit note PDFs.
 */
class FinanceBookkeepingController extends Controller
{
    use AuthorizesFinance;

    public function payments(Request $request, CurrentTenant $currentTenant, FinanceReports $reports): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());

        [$from, $to] = $this->period($request);
        $method = in_array($request->query('method'), Payment::METHODS, true) ? (string) $request->query('method') : null;
        $journal = $reports->paymentsJournal($tenant->id, $from, $to, $method);

        $students = Student::query()->where('tenant_id', $tenant->id)->where('is_active', true)
            ->with('schoolClass')->orderBy('last_name')->orderBy('first_name')->get();

        $openInvoices = Invoice::query()->where('tenant_id', $tenant->id)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->with('account')->orderBy('due_on')->get()
            ->groupBy(fn (Invoice $i) => $i->account->student_id)
            ->map(fn ($invoices) => $invoices->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'dueOn' => $i->due_on->toDateString(), 'outstandingMinor' => $i->outstandingMinor(),
            ])->values());

        return Inertia::render('portal/finance/payments', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'method' => $method],
            'payments' => $journal->map(fn (Payment $p) => $this->paymentRow($p))->values(),
            'totals' => [
                'receivedMinor' => (int) $journal->whereNull('reversed_at')->sum('amount_minor'),
                'count' => $journal->whereNull('reversed_at')->count(),
            ],
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->fullName(),
                'className' => $s->schoolClass?->name,
            ])->values(),
            'openInvoices' => $openInvoices,
            'preselectStudentId' => $request->integer('student') ?: null,
            'preselectInvoiceId' => $request->integer('invoice') ?: null,
        ]);
    }

    public function storePayment(Request $request, CurrentTenant $currentTenant, RecordManualPayments $action, ManageInvoices $invoices): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'invoice_id' => ['nullable', 'integer'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $student = Student::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['student_id'])->firstOrFail();

        $payment = $action->record(
            $invoices->accountFor($student),
            self::moneyToMinor($data['amount']),
            $data['method'],
            Carbon::parse($data['received_on'], 'Asia/Tbilisi'),
            $data['reference'] ?? null,
            $data['note'] ?? null,
            isset($data['invoice_id']) ? (int) $data['invoice_id'] : null,
            $data['idempotency_key'],
            $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "გადახდა გატარდა — ქვითარი {$payment->receiptNumber()}".($payment->unallocated_minor > 0 ? ' (ნაწილი კრედიტად დარჩა).' : '.'),
        ]);
    }

    public function reverse(Request $request, CurrentTenant $currentTenant, Payment $payment, RecordManualPayments $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($payment->tenant_id === $tenant->id, 404);
        $this->authorizeFinanceStaff($tenant->id, $request->user());
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $action->reverse($payment, $data['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'გატარება გაუქმდა, ინვოისები ხელახლა გაიხსნა.']);
    }

    public function refundCredit(Request $request, CurrentTenant $currentTenant, Payment $payment, RecordManualPayments $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($payment->tenant_id === $tenant->id, 404);
        $this->authorizeFinanceStaff($tenant->id, $request->user());
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'method' => ['required', Rule::in([Payment::METHOD_CASH, Payment::METHOD_BANK_TRANSFER])],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $action->refundCredit($payment, self::moneyToMinor($data['amount']), $data['method'], $data['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'თანხის დაბრუნება აღირიცხა.']);
    }

    public function storeCreditNote(Request $request, CurrentTenant $currentTenant, Invoice $invoice, ManageCreditNotes $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        $this->authorizeFinanceStaff($tenant->id, $request->user());
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotency_key' => ['required', 'string', 'uuid'],
        ]);

        $creditNote = $action->issue($invoice, self::moneyToMinor($data['amount']), $data['reason'], $data['idempotency_key'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "საკრედიტო ნოტა № {$creditNote->number} გამოიწერა".($creditNote->released_minor > 0 ? ' — ზედმეტად გადახდილი თანხა ანგარიშის კრედიტზე გადავიდა.' : '.'),
        ]);
    }

    public function creditNotePdf(Request $request, CurrentTenant $currentTenant, CreditNote $creditNote, FinancePdf $pdf): HttpResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($creditNote->tenant_id === $tenant->id, 404);
        $user = $request->user();
        $creditNote->load('invoice', 'account.student.schoolClass');
        abort_unless($this->isFinanceStaff($tenant->id, $user) || $this->financialChildrenOf($tenant->id, $user)->contains('id', $creditNote->account->student_id), 403);

        $money = fn (int $minor) => FinanceReports::money($minor).' '.($creditNote->currency === 'GEL' ? '₾' : $creditNote->currency);

        $content = $pdf->render('pdf.credit-note', [
            'schoolName' => $tenant->name,
            'generatedAt' => Carbon::now('Asia/Tbilisi')->format('d.m.Y H:i'),
            'settings' => FinanceSetting::forTenant($tenant->id)?->toPublicArray(),
            'creditNote' => [
                'number' => $creditNote->number,
                'issuedOn' => $creditNote->issued_on->format('d.m.Y'),
                'invoiceNumber' => $creditNote->invoice->number,
                'invoiceIssuedOn' => $creditNote->invoice->issued_on->format('d.m.Y'),
                'studentName' => $creditNote->account->student?->fullName(),
                'className' => $creditNote->account->student?->schoolClass?->name,
                'reason' => $creditNote->reason,
                'amount' => $money($creditNote->amount_minor),
                'released' => $creditNote->released_minor > 0 ? $money($creditNote->released_minor) : null,
            ],
        ]);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="credit-note-'.$creditNote->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function statement(Request $request, CurrentTenant $currentTenant, Student $student): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        $this->authorizeFinanceStaff($tenant->id, $request->user());
        $student->load('schoolClass', 'guardianLinks.guardian');

        $invoiceRows = Invoice::query()->where('tenant_id', $tenant->id)
            ->whereHas('account', fn ($q) => $q->where('student_id', $student->id))->latest('issued_on')->latest('id')->get();
        $payments = Payment::query()->where('tenant_id', $tenant->id)
            ->whereHas('account', fn ($q) => $q->where('student_id', $student->id))->with('account.student')->latest('received_at')->get();
        $contracts = StudentFeeContract::query()->where('tenant_id', $tenant->id)->where('student_id', $student->id)->with('plan')->get();
        $open = $invoiceRows->filter(fn (Invoice $i) => $i->isOpen());
        $today = Carbon::now('Asia/Tbilisi')->toDateString();

        return Inertia::render('portal/finance/statement', [
            'student' => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'className' => $student->schoolClass?->name,
                'isActive' => $student->is_active,
                'guardians' => $student->guardianLinks->where('is_active', true)->map(fn ($link) => [
                    'name' => $link->guardian?->name,
                    'email' => $link->guardian?->email,
                    'financial' => $link->can_view_financial,
                ])->values(),
            ],
            'summary' => [
                'invoicedMinor' => (int) $invoiceRows->where('status', '!=', Invoice::STATUS_VOID)->sum(fn (Invoice $i) => $i->netMinor()),
                'paidMinor' => (int) $invoiceRows->where('status', '!=', Invoice::STATUS_VOID)->sum('paid_minor'),
                'outstandingMinor' => (int) $open->sum(fn (Invoice $i) => $i->outstandingMinor()),
                'overdueMinor' => (int) $open->filter(fn (Invoice $i) => $i->due_on->toDateString() < $today)->sum(fn (Invoice $i) => $i->outstandingMinor()),
                'creditMinor' => (int) $payments->sum('unallocated_minor'),
            ],
            'invoices' => $invoiceRows->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'status' => $i->status, 'issuedOn' => $i->issued_on->toDateString(),
                'dueOn' => $i->due_on->toDateString(), 'totalMinor' => $i->total_minor, 'creditedMinor' => $i->credited_minor, 'paidMinor' => $i->paid_minor,
                'outstandingMinor' => $i->outstandingMinor(), 'currency' => $i->currency,
            ])->values(),
            'payments' => $payments->map(fn (Payment $p) => $this->paymentRow($p))->values(),
            'openInvoices' => $open->map(fn (Invoice $i) => ['id' => $i->id, 'number' => $i->number, 'outstandingMinor' => $i->outstandingMinor()])->values(),
            'contracts' => $contracts->map(fn (StudentFeeContract $c) => [
                'id' => $c->id, 'planName' => $c->plan->name, 'status' => $c->status, 'discountPercent' => $c->discount_percent,
                'discountReason' => $c->discount_reason, 'contractNumber' => $c->contract_number, 'signedOn' => $c->signed_on?->toDateString(),
            ])->values(),
        ]);
    }

    public function exportPayments(Request $request, CurrentTenant $currentTenant, FinanceReports $reports, AuditLogger $audit): HttpResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());
        [$from, $to] = $this->period($request);
        $rows = $reports->paymentsJournal($tenant->id, $from, $to);

        $audit->record($tenant->id, 'finance.exported', $tenant, $request->user()->id, ['report' => 'payments', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows->count()]);

        return CsvDownload::make("payments-{$from->toDateString()}-{$to->toDateString()}.csv",
            ['ქვითარი', 'თარიღი', 'მოსწავლე', 'კლასი', 'მეთოდი', 'საბუთი', 'თანხა', 'კრედიტი', 'დაბრუნებული', 'სტატუსი', 'შენიშვნა'],
            $rows->map(fn (Payment $p) => [
                $p->receiptNumber(),
                $p->received_at->copy()->setTimezone('Asia/Tbilisi')->toDateString(),
                $p->account?->student?->fullName(),
                $p->account?->student?->schoolClass?->name,
                FinanceReports::methodLabel($p->method, $p->provider),
                $p->reference,
                FinanceReports::money($p->amount_minor),
                FinanceReports::money($p->unallocated_minor),
                FinanceReports::money($p->refunded_minor),
                $p->isReversed() ? 'გაუქმებული' : 'აქტიური',
                $p->note,
            ]));
    }

    public function invoicePdf(Request $request, CurrentTenant $currentTenant, Invoice $invoice, FinancePdf $pdf): HttpResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        $user = $request->user();
        $invoice->load('account.student.schoolClass', 'items');
        abort_unless($this->isFinanceStaff($tenant->id, $user) || $this->financialChildrenOf($tenant->id, $user)->contains('id', $invoice->account->student_id), 403);

        $money = fn (int $minor) => FinanceReports::money($minor).' '.($invoice->currency === 'GEL' ? '₾' : $invoice->currency);

        $content = $pdf->render('pdf.invoice', [
            'schoolName' => $tenant->name,
            'generatedAt' => Carbon::now('Asia/Tbilisi')->format('d.m.Y H:i'),
            'settings' => FinanceSetting::forTenant($tenant->id)?->toPublicArray(),
            'invoice' => [
                'number' => $invoice->number,
                'status' => $invoice->status,
                'voidReason' => $invoice->void_reason,
                'studentName' => $invoice->account->student?->fullName(),
                'className' => $invoice->account->student?->schoolClass?->name,
                'issuedOn' => $invoice->issued_on->format('d.m.Y'),
                'dueOn' => $invoice->due_on->format('d.m.Y'),
                'items' => $invoice->items->map(fn ($item) => [
                    'description' => $item->description, 'quantity' => $item->quantity, 'unit' => $money($item->unit_minor), 'amount' => $money($item->amount_minor),
                ])->all(),
                'total' => $money($invoice->total_minor),
                'credited' => $invoice->credited_minor > 0 ? $money($invoice->credited_minor) : null,
                'creditNotes' => $invoice->creditNotes()->orderBy('id')->get()->map(fn (CreditNote $c) => $c->number.' ('.$c->issued_on->format('d.m.Y').')')->all(),
                'paid' => $money($invoice->paid_minor),
                'outstanding' => $money($invoice->outstandingMinor()),
            ],
        ]);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoice-'.$invoice->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function receiptPdf(Request $request, CurrentTenant $currentTenant, Payment $payment, FinancePdf $pdf): HttpResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($payment->tenant_id === $tenant->id, 404);
        $user = $request->user();
        $payment->load('account.student');
        abort_unless($this->isFinanceStaff($tenant->id, $user) || $this->financialChildrenOf($tenant->id, $user)->contains('id', $payment->account->student_id), 403);

        $money = fn (int $minor) => FinanceReports::money($minor).' '.($payment->currency === 'GEL' ? '₾' : $payment->currency);
        $allocations = PaymentAllocation::query()->where('tenant_id', $tenant->id)->where('payment_id', $payment->id)->active()->get();
        $numbers = Invoice::query()->where('tenant_id', $tenant->id)->whereIn('id', $allocations->pluck('invoice_id'))->pluck('number', 'id');

        $content = $pdf->render('pdf.receipt', [
            'schoolName' => $tenant->name,
            'generatedAt' => Carbon::now('Asia/Tbilisi')->format('d.m.Y H:i'),
            'receipt' => [
                'number' => $payment->receiptNumber(),
                'reversed' => $payment->isReversed(),
                'studentName' => $payment->account->student?->fullName(),
                'receivedOn' => $payment->received_at->copy()->setTimezone('Asia/Tbilisi')->format('d.m.Y'),
                'method' => FinanceReports::methodLabel($payment->method, $payment->provider),
                'reference' => $payment->reference,
                'amount' => $money($payment->amount_minor),
                'allocations' => $allocations->map(fn (PaymentAllocation $a) => ['number' => $numbers[$a->invoice_id] ?? '', 'amount' => $money($a->amount_minor)])->all(),
                'credit' => $payment->unallocated_minor > 0 ? $money($payment->unallocated_minor) : null,
            ],
        ]);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="receipt-'.$payment->receiptNumber().'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRow(Payment $p): array
    {
        return [
            'id' => $p->id,
            'receiptNumber' => $p->receiptNumber(),
            'studentId' => $p->account?->student_id,
            'studentName' => $p->account?->student?->fullName(),
            'className' => $p->account?->student?->schoolClass?->name,
            'receivedOn' => $p->received_at->copy()->setTimezone('Asia/Tbilisi')->toDateString(),
            'method' => $p->method,
            'methodLabel' => FinanceReports::methodLabel($p->method, $p->provider),
            'reference' => $p->reference,
            'note' => $p->note,
            'amountMinor' => $p->amount_minor,
            'creditMinor' => $p->unallocated_minor,
            'refundedMinor' => $p->refunded_minor,
            'currency' => $p->currency,
            'isManual' => $p->isManual(),
            'reversed' => $p->isReversed(),
            'reversalReason' => $p->reversal_reason,
            'refunds' => Refund::query()->where('tenant_id', $p->tenant_id)->where('payment_id', $p->id)->get()
                ->map(fn (Refund $r) => ['id' => $r->id, 'amountMinor' => $r->amount_minor, 'status' => $r->status, 'method' => $r->method])->values()->all(),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $now = Carbon::now('Asia/Tbilisi');
        $from = $this->date($request->query('from')) ?? $now->copy()->startOfMonth();
        $to = $this->date($request->query('to')) ?? $now->copy()->endOfMonth();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        // Keep exports bounded.
        if ($from->diffInDays($to) > 400) {
            $from = $to->copy()->subDays(400);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $value, 'Asia/Tbilisi') ?: null;
    }
}
