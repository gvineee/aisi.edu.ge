<?php

namespace App\Domain\PickupConsent\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time pickup code. Only an HMAC of the code is stored; the code
 * carries no student id; it expires quickly and can be consumed once.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int|null $authorized_pickup_id
 * @property int $issued_by
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property int|null $consumed_by
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 */
#[Fillable(['student_id', 'authorized_pickup_id', 'issued_by', 'code_hash', 'expires_at', 'consumed_at', 'consumed_by', 'cancelled_at'])]
class PickupCode extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<AuthorizedPickup, $this>
     */
    public function authorizedPickup(): BelongsTo
    {
        return $this->belongsTo(AuthorizedPickup::class);
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', strtoupper(trim($code)), (string) config('app.key'));
    }
}
