<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\Operations\Models\MealAllergen;
use App\Domain\Operations\Models\MealItem;
use App\Domain\Operations\Models\MealMenu;
use App\Domain\Operations\Models\MealSelection;
use App\Domain\Operations\Models\StudentDietaryProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menus are versioned: a published menu is never edited in place — a
 * change creates the next draft version, and publishing it supersedes the
 * previous one. Dietary profiles hold allergen codes only (the kitchen's
 * minimal projection, never health details). Opt-outs close at the
 * configured cutoff on the serving day.
 */
class ManageMeals
{
    public const OPT_OUT_CUTOFF_HOUR = 9;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<int, array{course: string, name: string, allergens?: array<int, string>}>  $items
     */
    public function saveDraft(int $tenantId, Carbon $servedOn, array $items, User $actor): MealMenu
    {
        foreach ($items as $index => $item) {
            foreach ($item['allergens'] ?? [] as $code) {
                if (! array_key_exists($code, MealAllergen::CODES)) {
                    throw ValidationException::withMessages(["items.{$index}.allergens" => 'უცნობი ალერგენი.']);
                }
            }
        }

        return DB::transaction(function () use ($tenantId, $servedOn, $items, $actor) {
            $menu = MealMenu::query()
                ->where('tenant_id', $tenantId)
                ->whereDate('served_on', $servedOn->toDateString())
                ->where('status', MealMenu::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();

            if ($menu === null) {
                $version = (int) MealMenu::query()->where('tenant_id', $tenantId)->whereDate('served_on', $servedOn->toDateString())->max('version') + 1;
                $menu = new MealMenu(['served_on' => $servedOn->toDateString(), 'version' => $version, 'status' => MealMenu::STATUS_DRAFT]);
                $menu->tenant_id = $tenantId;
                $menu->save();
            } else {
                $menu->items()->delete();
            }

            foreach (array_values($items) as $position => $item) {
                $mealItem = new MealItem(['meal_menu_id' => $menu->id, 'course' => $item['course'], 'name' => $item['name'], 'sort_order' => $position]);
                $mealItem->tenant_id = $tenantId;
                $mealItem->save();

                foreach (array_unique($item['allergens'] ?? []) as $code) {
                    $allergen = new MealAllergen(['meal_item_id' => $mealItem->id, 'allergen' => $code]);
                    $allergen->tenant_id = $tenantId;
                    $allergen->save();
                }
            }

            $this->auditLogger->record($tenantId, 'meal_menu.draft_saved', $menu, $actor->id, ['version' => $menu->version]);

            return $menu;
        });
    }

    public function publish(MealMenu $menu, User $actor): void
    {
        DB::transaction(function () use ($menu, $actor) {
            $locked = MealMenu::query()->where('tenant_id', $menu->tenant_id)->whereKey($menu->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== MealMenu::STATUS_DRAFT) {
                throw ValidationException::withMessages(['menu' => 'მხოლოდ დრაფტი ქვეყნდება.']);
            }

            if ($locked->items()->count() === 0) {
                throw ValidationException::withMessages(['menu' => 'ცარიელი მენიუ ვერ გამოქვეყნდება.']);
            }

            MealMenu::query()
                ->where('tenant_id', $locked->tenant_id)
                ->whereDate('served_on', $locked->served_on->toDateString())
                ->where('status', MealMenu::STATUS_PUBLISHED)
                ->update(['status' => MealMenu::STATUS_SUPERSEDED]);

            $locked->status = MealMenu::STATUS_PUBLISHED;
            $locked->published_at = Carbon::now();
            $locked->published_by = $actor->id;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'meal_menu.published', $locked, $actor->id, ['version' => $locked->version]);
        });

        $menu->refresh();
    }

    /**
     * @param  array<int, string>  $allergens
     */
    public function saveDietaryProfile(Student $student, array $allergens, User $actor): StudentDietaryProfile
    {
        $codes = array_values(array_unique(array_filter($allergens, fn (string $code) => array_key_exists($code, MealAllergen::CODES))));

        $profile = StudentDietaryProfile::query()->where('student_id', $student->id)->first() ?? new StudentDietaryProfile(['student_id' => $student->id]);
        $profile->tenant_id = $student->tenant_id;
        $profile->allergens = $codes;
        $profile->updated_by = $actor->id;
        $profile->save();

        $this->auditLogger->record($student->tenant_id, 'dietary_profile.updated', $profile, $actor->id, ['student_id' => $student->id, 'count' => count($codes)]);

        return $profile;
    }

    public function setOptOut(Student $student, Carbon $servedOn, bool $optOut, User $guardian): void
    {
        $cutoff = Carbon::parse($servedOn->toDateString().' '.str_pad((string) self::OPT_OUT_CUTOFF_HOUR, 2, '0', STR_PAD_LEFT).':00', 'Asia/Tbilisi');

        if (Carbon::now()->gte($cutoff)) {
            throw ValidationException::withMessages(['served_on' => 'ამ დღის კვებაზე ცვლილების ვადა ამოიწურა.']);
        }

        $existing = MealSelection::query()->where('student_id', $student->id)->whereDate('served_on', $servedOn->toDateString())->first();

        if ($optOut && $existing === null) {
            $selection = new MealSelection(['student_id' => $student->id, 'served_on' => $servedOn->toDateString(), 'choice' => 'opted_out', 'selected_by' => $guardian->id]);
            $selection->tenant_id = $student->tenant_id;
            $selection->save();
        } elseif (! $optOut && $existing !== null) {
            $existing->delete();
        }
    }
}
