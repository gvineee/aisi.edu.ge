<?php

namespace App\Domain\Content\Concerns;

use App\Domain\Content\Models\ContentCategorizable;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A page or post sits in at most one site category.
 */
trait HasCategoryPlacement
{
    /**
     * @return MorphOne<ContentCategorizable, $this>
     */
    public function placement(): MorphOne
    {
        return $this->morphOne(ContentCategorizable::class, 'categorizable');
    }
}
