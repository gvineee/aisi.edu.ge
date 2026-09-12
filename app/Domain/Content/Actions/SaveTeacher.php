<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\MediaFileStorage;
use App\Domain\Content\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class SaveTeacher
{
    public function __construct(private readonly MediaFileStorage $storage) {}

    /**
     * @param  array{name: string, subject: string, bio: string|null, display_order: int}  $attributes
     */
    public function handle(int $tenantId, ?Teacher $teacher, array $attributes, User $actor, ?UploadedFile $photo): Teacher
    {
        $teacher ??= new Teacher;

        if ($teacher->exists === false) {
            $teacher->tenant_id = $tenantId;
            $teacher->slug = $this->uniqueSlug($tenantId, $attributes['name']);
            $teacher->status = Teacher::STATUS_DRAFT;
            $teacher->created_by = $actor->id;
        }

        $teacher->fill([
            'name' => $attributes['name'],
            'subject' => $attributes['subject'],
            'bio' => $attributes['bio'],
            'display_order' => $attributes['display_order'],
            'updated_by' => $actor->id,
        ]);

        if ($photo !== null) {
            $stored = $this->storage->store($photo, $tenantId);
            $teacher->photo_path = $stored['path'];
        }

        $teacher->save();

        return $teacher;
    }

    /**
     * Laravel's Str::slug() only transliterates Latin scripts, which would
     * collapse every Georgian name to an empty string — this keeps letters
     * (Georgian included) and digits, and turns everything else into a
     * single hyphen, the same shape the school's own old site used for
     * teacher URLs (e.g. "/instructor/სოფიკო-ტატულაშვილი/").
     */
    private function uniqueSlug(int $tenantId, string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $base = preg_replace('/[^\p{L}\p{N}]+/u', '-', $normalized);
        $base = trim((string) $base, '-');
        $base = $base !== '' ? $base : 'teacher';
        $slug = $base;
        $suffix = 1;

        while (Teacher::query()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
