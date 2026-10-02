<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\Student;
use App\Domain\Operations\Actions\ManageMeals;
use App\Domain\Operations\Models\MealAllergen;
use App\Domain\Operations\Models\MealItem;
use App\Domain\Operations\Models\MealMenu;
use App\Domain\Operations\Models\MealSelection;
use App\Domain\Operations\Models\StudentDietaryProfile;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesOperationsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "კვება" (CLAUDE-PLATFORM-MODULES §11). Families see only published menu
 * versions, allergen warnings for their own child and the opt-out; the
 * kitchen view sees allergen codes per student, never health details.
 */
class MealController extends Controller
{
    use ResolvesOperationsAccess;

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $isManagement = $this->isManagement($tenant->id, $user);
        $children = $this->ownChildren($tenant->id, $user);
        abort_unless($isManagement || $children->isNotEmpty(), 403);

        $weekStart = $request->filled('week')
            ? Carbon::parse((string) $request->string('week'), 'Asia/Tbilisi')->startOfWeek()
            : Carbon::now('Asia/Tbilisi')->startOfWeek();
        $days = collect(range(0, 4))->map(fn (int $offset) => $weekStart->copy()->addDays($offset)->toDateString());

        $menus = MealMenu::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('served_on', '>=', $days->first())->whereDate('served_on', '<=', $days->last())
            ->whereIn('status', $isManagement ? [MealMenu::STATUS_PUBLISHED, MealMenu::STATUS_DRAFT] : [MealMenu::STATUS_PUBLISHED])
            ->with('items.allergens')
            ->get();

        $menuShape = fn (?MealMenu $menu) => $menu === null ? null : [
            'id' => $menu->id,
            'version' => $menu->version,
            'status' => $menu->status,
            'items' => $menu->items->sortBy('sort_order')->map(fn (MealItem $item) => [
                'course' => $item->course,
                'name' => $item->name,
                'allergens' => $item->allergens->pluck('allergen')->values()->all(),
            ])->values()->all(),
        ];

        $profiles = StudentDietaryProfile::query()->where('tenant_id', $tenant->id)->whereIn('student_id', $children->pluck('id'))->get()->keyBy('student_id');
        $optOuts = MealSelection::query()->where('tenant_id', $tenant->id)->whereIn('student_id', $children->pluck('id'))->whereDate('served_on', '>=', $days->first())->whereDate('served_on', '<=', $days->last())->get();

        $kitchen = null;

        if ($isManagement) {
            $activeStudents = Student::query()->where('tenant_id', $tenant->id)->where('is_active', true)->count();
            $kitchenProfiles = StudentDietaryProfile::query()->where('tenant_id', $tenant->id)
                ->whereHas('student', fn ($query) => $query->where('is_active', true))->with('student.schoolClass')->get()
                ->filter(fn (StudentDietaryProfile $profile) => $profile->allergens !== []);

            $kitchen = [
                'days' => $days->map(fn (string $day) => [
                    'date' => $day,
                    'expected' => $activeStudents - MealSelection::query()->where('tenant_id', $tenant->id)->whereDate('served_on', $day)->count(),
                ])->values(),
                'allergenList' => $kitchenProfiles->map(fn (StudentDietaryProfile $profile) => [
                    'studentName' => $profile->student->fullName(),
                    'className' => $profile->student->schoolClass?->name,
                    'allergens' => $profile->allergens,
                ])->sortBy('studentName')->values(),
            ];
        }

        return Inertia::render('portal/meals/index', [
            'isManagement' => $isManagement,
            'weekStart' => $weekStart->toDateString(),
            'allergenCodes' => MealAllergen::CODES,
            'cutoffHour' => ManageMeals::OPT_OUT_CUTOFF_HOUR,
            'days' => $days->map(function (string $day) use ($menus, $menuShape, $isManagement) {
                $forDay = $menus->filter(fn (MealMenu $menu) => $menu->served_on->toDateString() === $day);

                return [
                    'date' => $day,
                    'published' => $menuShape($forDay->firstWhere('status', MealMenu::STATUS_PUBLISHED)),
                    'draft' => $isManagement ? $menuShape($forDay->firstWhere('status', MealMenu::STATUS_DRAFT)) : null,
                ];
            })->values(),
            'children' => $children->map(fn (Student $child) => [
                'id' => $child->id,
                'name' => $child->fullName(),
                'allergens' => $profiles->get($child->id)->allergens ?? [],
                'optedOutDays' => $optOuts->where('student_id', $child->id)->map(fn (MealSelection $s) => $s->served_on->toDateString())->values()->all(),
            ])->values(),
            'kitchen' => $kitchen,
        ]);
    }

    public function saveDraft(Request $request, CurrentTenant $currentTenant, ManageMeals $meals): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($this->isManagement($tenant->id, $request->user()), 403);
        $data = $request->validate([
            'served_on' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.course' => ['required', 'string', 'max:32'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.allergens' => ['array'],
            'items.*.allergens.*' => ['string'],
        ]);

        /** @var array<int, array{course: string, name: string, allergens?: array<int, string>}> $items */
        $items = $data['items'];
        $meals->saveDraft($tenant->id, Carbon::parse($data['served_on']), $items, $request->user());

        return back();
    }

    public function publish(Request $request, CurrentTenant $currentTenant, MealMenu $menu, ManageMeals $meals): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($menu->tenant_id === $tenant->id, 404);
        abort_unless($this->isManagement($tenant->id, $request->user()), 403);

        $meals->publish($menu, $request->user());

        return back();
    }

    public function saveDietary(Request $request, CurrentTenant $currentTenant, Student $student, ManageMeals $meals): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        $user = $request->user();
        abort_unless($this->ownChild($tenant->id, $user, $student->id) !== null || $this->isManagement($tenant->id, $user), 403);
        $request->validate(['allergens' => ['array'], 'allergens.*' => ['string']]);

        /** @var array<int, string> $allergens */
        $allergens = $request->array('allergens');
        $meals->saveDietaryProfile($student, $allergens, $user);

        return back();
    }

    public function optOut(Request $request, CurrentTenant $currentTenant, Student $student, ManageMeals $meals): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        abort_unless($this->ownChild($tenant->id, $request->user(), $student->id) !== null, 403);
        $request->validate(['served_on' => ['required', 'date'], 'opt_out' => ['required', 'boolean']]);

        $meals->setOptOut($student, Carbon::parse((string) $request->string('served_on'), 'Asia/Tbilisi'), $request->boolean('opt_out'), $request->user());

        return back();
    }
}
