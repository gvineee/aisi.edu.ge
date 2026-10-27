<?php

namespace App\Domain\Communications\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One question: a single or multiple choice among `options`, a 1–5 scale,
 * or free text.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $survey_id
 * @property int $position
 * @property string $type
 * @property string $prompt
 * @property list<string>|null $options
 * @property bool $is_required
 */
#[Fillable(['survey_id', 'position', 'type', 'prompt', 'options', 'is_required'])]
class SurveyQuestion extends Model
{
    use BelongsToTenant;

    public const TYPES = ['single', 'multiple', 'scale', 'text'];

    protected function casts(): array
    {
        return ['options' => 'array', 'is_required' => 'boolean'];
    }
}
