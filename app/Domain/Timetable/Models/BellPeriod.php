<?php

namespace App\Domain\Timetable\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The school's bell schedule for a year: period number → wall-clock times
 * in Asia/Tbilisi.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $academic_year_id
 * @property int $number
 * @property string $starts_at
 * @property string $ends_at
 */
#[Fillable(['academic_year_id', 'number', 'starts_at', 'ends_at'])]
class BellPeriod extends Model
{
    use BelongsToTenant;
}
