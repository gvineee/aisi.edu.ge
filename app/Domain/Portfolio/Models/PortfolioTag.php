<?php

namespace App\Domain\Portfolio\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $portfolio_item_id
 * @property string $tag
 */
#[Fillable(['portfolio_item_id', 'tag'])]
class PortfolioTag extends Model
{
    use BelongsToTenant;

    public $timestamps = false;
}
