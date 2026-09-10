<?php

namespace App\Domain\Portfolio\Actions;

use App\Domain\Portfolio\Models\PortfolioFeedback;
use App\Domain\Portfolio\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Teacher review decision on a submitted portfolio item. Row-locked so two
 * concurrent decisions on the same item can't both apply (same pattern as
 * Documents' DecideApproval).
 */
class DecidePortfolioItem
{
    public const DECISION_PUBLISH = 'publish';

    public const DECISION_RETURN = 'return';

    public function handle(PortfolioItem $item, User $reviewer, string $decision, ?string $feedbackBody): void
    {
        if ($decision === self::DECISION_RETURN && trim((string) $feedbackBody) === '') {
            throw ValidationException::withMessages([
                'feedback' => 'დაბრუნებისას მიზეზის მითითება სავალდებულოა.',
            ]);
        }

        DB::transaction(function () use ($item, $reviewer, $decision, $feedbackBody): void {
            /** @var PortfolioItem $locked */
            $locked = PortfolioItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PortfolioItem::STATUS_SUBMITTED) {
                throw new RuntimeException("Portfolio item {$item->id} is not pending review (status \"{$locked->status}\").");
            }

            $locked->status = $decision === self::DECISION_PUBLISH
                ? PortfolioItem::STATUS_PUBLISHED
                : PortfolioItem::STATUS_RETURNED;

            if ($decision === self::DECISION_PUBLISH) {
                $locked->published_at = Carbon::now();
            }

            $locked->save();

            if (trim((string) $feedbackBody) !== '') {
                $feedback = new PortfolioFeedback([
                    'portfolio_item_id' => $locked->id,
                    'author_id' => $reviewer->id,
                    'body' => $feedbackBody,
                ]);
                $feedback->tenant_id = $locked->tenant_id;
                $feedback->save();
            }
        });
    }
}
