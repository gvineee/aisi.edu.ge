<?php

namespace App\Domain\Portfolio\Actions;

use App\Domain\Portfolio\Models\PortfolioAsset;
use App\Domain\Portfolio\Models\PortfolioItem;
use App\Domain\Portfolio\PortfolioFileStorage;
use App\Domain\Uploads\ScanStatus;
use App\Jobs\ScanUploadedFile;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class AddPortfolioAsset
{
    public function __construct(private readonly PortfolioFileStorage $storage) {}

    public function handle(PortfolioItem $item, UploadedFile $file): PortfolioAsset
    {
        if (! $item->isEditableByStudent()) {
            throw new RuntimeException("Portfolio item {$item->id} is not editable in status \"{$item->status}\".");
        }

        $stored = $this->storage->store($file, $item->tenant_id, $item->id);

        $asset = new PortfolioAsset($stored);
        $asset->tenant_id = $item->tenant_id;
        $asset->portfolio_item_id = $item->id;
        $asset->scan_status = ScanStatus::PENDING;
        $asset->save();

        ScanUploadedFile::dispatch('portfolio_assets', $asset->id, $asset->storage_path);

        return $asset;
    }
}
