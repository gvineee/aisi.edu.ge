<?php

namespace App\Domain\Admissions\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One family's place in a visit slot. Cancelled bookings are kept.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $admission_visit_slot_id
 * @property int $admission_lead_id
 * @property string $status
 * @property string|null $notify_email
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property Carbon|null $created_at
 * @property-read AdmissionVisitSlot $slot
 * @property-read AdmissionLead $lead
 */
#[Fillable(['admission_visit_slot_id', 'admission_lead_id', 'status', 'notify_email', 'reminder_sent_at', 'cancelled_at', 'cancelled_by', 'cancel_reason'])]
class AdmissionVisitBooking extends Model
{
    use BelongsToTenant;

    public const STATUS_BOOKED = 'booked';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'reminder_sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AdmissionVisitSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(AdmissionVisitSlot::class, 'admission_visit_slot_id');
    }

    /**
     * @return BelongsTo<AdmissionLead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(AdmissionLead::class, 'admission_lead_id');
    }
}
