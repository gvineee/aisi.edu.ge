<?php

namespace App\Domain\Portfolio\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Timetable\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A student's own piece of work (CLAUDE-PLATFORM-MODULES.md §5). Default
 * private — visible to the owning student always, to their teacher once
 * submitted, and to guardians only once published. Public sharing is not
 * implemented in this pass (spec explicitly defaults it off; `visibility`
 * stays `'private'` for every row until that feature exists).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int|null $subject_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $visibility
 * @property int $created_by
 * @property Carbon|null $published_at
 */
#[Fillable(['student_id', 'subject_id', 'title', 'description', 'status', 'visibility', 'created_by', 'published_at'])]
class PortfolioItem extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Statuses the owning student may still edit/add assets to.
     *
     * @var array<int, string>
     */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_RETURNED];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PortfolioAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(PortfolioAsset::class);
    }

    /**
     * @return HasMany<PortfolioFeedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(PortfolioFeedback::class)->orderBy('created_at');
    }

    public function isEditableByStudent(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }
}
