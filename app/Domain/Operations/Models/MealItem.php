<?php

namespace App\Domain\Operations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dish on a menu.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $meal_menu_id
 * @property string $course
 * @property string $name
 * @property int $sort_order
 */
#[Fillable(['meal_menu_id', 'course', 'name', 'sort_order'])]
class MealItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<MealAllergen, $this>
     */
    public function allergens(): HasMany
    {
        return $this->hasMany(MealAllergen::class);
    }
}
