<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentIntent;
use App\Domain\Finance\Models\ProviderWebhookEvent;
use App\Domain\Finance\Models\Refund;
use App\Domain\Finance\Providers\PaymentProvider;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Payment intents, provider webhooks, allocations and refunds
 * (CLAUDE-PLATFORM-MODULES §12).
 *
 * - The payer's return redirect proves nothing; only a webhook with a valid
 *   signature records money.
 * - The tenant is resolved from the trusted payment intent the webhook's
 *   reference points to — never from the request.
 * - Each provider event id is processed at most once (unique key + check
 *   inside the transaction); duplicates and late/out-of-order events are
 *   recorded and ignored without changing state.
 * - Amount and currency must match the intent exactly, or the event is
 *   rejected for reconciliation.
 * - Allocation locks the invoice (and the payment) rows; over-payment stays
 *   as account credit, under-payment leaves the invoice partially paid.
 */
class ProcessPayments
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function startIntent(Invoice $invoice, PaymentProvider $provider, User $actor): PaymentIntent
    {
        if (! in_array($invoice->status, [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID], true) || $invoice->outstandingMinor() === 0) {
            throw ValidationException::withMessages(['invoice' => 'ეს ინვოისი გადასახდელი აღარ არის.']);
        }

        $intent = new PaymentIntent([
            'invoice_id' => $invoice->id,
            'provider' => $provider->name(),
            'provider_reference' => $provider->newReference(),
            'amount_minor' => $invoice->outstandingMinor(),
            'currency' => $invoice->currency,
            'status' => PaymentIntent::STATUS_CREATED,
            'created_by' => $actor->id,
        ]);
        $intent->tenant_id = $invoice->tenant_id;
        $intent->save();

        $this->auditLogger->record($invoice->tenant_id, 'payment_intent.created', $intent, $actor->id, [
            'invoice_id' => $invoice->id,
            'amount_minor' => $intent->amount_minor,
        ]);

        return $intent;
    }

    /**
     * @return string the recorded outcome
     */
    public function handleWebhook(PaymentProvider $provider, string $rawBody, ?string $signature): string
    {
        $hash = hash('sha256', $rawBody);

        if (! $provider->verifySignature($rawBody, $signature)) {
            // Not stored under the claimed event id: a forged body must never
            // be able to "use up" a real event id.
            $this->logEvent(null, $provider->name(), 'invalid_'.Str::random(20), 'unknown', $hash, false, 'rejected_signature', null);

            return 'rejected_signature';
        }

        $event = $provider->parse($rawBody);

        if ($event === null) {
            $this->logEvent(null, $provider->name(), 'unparseable_'.Str::random(20), 'unknown', $hash, true, 'unmatched', 'unparseable body');

            return 'unmatched';
        }

        try {
            return DB::transaction(function () use ($provider, $event, $hash) {
                if (ProviderWebhookEvent::query()->where('provider', $provider->name())->where('event_id', $event['event_id'])->lockForUpdate()->exists()) {
                    return 'duplicate_ignored';
                }

                [$tenantId, $outcome, $detail] = match (true) {
                    str_starts_with($event['type'], 'payment.') => $this->applyPaymentEvent($provider, $event),
                    str_starts_with($event['type'], 'refund.') => $this->applyRefundEvent($provider, $event),
                    default => [null, 'unmatched', 'unknown event type'],
                };

                $this->logEvent($tenantId, $provider->name(), $event['event_id'], $event['type'], $hash, true, $outcome, $detail);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same event won the race.
            return 'duplicate_ignored';
        }
    }

    /**
     * @param  array{event_id: string, type: string, reference: string|null, provider_payment_id: string|null, provider_refund_id: string|null, amount_minor: int|null, currency: string|null}  $event
     * @return array{0: int|null, 1: string, 2: string|null}
     */
    private function applyPaymentEvent(PaymentProvider $provider, array $event): array
    {
        $intent = $event['reference'] === null ? null : PaymentIntent::query()
            ->withoutGlobalScope('tenant')
            ->where('provider', $provider->name())
            ->where('provider_reference', $event['reference'])
            ->lockForUpdate()
            ->first();

        if ($intent === null) {
            return [null, 'unmatched', 'no payment intent for reference'];
        }

        if ($event['type'] === 'payment.failed') {
            if ($intent->status === PaymentIntent::STATUS_SUCCEEDED) {
                return [$intent->tenant_id, 'ignored_out_of_order', 'failure after success'];
            }

            $intent->status = PaymentIntent::STATUS_FAILED;
            $intent->save();

            return [$intent->tenant_id, 'processed', null];
        }

        if ($event['type'] !== 'payment.succeeded') {
            return [$intent->tenant_id, 'unmatched', 'unknown payment event'];
        }

        if ($event['amount_minor'] !== $intent->amount_minor || $event['currency'] !== $intent->currency || $event['provider_payment_id'] === null) {
            $this->auditLogger->record($intent->tenant_id, 'payment.rejected_mismatch', $intent, null, [
                'expected_minor' => $intent->amount_minor,
                'expected_currency' => $intent->currency,
                'received_minor' => $event['amount_minor'],
                'received_currency' => $event['currency'],
            ]);

            return [$intent->tenant_id, 'rejected_mismatch', 'amount/currency does not match intent'];
        }

        $alreadyRecorded = Payment::query()->withoutGlobalScope('tenant')
            ->where('provider', $provider->name())
            ->where('provider_payment_id', $event['provider_payment_id'])
            ->exists();

        if ($alreadyRecorded || $intent->status === PaymentIntent::STATUS_SUCCEEDED) {
            return [$intent->tenant_id, 'duplicate_ignored', 'payment already recorded'];
        }

        // A success may legitimately arrive after an earlier failure
        // notice (out-of-order delivery): real money wins.
        $intent->status = PaymentIntent::STATUS_SUCCEEDED;
        $intent->save();

        $invoice = Invoice::query()->withoutGlobalScope('tenant')->where('tenant_id', $intent->tenant_id)->whereKey($intent->invoice_id)->lockForUpdate()->firstOrFail();

        $payment = new Payment([
            'billing_account_id' => $invoice->billing_account_id,
            'payment_intent_id' => $intent->id,
            'provider' => $provider->name(),
            'provider_payment_id' => $event['provider_payment_id'],
            'amount_minor' => $event['amount_minor'],
            'currency' => $event['currency'],
            'unallocated_minor' => $event['amount_minor'],
            'refunded_minor' => 0,
            'received_at' => Carbon::now(),
        ]);
        $payment->tenant_id = $intent->tenant_id;
        $payment->save();

        // A payment for an invoice that was voided (or already settled) in
        // the meantime is never applied to it: the money stays as account
        // credit for the accountant to refund or reallocate.
        $invoiceOpen = in_array($invoice->status, [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID], true);

        if ($invoiceOpen) {
            $this->allocateLocked($payment, $invoice, min($payment->unallocated_minor, $invoice->outstandingMinor()), null);
        }

        $this->auditLogger->record($intent->tenant_id, $invoiceOpen ? 'payment.received' : 'payment.received_for_closed_invoice', $payment, null, [
            'invoice_id' => $invoice->id,
            'invoice_status' => $invoice->status,
            'amount_minor' => $payment->amount_minor,
            'credit_minor' => $payment->unallocated_minor,
        ]);

        return [$intent->tenant_id, 'processed', null];
    }

    /**
     * @param  array{event_id: string, type: string, reference: string|null, provider_payment_id: string|null, provider_refund_id: string|null, amount_minor: int|null, currency: string|null}  $event
     * @return array{0: int|null, 1: string, 2: string|null}
     */
    private function applyRefundEvent(PaymentProvider $provider, array $event): array
    {
        $refund = $event['provider_refund_id'] === null ? null : Refund::query()
            ->withoutGlobalScope('tenant')
            ->where('provider_refund_id', $event['provider_refund_id'])
            ->lockForUpdate()
            ->first();

        if ($refund === null) {
            return [null, 'unmatched', 'no refund for id'];
        }

        if ($refund->status !== 'requested') {
            return [$refund->tenant_id, 'ignored_out_of_order', 'refund already final'];
        }

        if ($event['type'] === 'refund.succeeded') {
            if ($event['amount_minor'] !== $refund->amount_minor || $event['currency'] !== $refund->currency) {
                return [$refund->tenant_id, 'rejected_mismatch', 'refund amount/currency mismatch'];
            }

            $refund->status = 'succeeded';
            $refund->save();

            return [$refund->tenant_id, 'processed', null];
        }

        if ($event['type'] === 'refund.failed') {
            // Money did not leave: return it to the account credit.
            $payment = Payment::query()->withoutGlobalScope('tenant')->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            $payment->unallocated_minor += $refund->amount_minor;
            $payment->refunded_minor -= $refund->amount_minor;
            $payment->save();

            $refund->status = 'failed';
            $refund->save();

            return [$refund->tenant_id, 'processed', null];
        }

        return [$refund->tenant_id, 'unmatched', 'unknown refund event'];
    }

    /**
     * Applies account credit from one payment to another open invoice of
     * the same account.
     */
    public function allocateCredit(Payment $payment, Invoice $invoice, int $amountMinor, User $actor): void
    {
        DB::transaction(function () use ($payment, $invoice, $amountMinor, $actor) {
            $lockedPayment = Payment::query()->where('tenant_id', $payment->tenant_id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $lockedInvoice = Invoice::query()->where('tenant_id', $invoice->tenant_id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->billing_account_id !== $lockedInvoice->billing_account_id || $lockedPayment->currency !== $lockedInvoice->currency) {
                throw ValidationException::withMessages(['invoice_id' => 'კრედიტი მხოლოდ იმავე ანგარიშის და ვალუტის ინვოისზე გადავა.']);
            }

            if (! in_array($lockedInvoice->status, [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID], true)) {
                throw ValidationException::withMessages(['invoice_id' => 'ინვოისი ღია არ არის.']);
            }

            if ($lockedPayment->isReversed() || $amountMinor < 1 || $amountMinor > $lockedPayment->unallocated_minor || $amountMinor > $lockedInvoice->outstandingMinor()) {
                throw ValidationException::withMessages(['amount' => 'თანხა აღემატება ხელმისაწვდომ კრედიტს ან ინვოისის დავალიანებას.']);
            }

            $this->allocateLocked($lockedPayment, $lockedInvoice, $amountMinor, $actor->id);
            $this->auditLogger->record($lockedPayment->tenant_id, 'payment.credit_allocated', $lockedPayment, $actor->id, [
                'invoice_id' => $lockedInvoice->id,
                'amount_minor' => $amountMinor,
            ]);
        });
    }

    /**
     * Refunds account credit (unallocated money) back to the payer.
     * Refunding money already applied to an invoice needs a credit-note
     * process agreed with the accountant and is deliberately not offered.
     */
    public function requestRefund(Payment $payment, int $amountMinor, string $reason, PaymentProvider $provider, User $actor): Refund
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'დაბრუნების მიზეზი სავალდებულოა.']);
        }

        return DB::transaction(function () use ($payment, $amountMinor, $reason, $provider, $actor) {
            $locked = Payment::query()->where('tenant_id', $payment->tenant_id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($amountMinor < 1 || $amountMinor > $locked->unallocated_minor) {
                throw ValidationException::withMessages(['amount' => 'დასაბრუნებელი თანხა აღემატება ანგარიშის კრედიტს.']);
            }

            $locked->unallocated_minor -= $amountMinor;
            $locked->refunded_minor += $amountMinor;
            $locked->save();

            $refund = new Refund([
                'payment_id' => $locked->id,
                'amount_minor' => $amountMinor,
                'currency' => $locked->currency,
                'reason' => trim($reason),
                'provider_refund_id' => $provider->requestRefund($locked->provider_payment_id, $amountMinor, $locked->currency),
                'status' => 'requested',
                'requested_by' => $actor->id,
            ]);
            $refund->tenant_id = $locked->tenant_id;
            $refund->save();

            $this->auditLogger->record($locked->tenant_id, 'refund.requested', $refund, $actor->id, ['payment_id' => $locked->id, 'amount_minor' => $amountMinor]);

            return $refund;
        });
    }

    /**
     * Callers must already hold row locks on both the payment and the
     * invoice inside their transaction.
     */
    public function allocateLocked(Payment $payment, Invoice $invoice, int $amountMinor, ?int $actorId): void
    {
        if ($amountMinor <= 0) {
            return;
        }

        $allocation = new PaymentAllocation(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_minor' => $amountMinor, 'allocated_by' => $actorId]);
        $allocation->tenant_id = $payment->tenant_id;
        $allocation->save();

        $payment->unallocated_minor -= $amountMinor;
        $payment->save();

        $invoice->paid_minor += $amountMinor;
        $invoice->status = $invoice->statusFromPaid();
        $invoice->save();
    }

    private function logEvent(?int $tenantId, string $provider, string $eventId, string $type, string $hash, bool $valid, string $outcome, ?string $detail): void
    {
        ProviderWebhookEvent::query()->create([
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type' => mb_substr($type, 0, 60),
            'payload_hash' => $hash,
            'signature_valid' => $valid,
            'outcome' => $outcome,
            'detail' => $detail,
            'received_at' => Carbon::now(),
        ]);
    }
}
