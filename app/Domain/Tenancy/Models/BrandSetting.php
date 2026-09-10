<?php

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $display_name
 * @property string|null $short_name
 * @property string|null $logo_path
 * @property string|null $favicon_path
 * @property array<string, string> $colors
 * @property string $font_family
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $contact_address
 * @property array<int, array<string, string>>|null $social_links
 */
#[Fillable([
    'tenant_id', 'display_name', 'short_name', 'logo_path', 'favicon_path',
    'colors', 'font_family', 'contact_email', 'contact_phone', 'contact_address', 'social_links',
])]
class BrandSetting extends Model
{
    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'social_links' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
