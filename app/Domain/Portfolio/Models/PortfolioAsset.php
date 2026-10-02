<?php

namespace App\Domain\Portfolio\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $portfolio_item_id
 * @property string $storage_path
 * @property string $checksum
 * @property string $mime
 * @property int $size
 * @property string $original_filename
 * @property string $scan_status
 */
#[Fillable(['portfolio_item_id', 'storage_path', 'checksum', 'mime', 'size', 'original_filename', 'scan_status', 'scanned_at'])]
class PortfolioAsset extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<PortfolioItem, $this>
     */
    public function portfolioItem(): BelongsTo
    {
        return $this->belongsTo(PortfolioItem::class);
    }
}
