<?php

namespace App\Console\Commands;

use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\ApprovalStep;
use App\Domain\Documents\Models\DocumentReviewDelegation;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Tenancy\Models\Tenant;
use App\Mail\DocumentReviewReminderMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Scheduled hourly (routes/console.php). For review-chain requests due
 * within 24 hours or already overdue, e-mails the reviewer of each step
 * that is waiting on them now (sequential: the first pending step; all:
 * every pending step) and their active delegate. At most once a day per
 * step — claimed with a conditional update so overlapping runs never send
 * twice.
 */
class SendDocumentReviewReminders extends Command
{
    protected $signature = 'documents:send-review-reminders';

    protected $description = 'Remind review-chain reviewers of document reviews that are due soon or overdue';

    public function handle(): int
    {
        $sent = 0;
        $now = Carbon::now();
        $requests = ApprovalRequest::query()->withoutGlobalScope('tenant')->where('state', ApprovalRequest::STATE_PENDING)
            ->whereNotNull('due_at')->where('due_at', '<=', $now->copy()->addDay())->get();

        foreach ($requests as $request) {
            $version = DocumentVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $request->tenant_id)
                ->with(['document' => fn ($q) => $q->withoutGlobalScope('tenant')->with(['workspace' => fn ($q) => $q->withoutGlobalScope('tenant')])])
                ->find($request->document_version_id);

            if ($version === null) {
                continue;
            }

            $pending = ApprovalStep::query()->withoutGlobalScope('tenant')->where('tenant_id', $request->tenant_id)
                ->where('approval_request_id', $request->id)->where('state', ApprovalStep::STATE_PENDING)->orderBy('position')->get();
            $waiting = $version->document->workspace->review_mode === 'sequential' ? $pending->take(1) : $pending;

            foreach ($waiting as $step) {
                $claimed = ApprovalStep::query()->withoutGlobalScope('tenant')->whereKey($step->id)->where('state', ApprovalStep::STATE_PENDING)
                    ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', $now->copy()->subDay()))
                    ->update(['reminded_at' => $now]);

                if ($claimed === 1) {
                    $sent += $this->notify($request, $step, $version->document->title, $version->document_id);
                }
            }
        }

        $this->info("Review reminders sent: {$sent}.");

        return self::SUCCESS;
    }

    private function notify(ApprovalRequest $request, ApprovalStep $step, string $title, int $documentId): int
    {
        $tenant = Tenant::query()->findOrFail($request->tenant_id);
        $reviewer = User::query()->findOrFail($step->reviewer_id);
        $dueAt = $request->due_at;

        if ($dueAt === null) {
            return 0;
        }

        $due = $dueAt->copy()->setTimezone($tenant->timezone ?: 'Asia/Tbilisi')->format('d.m.Y H:i');
        $url = $tenant->canonicalUrl("/documents/{$documentId}");
        $recipients = [[$reviewer, null]];

        $delegateIds = DocumentReviewDelegation::query()->withoutGlobalScope('tenant')->where('tenant_id', $request->tenant_id)
            ->where('delegator_id', $reviewer->id)->activeAt(Carbon::now())->pluck('delegate_id');

        foreach (User::query()->whereIn('id', $delegateIds)->get() as $delegate) {
            $recipients[] = [$delegate, $reviewer->name];
        }

        foreach ($recipients as [$user, $onBehalfOf]) {
            Mail::to($user)->queue(new DocumentReviewReminderMail($tenant->name, $title, $due, $dueAt->isPast(), $onBehalfOf, $url));
        }

        return count($recipients);
    }
}
