<?php

namespace App\Domain\Portfolio\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Portfolio\Models\PortfolioItem;
use App\Models\User;

class CreatePortfolioItem
{
    public function handle(int $tenantId, Student $student, User $creator, string $title, ?string $description, ?int $subjectId): PortfolioItem
    {
        $item = new PortfolioItem([
            'student_id' => $student->id,
            'subject_id' => $subjectId,
            'title' => $title,
            'description' => $description,
            'status' => PortfolioItem::STATUS_DRAFT,
            'visibility' => 'private',
            'created_by' => $creator->id,
        ]);
        $item->tenant_id = $tenantId;
        $item->save();

        return $item;
    }
}
