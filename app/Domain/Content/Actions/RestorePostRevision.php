<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostRevision;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Post's equivalent of RestorePageRevision — see that class's docblock for
 * why `status` is deliberately never restored.
 */
class RestorePostRevision
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Post $post, PostRevision $revision, User $actor): Post
    {
        if ($revision->post_id !== $post->id) {
            throw new RuntimeException('Revision does not belong to this post.');
        }

        return DB::transaction(function () use ($post, $revision, $actor) {
            $post->title = $revision->title;
            $post->excerpt = $revision->excerpt;
            $post->body = $revision->body;
            $post->cover_image_path = $revision->cover_image_path;
            $post->seo_title = $revision->seo_title;
            $post->seo_description = $revision->seo_description;
            $post->fill(['updated_by' => $actor->id]);
            $post->save();

            $newRevision = new PostRevision([
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
            $newRevision->tenant_id = $post->tenant_id;
            $newRevision->save();

            $this->auditLogger->record($post->tenant_id, 'post.restored', $post, $actor->id, ['restored_from_revision_id' => $revision->id]);

            return $post;
        });
    }
}
