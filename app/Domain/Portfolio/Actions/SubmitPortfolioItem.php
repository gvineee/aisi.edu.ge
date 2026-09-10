<?php

namespace App\Domain\Portfolio\Actions;

use App\Domain\Portfolio\Models\PortfolioItem;
use RuntimeException;

class SubmitPortfolioItem
{
    public function handle(PortfolioItem $item): void
    {
        if (! $item->isEditableByStudent()) {
            throw new RuntimeException("Portfolio item {$item->id} cannot be submitted from status \"{$item->status}\".");
        }

        $item->status = PortfolioItem::STATUS_SUBMITTED;
        $item->save();
    }
}
