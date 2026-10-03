<?php

namespace App\Domain\Communications\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $appointment_slot_id
 * @property int $student_id
 * @property int $guardian_id
 * @property string|null $note
 * @property string $status
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $reminder_sent_at
 */
#[Fillable(['appointment_slot_id', 'student_id', 'guardian_id', 'note', 'status', 'cancelled_at'])]
class AppointmentBooking extends Model
{
    use BelongsToTenant;

    public const STATUS_BOOKED = 'booked';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AppointmentSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'appointment_slot_id');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_id');
    }
}
