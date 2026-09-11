<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostRevision;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Post's equivalent of SavePage — see that class's docblock for the
 * snapshot-every-save revisioning rationale and the deliberate exclusion of
 * status/published_at from an ordinary content edit.
 */
class SavePost
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{slug: string, locale: string, title: string, excerpt: ?string, body: string, cover_image_path: ?string, seo_title: ?string, seo_description: ?string}  $attributes
     */
    public function handle(int $tenantId, ?Post $post, array $attributes, User $actor): Post
    {
        return DB::transaction(function () use ($tenantId, $post, $attributes, $actor) {
            $isNew = $post === null;

            if ($post === null) {
                $post = new Post($attributes);
                $post->tenant_id = $tenantId;
                $post->status = Post::STATUS_DRAFT;
                $post->fill(['created_by' => $actor->id]);
            } else {
                $post->fill($attributes);
            }

            $post->fill(['updated_by' => $actor->id]);
            $post->save();

            $revision = new PostRevision([
                'post_id' => $post->id,
                'title' => $post->title,
                'excerpt' => $post->excerpt,
                'body' => $post->body,
                'cover_image_path' => $post->cover_image_path,
                'status' => $post->status,
                'seo_title' => $post->seo_title,
                'seo_description' => $post->seo_description,
                'created_by' => $actor->id,
            ]);
            $revision->tenant_id = $tenantId;
            $revision->save();

            $this->auditLogger->record($tenantId, $isNew ? 'post.created' : 'post.updated', $post, $actor->id, ['slug' => $post->slug]);

            return $post;
        });
    }
}
