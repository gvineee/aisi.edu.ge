<?php

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Models\GradeScale;
use App\Domain\Assessment\Models\GradeScaleBand;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the next version of the school's grade scale and makes it the
 * active one. Older versions stay attached to the assessments graded under
 * them; new assessments pick up this version.
 */
class CreateGradeScale
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<int, array{min_percent: string|int|float, label: string}>  $bands
     */
    public function handle(int $tenantId, User $actor, string $name, array $bands): GradeScale
    {
        $normalized = [];

        foreach ($bands as $index => $band) {
            $min = ScoreCalculator::toHundredths($band['min_percent']);
            $label = trim($band['label']);

            if ($min < 0 || $min > 10000 || $label === '') {
                throw ValidationException::withMessages(["bands.{$index}.min_percent" => 'ზღვარი 0-დან 100-მდე უნდა იყოს და დასახელება სავალდებულოა.']);
            }

            if (isset($normalized[$min])) {
                throw ValidationException::withMessages(["bands.{$index}.min_percent" => 'ორ შეფასებას ერთნაირი ზღვარი აქვს.']);
            }

            $normalized[$min] = $label;
        }

        if (! isset($normalized[0])) {
            throw ValidationException::withMessages(['bands' => 'სკალას უნდა ჰქონდეს 0%-დან დაწყებული შეფასება, რომ ყველა ქულა დაიფაროს.']);
        }

        return DB::transaction(function () use ($tenantId, $actor, $name, $normalized) {
            // No lock on an aggregate (PostgreSQL rejects FOR UPDATE with
            // MAX); the unique (tenant_id, version) index rejects a racing
            // duplicate instead.
            $version = (int) GradeScale::query()->where('tenant_id', $tenantId)->max('version') + 1;

            GradeScale::query()->where('tenant_id', $tenantId)->where('is_active', true)->update(['is_active' => false]);

            $scale = new GradeScale([
                'name' => $name,
                'version' => $version,
                'is_active' => true,
                'created_by' => $actor->id,
            ]);
            $scale->tenant_id = $tenantId;
            $scale->save();

            foreach ($normalized as $min => $label) {
                $band = new GradeScaleBand([
                    'grade_scale_id' => $scale->id,
                    'min_percent' => ScoreCalculator::fromHundredths($min),
                    'label' => $label,
                ]);
                $band->tenant_id = $tenantId;
                $band->save();
            }

            $this->auditLogger->record($tenantId, 'grade_scale.created', $scale, $actor->id, [
                'version' => $version,
                'bands' => count($normalized),
            ]);

            return $scale;
        });
    }
}
