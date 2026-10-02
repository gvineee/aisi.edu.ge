<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A label for grouping assessments (e.g. "შემაჯამებელი"). Deliberately has
 * no weight: weighting is part of the school's unapproved grading policy,
 * so averages shown today are unweighted and labelled as informational.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 */
#[Fillable(['name'])]
class AssessmentCategory extends Model
{
    use BelongsToTenant;
}
