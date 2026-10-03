<?php

namespace App\Domain\Timetable\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The absent teacher's (or a coordinator's) plan for one covered lesson
 * occurrence. Readable only by the people on that assignment and the
 * coverage coordinators; the substitute sees it on the covered day only.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $substitution_assignment_id
 * @property int|null $author_id
 * @property string $plan
 * @property string|null $materials
 * @property string|null $notes
 */
#[Fillable(['substitution_assignment_id', 'author_id', 'plan', 'materials', 'notes'])]
class LessonHandoff extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<SubstitutionAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SubstitutionAssignment::class, 'substitution_assignment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
