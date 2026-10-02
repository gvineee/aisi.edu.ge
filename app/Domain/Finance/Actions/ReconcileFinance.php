<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentIntent;
use App\Domain\Finance\Models\ProviderWebhookEvent;

/**
 * Consistency report for the accountant: every payment must equal what was
 * allocated + what is still credit + what was refunded; every invoice's
 * paid total must equal its allocations; webhooks that were not processed
 * cleanly, and succeeded intents without a payment, are listed.
 */
class ReconcileFinance
{
    /**
     * @return array<string, mixed>
     */
    public function handle(int $tenantId): array
    {
        $allocatedByPayment = PaymentAllocation::query()->where('tenant_id', $tenantId)
            ->selectRaw('payment_id, sum(amount_minor) as aggregate')->groupBy('payment_id')->pluck('aggregate', 'payment_id');
        $allocatedByInvoice = PaymentAllocation::query()->where('tenant_id', $tenantId)
            ->selectRaw('invoice_id, sum(amount_minor) as aggregate')->groupBy('invoice_id')->pluck('aggregate', 'invoice_id');

        $payments = Payment::query()->where('tenant_id', $tenantId)->get();
        $paymentIssues = $payments
            ->filter(fn (Payment $p) => $p->amount_minor !== (int) ($allocatedByPayment[$p->id] ?? 0) + $p->unallocated_minor + $p->refunded_minor)
            ->map(fn (Payment $p) => ['paymentId' => $p->id, 'providerPaymentId' => $p->provider_payment_id])
            ->values();

        $invoiceIssues = Invoice::query()->where('tenant_id', $tenantId)->get()
            ->filter(fn (Invoice $i) => $i->paid_minor !== (int) ($allocatedByInvoice[$i->id] ?? 0))
            ->map(fn (Invoice $i) => ['invoiceId' => $i->id, 'number' => $i->number])
            ->values();

        $succeededWithoutPayment = PaymentIntent::query()->where('tenant_id', $tenantId)
            ->where('status', PaymentIntent::STATUS_SUCCEEDED)
            ->whereNotIn('id', Payment::query()->where('tenant_id', $tenantId)->whereNotNull('payment_intent_id')->select('payment_intent_id'))
            ->pluck('provider_reference');

        $events = ProviderWebhookEvent::query()->where('tenant_id', $tenantId)
            ->selectRaw('outcome, count(*) as aggregate')->groupBy('outcome')->pluck('aggregate', 'outcome');

        $attention = ProviderWebhookEvent::query()->where('tenant_id', $tenantId)
            ->whereIn('outcome', ['rejected_mismatch', 'ignored_out_of_order', 'unmatched'])
            ->latest('id')->limit(50)->get()
            ->map(fn (ProviderWebhookEvent $e) => [
                'eventId' => $e->event_id,
                'type' => $e->event_type,
                'outcome' => $e->outcome,
                'detail' => $e->detail,
                'receivedAt' => $e->received_at->toIso8601String(),
            ])->values();

        return [
            'totals' => [
                'paymentsMinor' => (int) $payments->sum('amount_minor'),
                'allocatedMinor' => (int) $allocatedByPayment->sum(),
                'creditMinor' => (int) $payments->sum('unallocated_minor'),
                'refundedMinor' => (int) $payments->sum('refunded_minor'),
                'outstandingMinor' => (int) Invoice::query()->where('tenant_id', $tenantId)->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])->get()->sum(fn (Invoice $i) => $i->outstandingMinor()),
            ],
            'paymentIssues' => $paymentIssues,
            'invoiceIssues' => $invoiceIssues,
            'succeededWithoutPayment' => $succeededWithoutPayment,
            'eventOutcomes' => $events,
            'attentionEvents' => $attention,
            'balanced' => $paymentIssues->isEmpty() && $invoiceIssues->isEmpty() && $succeededWithoutPayment->isEmpty(),
        ];
    }
}
