<?php

namespace App\Domain\Operations\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's route and stop.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int $transport_route_id
 * @property int $transport_stop_id
 * @property bool $is_active
 */
#[Fillable(['student_id', 'transport_route_id', 'transport_stop_id', 'is_active'])]
class StudentTransportAssignment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
     * @return BelongsTo<TransportRoute, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    /**
     * @return BelongsTo<TransportStop, $this>
     */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(TransportStop::class, 'transport_stop_id');
    }
}
