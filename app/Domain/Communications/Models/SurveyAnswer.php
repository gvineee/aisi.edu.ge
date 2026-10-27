<?php

namespace App\Domain\Communications\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The answer to one question: option indexes, a scale value or text.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $survey_response_id
 * @property int $survey_question_id
 * @property list<int>|int|string $value
 */
#[Fillable(['survey_response_id', 'survey_question_id', 'value'])]
class SurveyAnswer extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
