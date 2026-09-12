<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Post;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Post's equivalent of ChangePageStatus — see that class's docblock.
 */
class ChangePostStatus
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Post $post, bool $publish, User $actor): Post
    {
        return DB::transaction(function () use ($post, $publish, $actor) {
            /** @var Post $locked */
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            $locked->status = $publish ? Post::STATUS_PUBLISHED : Post::STATUS_DRAFT;

            // Never overwrite a real historical publish date a migration
            // importer already set (see ImportContentRecords) -- only fall
            // back to "now" when this post genuinely has no known date yet.
            // Unpublishing intentionally leaves published_at untouched: the
            // historical (or original-publish) fact doesn't change just
            // because the content is temporarily hidden.
            if ($publish) {
                $locked->published_at ??= Carbon::now();
            }

            $locked->fill(['updated_by' => $actor->id]);
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, $publish ? 'post.published' : 'post.unpublished', $locked, $actor->id, ['slug' => $locked->slug]);

            return $locked;
        });
    }
}
