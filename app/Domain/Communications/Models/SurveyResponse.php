<?php

namespace App\Domain\Communications\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's answers. `respondent_hash` keeps them to one response;
 * `user_id` is set only when the survey is not anonymous.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $survey_id
 * @property string $respondent_hash
 * @property int|null $user_id
 * @property string $role
 */
#[Fillable(['survey_id', 'respondent_hash', 'user_id', 'role'])]
class SurveyResponse extends Model
{
    use BelongsToTenant;

    /**
     * @return HasMany<SurveyAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class);
    }
}
