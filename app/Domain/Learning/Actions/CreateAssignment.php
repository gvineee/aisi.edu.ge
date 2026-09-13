<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Learning\Models\Assignment;
use App\Models\User;
use Illuminate\Support\Carbon;

class CreateAssignment
{
    public function handle(
        int $tenantId,
        TeacherAssignment $teacherAssignment,
        User $creator,
        string $title,
        ?string $description,
        ?Carbon $dueAt,
        ?int $maxScore,
    ): Assignment {
        $assignment = new Assignment([
            'teacher_assignment_id' => $teacherAssignment->id,
            'title' => $title,
            'description' => $description,
            'due_at' => $dueAt,
            'max_score' => $maxScore,
            'status' => Assignment::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);
        $assignment->tenant_id = $tenantId;
        $assignment->save();

        return $assignment;
    }
}
