<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\InvoiceReminder;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Mail\InvoiceNoticeMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * E-mails an invoice's guardians: those with an active guardian
 * membership and an active link to the student carrying both financial
 * access and notifications. Reminders are throttled per invoice.
 */
class NotifyInvoiceGuardians
{
    public const REMINDER_COOLDOWN_HOURS = 72;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function issued(Invoice $invoice): int
    {
        return $this->send($invoice, false);
    }

    public function remind(Invoice $invoice, ?User $actor): int
    {
        $invoice->refresh();

        if (! $invoice->isOpen() || $invoice->outstandingMinor() === 0) {
            throw ValidationException::withMessages(['invoice' => 'ინვოისი უკვე დახურულია.']);
        }

        return DB::transaction(function () use ($invoice, $actor) {
            Invoice::query()->where('tenant_id', $invoice->tenant_id)->whereKey($invoice->id)->lockForUpdate()->first();

            $recent = InvoiceReminder::query()->where('tenant_id', $invoice->tenant_id)->where('invoice_id', $invoice->id)
                ->where('sent_at', '>=', Carbon::now()->subHours(self::REMINDER_COOLDOWN_HOURS))->exists();

            if ($recent) {
                throw ValidationException::withMessages(['invoice' => 'შეხსენება ბოლო 3 დღეში უკვე გაიგზავნა.']);
            }

            $count = $this->send($invoice, true);

            if ($count === 0) {
                throw ValidationException::withMessages(['invoice' => 'ფინანსურ წვდომიან მშობელს ელფოსტა/შეტყობინება არ აქვს ჩართული.']);
            }

            $reminder = new InvoiceReminder(['invoice_id' => $invoice->id, 'sent_by' => $actor?->id, 'recipients' => $count, 'sent_at' => Carbon::now()]);
            $reminder->tenant_id = $invoice->tenant_id;
            $reminder->save();

            $this->auditLogger->record($invoice->tenant_id, 'invoice.reminder_sent', $invoice, $actor?->id, ['number' => $invoice->number, 'recipients' => $count]);

            return $count;
        });
    }

    /**
     * Reminds every overdue open invoice not reminded within the cooldown.
     *
     * @return array{sent: int, skipped: int}
     */
    public function remindOverdue(int $tenantId, User $actor): array
    {
        $today = Carbon::now('Asia/Tbilisi')->toDateString();
        $sent = 0;
        $skipped = 0;

        Invoice::query()->where('tenant_id', $tenantId)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->where('due_on', '<', $today)->orderBy('id')
            ->each(function (Invoice $invoice) use ($actor, &$sent, &$skipped) {
                try {
                    $this->remind($invoice, $actor);
                    $sent++;
                } catch (ValidationException) {
                    $skipped++;
                }
            });

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * @return Collection<int, User>
     */
    public function recipients(Invoice $invoice): Collection
    {
        $invoice->loadMissing('account');

        $guardianIds = TenantMembership::query()->where('tenant_id', $invoice->tenant_id)
            ->where('role', TenantMembership::ROLE_GUARDIAN)->where('is_active', true)->pluck('user_id');

        return GuardianLink::query()->where('tenant_id', $invoice->tenant_id)
            ->where('student_id', $invoice->account->student_id)
            ->where('is_active', true)->where('can_view_financial', true)->where('can_receive_notifications', true)
            ->whereIn('user_id', $guardianIds)
            ->with('guardian')->get()->pluck('guardian')->filter()->unique('id')->values();
    }

    private function send(Invoice $invoice, bool $reminder): int
    {
        $invoice->loadMissing('account.student');
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);
        $recipients = $this->recipients($invoice);

        foreach ($recipients as $guardian) {
            Mail::to($guardian)->queue(new InvoiceNoticeMail(
                reminder: $reminder,
                schoolName: $tenant->name,
                studentName: (string) $invoice->account->student?->fullName(),
                invoiceNumber: $invoice->number,
                amount: number_format($invoice->outstandingMinor() / 100, 2, '.', ' ').' '.($invoice->currency === 'GEL' ? '₾' : $invoice->currency),
                dueOn: $invoice->due_on->format('d.m.Y'),
                url: route('finance.invoices.show', $invoice),
            ));
        }

        return $recipients->count();
    }
}
