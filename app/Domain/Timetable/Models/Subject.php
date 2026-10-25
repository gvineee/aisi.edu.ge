<?php

namespace App\Domain\Timetable\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $short_name
 */
#[Fillable(['name', 'short_name'])]
class Subject extends Model
{
    use BelongsToTenant;
}
