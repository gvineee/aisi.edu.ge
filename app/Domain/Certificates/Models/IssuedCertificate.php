<?php

namespace App\Domain\Certificates\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A document as issued: number `year/sequence`, the fixed text it was
 * issued with (`snapshot`), and its verification code. Never edited — a
 * mistake is revoked and issued again.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $certificate_template_id
 * @property int|null $student_id
 * @property int $year
 * @property int $sequence
 * @property string $verification_code
 * @property array{kind: string, title: string, body: string, signatory: string|null, orientation: string, schoolName: string, recipient: string|null, className: string|null, number: string, issuedOn: string} $snapshot
 * @property int|null $issued_by
 * @property Carbon $issued_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $revoke_reason
 * @property-read Student|null $student
 */
#[Fillable(['certificate_template_id', 'student_id', 'year', 'sequence', 'verification_code', 'snapshot', 'issued_by', 'issued_at', 'revoked_at', 'revoked_by', 'revoke_reason'])]
class IssuedCertificate extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'issued_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function number(): string
    {
        return sprintf('%d/%04d', $this->year, $this->sequence);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
