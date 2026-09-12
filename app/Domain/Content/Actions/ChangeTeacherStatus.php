<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Teacher;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeTeacherStatus
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Teacher $teacher, bool $publish, User $actor): Teacher
    {
        return DB::transaction(function () use ($teacher, $publish, $actor) {
            /** @var Teacher $locked */
            $locked = Teacher::query()->whereKey($teacher->id)->lockForUpdate()->firstOrFail();

            $locked->status = $publish ? Teacher::STATUS_PUBLISHED : Teacher::STATUS_DRAFT;
            $locked->fill(['updated_by' => $actor->id]);
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, $publish ? 'teacher.published' : 'teacher.unpublished', $locked, $actor->id, ['slug' => $locked->slug]);

            return $locked;
        });
    }
}
