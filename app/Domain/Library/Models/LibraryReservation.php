<?php

namespace App\Domain\Library\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A student's place in the queue for a title. When a copy comes back it is
 * held for the first waiting reservation until ready_until. open_key is
 * set while waiting/ready (unique), so a student holds one open
 * reservation per title.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $library_resource_id
 * @property int $student_id
 * @property int $requested_by
 * @property string $status
 * @property string|null $open_key
 * @property int|null $library_copy_id
 * @property Carbon|null $ready_until
 * @property Carbon|null $created_at
 * @property-read LibraryResource $resource
 * @property-read Student $student
 * @property-read LibraryCopy|null $copy
 */
#[Fillable(['library_resource_id', 'student_id', 'requested_by', 'status', 'open_key', 'library_copy_id', 'ready_until'])]
class LibraryReservation extends Model
{
    use BelongsToTenant;

    public const STATUS_WAITING = 'waiting';

    public const STATUS_READY = 'ready';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /** Days a returned copy is held for the reservation. */
    public const HOLD_DAYS = 3;

    protected function casts(): array
    {
        return [
            'ready_until' => 'datetime',
        ];
    }

    public static function openKey(int $tenantId, int $resourceId, int $studentId): string
    {
        return "{$tenantId}:{$resourceId}:{$studentId}";
    }

    /**
     * @return BelongsTo<LibraryResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LibraryResource::class, 'library_resource_id');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<LibraryCopy, $this>
     */
    public function copy(): BelongsTo
    {
        return $this->belongsTo(LibraryCopy::class, 'library_copy_id');
    }
}
