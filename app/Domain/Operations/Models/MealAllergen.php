<?php

namespace App\Domain\Operations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An allergen tag on a dish (code from MealAllergen::CODES).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $meal_item_id
 * @property string $allergen
 */
#[Fillable(['meal_item_id', 'allergen'])]
class MealAllergen extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * The 14 allergen groups of EU Regulation 1169/2011, as stable codes.
     *
     * @var array<string, string>
     */
    public const CODES = [
        'gluten' => 'გლუტენი',
        'crustaceans' => 'კიბოსნაირები',
        'eggs' => 'კვერცხი',
        'fish' => 'თევზი',
        'peanuts' => 'მიწის თხილი (არაქისი)',
        'soy' => 'სოია',
        'milk' => 'რძე',
        'nuts' => 'კაკალი/თხილი',
        'celery' => 'ნიახური',
        'mustard' => 'მდოგვი',
        'sesame' => 'სეზამი',
        'sulphites' => 'სულფიტები',
        'lupin' => 'ლუპინი',
        'molluscs' => 'მოლუსკები',
    ];
}
