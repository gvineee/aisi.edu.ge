<?php

namespace App\Domain\Content\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One image or attachment of an imported page/post, in source order.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $linkable_type
 * @property int $linkable_id
 * @property string $role
 * @property string|null $group_key
 * @property int $position
 * @property string $disk_path
 * @property string $mime
 * @property int $bytes
 * @property string $sha256
 * @property string $source_url
 * @property int|null $source_media_id
 * @property string $resolution
 * @property string|null $alt
 * @property string|null $caption
 * @property string|null $label
 */
#[Fillable([
    'linkable_type', 'linkable_id', 'role', 'group_key', 'position', 'disk_path', 'mime', 'bytes', 'sha256',
    'source_url', 'source_media_id', 'resolution', 'alt', 'caption', 'label',
])]
class ContentMediaLink extends Model
{
    use BelongsToTenant;

    public const ROLE_IMAGE = 'image';

    public const ROLE_ATTACHMENT = 'attachment';

    public function url(): string
    {
        return Storage::disk('public')->url($this->disk_path);
    }

    /**
     * Public props for a published page/post: images in order, then files.
     *
     * @return array{images: list<array<string, mixed>>, attachments: list<array<string, mixed>>}
     */
    public static function propsFor(Model $owner): array
    {
        $links = self::query()
            ->where('tenant_id', $owner->getAttribute('tenant_id'))
            ->where('linkable_type', $owner::class)
            ->where('linkable_id', $owner->getKey())
            ->orderBy('role')
            ->orderBy('position')
            ->get();

        return [
            'images' => array_values($links->where('role', self::ROLE_IMAGE)->map(fn (self $link) => [
                'url' => $link->url(),
                'alt' => $link->alt ?? '',
                'caption' => $link->caption,
                'group' => $link->group_key,
            ])->all()),
            'attachments' => array_values($links->where('role', self::ROLE_ATTACHMENT)->map(fn (self $link) => [
                'url' => $link->url(),
                'label' => $link->label ?? basename($link->disk_path),
                'mime' => $link->mime,
                'bytes' => $link->bytes,
            ])->all()),
        ];
    }
}
